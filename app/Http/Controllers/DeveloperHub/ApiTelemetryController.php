<?php

namespace App\Http\Controllers\DeveloperHub;

use App\Http\Controllers\Controller;
use App\Repositories\DeveloperHub\TelemetryRepository;
use App\Services\DeveloperHub\TelemetryIngestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class ApiTelemetryController extends Controller
{
    protected TelemetryRepository $repository;
    protected TelemetryIngestService $ingestService;

    public function __construct(TelemetryRepository $repository, TelemetryIngestService $ingestService)
    {
        $this->repository = $repository;
        $this->ingestService = $ingestService;
    }

    /**
     * Tampilkan halaman utama API Telemetry Dashboard.
     */
    public function index(Request $request)
    {
        // Ambil data raw terbaru untuk sidebar stream
        $filters = [
            'endpoint' => $request->input('endpoint'),
            'status_code' => $request->input('status_code'),
            'method' => $request->input('method'),
        ];
        
        $requests = $this->repository->getRawRequests($filters, 50);
        $queueLength = $this->ingestService->getQueueLength();

        return view('superadmin.developer_hub.api_telemetry', compact('requests', 'queueLength'));
    }

    /**
     * JSON overview metrics untuk grafik Chart.js.
     */
    public function getOverview(Request $request)
    {
        $range = $request->input('range', '1h');
        $end = Carbon::now();
        
        switch ($range) {
            case '6h':
                $start = Carbon::now()->subHours(6);
                break;
            case '24h':
                $start = Carbon::now()->subHours(24);
                break;
            case '7d':
                $start = Carbon::now()->subDays(7);
                break;
            case '1h':
            default:
                $start = Carbon::now()->subHour();
                break;
        }

        $aggregates = $this->repository->getOverview($start->toDateTimeString(), $end->toDateTimeString());
        $topEndpoints = $this->repository->getTopEndpoints($start->toDateTimeString(), $end->toDateTimeString(), 5);

        // Hitung KPIs
        $totalRequests = array_sum(array_column($aggregates, 'total_requests'));
        $totalErrors = array_sum(array_column($aggregates, 'error_count'));
        $errorRate = $totalRequests > 0 ? round(($totalErrors / $totalRequests) * 100, 2) : 0;
        
        $avgP99 = !empty($aggregates) ? round(array_sum(array_column($aggregates, 'p99_ms')) / count($aggregates)) : 0;
        $avgP50 = !empty($aggregates) ? round(array_sum(array_column($aggregates, 'p50_ms')) / count($aggregates)) : 0;
        
        $durationSeconds = $end->diffInSeconds($start);
        $currentRps = $durationSeconds > 0 ? round($totalRequests / $durationSeconds, 2) : 0;

        return response()->json([
            'status' => 'success',
            'data' => [
                'current_rps' => $currentRps,
                'error_rate_pct' => $errorRate,
                'avg_p50_ms' => $avgP50,
                'avg_p99_ms' => $avgP99,
                'series' => $aggregates,
                'top_endpoints' => $topEndpoints,
            ]
        ]);
    }

    /**
     * Dapatkan detail JSON untuk satu request ID.
     */
    public function getRequestDetail(string $requestId)
    {
        $request = $this->repository->getRequestDetail($requestId);
        if (!$request) {
            return response()->json([
                'status' => 'error',
                'message' => 'Request ID tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $request
        ]);
    }

    /**
     * API Replay: Kirim ulang request gagal/lambat.
     */
    public function replayRequest(string $requestId)
    {
        $rawRequest = $this->repository->getRequestDetail($requestId);
        if (!$rawRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Request ID tidak ditemukan.'
            ], 404);
        }

        $appUrl = config('app.url');
        $targetUrl = rtrim($appUrl, '/') . '/' . ltrim($rawRequest->endpoint, '/');
        $method = strtoupper($rawRequest->method);
        
        // Buat body payload & header tiruan
        $headers = [];
        if (is_array($rawRequest->request_headers)) {
            foreach ($rawRequest->request_headers as $key => $values) {
                if (strtolower($key) !== 'host' && strtolower($key) !== 'authorization') {
                    $headers[$key] = is_array($values) ? implode(', ', $values) : $values;
                }
            }
        }
        $headers['X-Replayed-From'] = $rawRequest->request_id;
        
        $payload = is_array($rawRequest->request_payload) ? $rawRequest->request_payload : (json_decode($rawRequest->request_payload, true) ?: []);

        $startTime = microtime(true);
        try {
            $response = Http::withHeaders($headers)
                ->send($method, $targetUrl, [
                    'json' => $payload
                ]);
            
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'replayed_at' => now()->toDateTimeString(),
                    'original_status' => $rawRequest->status_code,
                    'new_status' => $response->status(),
                    'new_latency_ms' => $latencyMs,
                    'new_response' => $response->json() ?: $response->body()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengeksekusi replay request: ' . $e->getMessage()
            ], 500);
        }
    }
}
