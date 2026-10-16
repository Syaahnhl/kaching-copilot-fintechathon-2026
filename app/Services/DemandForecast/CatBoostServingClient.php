<?php

namespace App\Services\DemandForecast;

use App\Services\DemandForecast\Exceptions\CatBoostClientException;
use App\Services\DemandForecast\Exceptions\CatBoostConnectionException;
use App\Services\DemandForecast\Exceptions\CatBoostServerException;
use App\Services\DemandForecast\Exceptions\CatBoostServiceUnavailableException;
use App\Services\DemandForecast\Exceptions\CatBoostTimeoutException;
use App\Services\DemandForecast\Exceptions\CatBoostValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CatBoostServingClient
{
    protected string $baseUrl;
    protected int $connectTimeout;
    protected int $timeout;
    protected int $maxRetries;

    public function __construct(
        ?string $baseUrl = null,
        ?int $connectTimeout = null,
        ?int $timeout = null,
        ?int $maxRetries = null
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.catboost_serving.base_url', 'http://127.0.0.1:8000'), '/');
        $this->connectTimeout = $connectTimeout ?? (int) config('services.catboost_serving.connect_timeout', 2);
        $this->timeout = $timeout ?? (int) config('services.catboost_serving.timeout', 5);
        $this->maxRetries = $maxRetries ?? (int) config('services.catboost_serving.max_retries', 1);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.catboost_serving.enabled', true);
    }

    /**
     * Check health status of the serving daemon.
     *
     * @return array
     * @throws CatBoostClientException
     */
    public function health(): array
    {
        if (! $this->isEnabled()) {
            throw new CatBoostServiceUnavailableException('CatBoost serving client is disabled via configuration (CATBOOST_SERVING_ENABLED=false).', 503);
        }

        $url = "{$this->baseUrl}/health";

        try {
            $response = Http::connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($url);

            if ($response->status() === 503) {
                throw new CatBoostServiceUnavailableException('FastAPI CatBoost daemon model is not loaded or unavailable (HTTP 503).', 503);
            }

            if (!$response->successful()) {
                throw new CatBoostServerException("Health check failed with HTTP {$response->status()}", $response->status());
            }

            $data = $response->json();
            if (!is_array($data) || empty($data['status'])) {
                throw new CatBoostValidationException('Malformed health check response payload.');
            }

            return $data;
        } catch (CatBoostClientException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            Log::warning('[CatBoostServingClient] Health check connection failed', [
                'endpoint' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new CatBoostConnectionException('Unable to connect to FastAPI CatBoost daemon: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            Log::warning('[CatBoostServingClient] Health check unexpected error', [
                'endpoint' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new CatBoostClientException('Unexpected error during CatBoost health check: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Readiness predicate.
     *
     * @return bool
     */
    public function isReady(): bool
    {
        try {
            $health = $this->health();
            return ($health['status'] ?? '') === 'healthy' && ($health['model_loaded'] ?? false) === true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Format and sanitize batch inference payload strictly conforming to FastAPI daemon schema.
     * Strips orchestration/cache metadata (e.g. cutoff_date, target_date, history_status) from items.
     *
     * @param int $outletId
     * @param string $targetDate
     * @param array $items
     * @return array
     * @throws CatBoostValidationException
     */
    public function formatBatchPayload(int $outletId, string $targetDate, array $items): array
    {
        if ($outletId <= 0) {
            throw new CatBoostValidationException("Invalid outlet_id: {$outletId}. Must be positive integer.");
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            throw new CatBoostValidationException("Invalid target_date format: '{$targetDate}'. Expected YYYY-MM-DD.");
        }

        $itemCount = count($items);
        if ($itemCount === 0) {
            throw new CatBoostValidationException('Prediction items array cannot be empty.');
        }

        if ($itemCount > 100) {
            throw new CatBoostValidationException("Batch size {$itemCount} exceeds maximum allowed limit of 100 items.");
        }

        $sanitizedItems = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                throw new CatBoostValidationException("Item at index {$idx} must be an array.");
            }

            if (!isset($item['menu_id']) || !isset($item['features']) || !is_array($item['features'])) {
                throw new CatBoostValidationException("Item at index {$idx} missing required menu_id or features array.");
            }

            $menuId = (int) $item['menu_id'];
            if ($menuId <= 0) {
                throw new CatBoostValidationException("Item at index {$idx} has invalid menu_id: {$item['menu_id']}.");
            }

            $menuName = trim((string) ($item['menu_name'] ?? ''));
            if ($menuName === '') {
                $menuName = "Menu-{$menuId}";
            }

            $category = trim((string) ($item['category'] ?? ''));
            if ($category === '') {
                $category = 'Default';
            }

            $uom = trim((string) ($item['uom'] ?? ''));
            if ($uom === '') {
                $uom = 'porsi';
            }

            $historyDays = isset($item['history_days_available']) ? max(0, (int) $item['history_days_available']) : 28;

            $sanitizedItems[] = [
                'menu_id' => $menuId,
                'menu_name' => $menuName,
                'category' => $category,
                'uom' => $uom,
                'history_days_available' => $historyDays,
                'features' => $item['features'],
            ];
        }

        return [
            'outlet_id' => (int) $outletId,
            'target_date' => (string) $targetDate,
            'items' => $sanitizedItems,
        ];
    }

    /**
     * Send batch inference request to the serving daemon.
     *
     * @param int $outletId
     * @param string $targetDate Format YYYY-MM-DD
     * @param array $items Array of items with features
     * @return array
     * @throws CatBoostClientException
     */
    public function predict(int $outletId, string $targetDate, array $items): array
    {
        if (! $this->isEnabled()) {
            throw new CatBoostServiceUnavailableException('CatBoost serving client is disabled via configuration (CATBOOST_SERVING_ENABLED=false).', 503);
        }

        $payload = $this->formatBatchPayload($outletId, $targetDate, $items);
        $itemCount = count($payload['items']);

        $url = "{$this->baseUrl}/predict";

        try {
            $httpClient = Http::connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson();

            if ($this->maxRetries > 0) {
                $httpClient = $httpClient->retry($this->maxRetries, 100, function ($exception) {
                    return $exception instanceof ConnectionException;
                }, false);
            }

            $response = $httpClient->post($url, $payload);

            $status = $response->status();

            if ($status === 200) {
                $body = $response->json();
                return $this->validateAndParseResponse($body, $items);
            }

            if ($status === 422) {
                $errorData = $response->json() ?? [];
                $detail = $errorData['detail'] ?? $errorData['error']['message'] ?? 'Validation failed on FastAPI server';
                $msg = is_array($detail) ? json_encode($detail) : (string) $detail;
                Log::warning('[CatBoostServingClient] HTTP 422 validation error', [
                    'outlet_id' => $outletId,
                    'items_count' => $itemCount,
                    'detail' => $msg,
                ]);
                throw new CatBoostValidationException("FastAPI validation error (422): {$msg}", 422, null, is_array($detail) ? $detail : [$msg]);
            }

            if ($status === 503) {
                Log::warning('[CatBoostServingClient] HTTP 503 daemon unavailable', [
                    'outlet_id' => $outletId,
                    'target_date' => $targetDate,
                ]);
                throw new CatBoostServiceUnavailableException('FastAPI CatBoost daemon model is not loaded (HTTP 503).', 503);
            }

            if ($status >= 500) {
                Log::error('[CatBoostServingClient] HTTP 500 server error', [
                    'outlet_id' => $outletId,
                    'status' => $status,
                ]);
                throw new CatBoostServerException("FastAPI server error (HTTP {$status}).", $status);
            }

            throw new CatBoostClientException("FastAPI returned unexpected HTTP status {$status}.", $status);

        } catch (CatBoostClientException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            $isTimeout = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout');
            Log::warning('[CatBoostServingClient] HTTP connection/timeout error', [
                'endpoint' => $url,
                'is_timeout' => $isTimeout,
                'error' => $e->getMessage(),
            ]);

            if ($isTimeout) {
                throw new CatBoostTimeoutException("CatBoost serving request timed out: {$e->getMessage()}", 0, $e);
            }

            throw new CatBoostConnectionException("Failed to connect to CatBoost serving daemon: {$e->getMessage()}", 0, $e);
        } catch (Throwable $e) {
            Log::error('[CatBoostServingClient] Unexpected inference request failure', [
                'endpoint' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new CatBoostClientException("CatBoost inference client failure: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Validate and extract forecasts from raw response payload.
     *
     * @param mixed $body
     * @param array $requestedItems
     * @return array
     * @throws CatBoostValidationException
     */
    protected function validateAndParseResponse(mixed $body, array $requestedItems): array
    {
        if (!is_array($body) || !isset($body['success']) || $body['success'] !== true || !isset($body['data']['forecasts'])) {
            throw new CatBoostValidationException('Malformed response: missing success flag or data.forecasts payload.');
        }

        $forecasts = $body['data']['forecasts'];
        if (!is_array($forecasts)) {
            throw new CatBoostValidationException('Malformed response: data.forecasts is not an array.');
        }

        $forecastMap = [];
        foreach ($forecasts as $idx => $f) {
            if (!is_array($f) || !isset($f['menu_id']) || !isset($f['predicted_quantity'])) {
                throw new CatBoostValidationException("Malformed forecast entry at index {$idx}: missing menu_id or predicted_quantity.");
            }

            $predQty = $f['predicted_quantity'];
            if (!is_numeric($predQty) || is_nan((float) $predQty) || is_infinite((float) $predQty)) {
                throw new CatBoostValidationException("Non-finite or non-numeric predicted_quantity for menu_id '{$f['menu_id']}'.");
            }

            $forecastMap[(string) $f['menu_id']] = $f;
        }

        // Verify that all requested items are accounted for
        foreach ($requestedItems as $req) {
            $reqMenuId = (string) ($req['menu_id'] ?? '');
            if ($reqMenuId !== '' && !isset($forecastMap[$reqMenuId])) {
                throw new CatBoostValidationException("Mismatched response: requested menu_id '{$reqMenuId}' missing from forecasts.");
            }
        }

        return $body;
    }
}
