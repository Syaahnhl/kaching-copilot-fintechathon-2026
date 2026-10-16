<?php

namespace App\Http\Middleware\DeveloperHub;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\DeveloperHub\TelemetryIngestService;

class TelemetryCaptureMiddleware
{
    protected TelemetryIngestService $ingestService;
    protected string $requestId;
    protected float $startTime;

    public function __construct(TelemetryIngestService $ingestService)
    {
        $this->ingestService = $ingestService;
        $this->startTime = microtime(true);
        $this->requestId = (string) Str::uuid();
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Inject request ID ke headers response agar klien/developer bisa melacak request di log
        $request->headers->set('X-Request-ID', $this->requestId);
        
        $response = $next($request);
        
        $response->headers->set('X-Request-ID', $this->requestId);
        return $response;
    }

    /**
     * Perform any work after the response has been sent.
     */
    public function terminate(Request $request, $response): void
    {
        // Hindari logging akses halaman developer-hub itu sendiri untuk menghindari loops
        if ($request->is('superadmin/developer-hub*') || $request->is('api/developer-hub*')) {
            return;
        }

        // Hitung latency
        $laravelStart = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');
        $latencyMs = (int) round((microtime(true) - ($laravelStart ?: $this->startTime)) * 1000);

        $statusCode = $response->getStatusCode();
        
        // Filter headers sensitif
        $reqHeaders = $this->filterHeaders($request->headers->all());
        $resHeaders = $this->filterHeaders($response->headers->all());

        // Kondisi penyimpanan payload: lambat (> 300ms) atau error (>= 400)
        $shouldLogPayload = ($statusCode >= 400 || $latencyMs > 300);

        $payloadData = [
            'request_id' => $this->requestId,
            'user_id' => $request->user() ? $request->user()->id : null,
            'outlet_id' => $request->user() && isset($request->user()->outlet_id) ? $request->user()->outlet_id : null,
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'status_code' => $statusCode,
            'latency_ms' => $latencyMs,
            'client_ip' => $request->ip() ?: '0.0.0.0',
            'user_agent' => substr($request->userAgent() ?: '', 0, 500),
            'request_headers' => $reqHeaders,
            'response_headers' => $resHeaders,
            'request_payload' => $shouldLogPayload ? $this->sanitizePayload($request->all()) : null,
            'response_payload' => $shouldLogPayload ? $this->sanitizePayload($this->getResponsePayload($response)) : null,
            'created_at' => now()->toDateTimeString(),
        ];

        // Masukkan ke buffer Redis secara non-blocking
        $this->ingestService->pushToBuffer($payloadData);
    }

    /**
     * Bersihkan data kredensial dari header log.
     */
    protected function filterHeaders(array $headers): array
    {
        $sensitiveKeys = ['authorization', 'cookie', 'x-csrf-token', 'php-auth-pw', 'password'];
        $cleanHeaders = [];
        foreach ($headers as $key => $values) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveKeys)) {
                $cleanHeaders[$key] = ['[REDRACTED_SECURITY_GATE]'];
            } else {
                $cleanHeaders[$key] = $values;
            }
        }
        return $cleanHeaders;
    }

    /**
     * Sensor field password/pin sensitif dari data input/payload.
     */
    protected function sanitizePayload($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }

        $sensitiveKeys = ['password', 'pin', 'token', 'password_confirmation', 'key', 'secret'];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sanitizePayload($value);
            } elseif (in_array(strtolower($key), $sensitiveKeys)) {
                $payload[$key] = '[REDRACTED_SECURITY_GATE]';
            }
        }
        return $payload;
    }

    /**
     * Dapatkan payload respon string secara aman.
     */
    protected function getResponsePayload($response)
    {
        if (method_exists($response, 'getContent')) {
            $content = $response->getContent();
            $decoded = json_decode($content, true);
            return $decoded ?: substr($content, 0, 2000);
        }
        return null;
    }
}
