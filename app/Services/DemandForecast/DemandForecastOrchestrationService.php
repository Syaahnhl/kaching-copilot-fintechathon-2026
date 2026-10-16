<?php

namespace App\Services\DemandForecast;

use App\Models\Menu;
use App\Models\MenuDemandForecast;
use App\Models\Outlet;
use App\Models\User;
use App\Services\DemandForecast\Exceptions\CatBoostClientException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class DemandForecastOrchestrationService
{
    public const MODEL_VERSION_CATBOOST = 'native-catboost-v1';
    public const FEATURE_VERSION = 'native-37f-v1';
    public const SERVING_ORIGIN_CATBOOST = 'FASTAPI_CATBOOST';

    protected NativeDemandFeatureExtractor $featureExtractor;
    protected CatBoostServingClient $client;
    protected DemandForecastFallbackService $fallbackService;

    public function __construct(
        ?NativeDemandFeatureExtractor $featureExtractor = null,
        ?CatBoostServingClient $client = null,
        ?DemandForecastFallbackService $fallbackService = null
    ) {
        $this->featureExtractor = $featureExtractor ?? new NativeDemandFeatureExtractor();
        $this->client = $client ?? new CatBoostServingClient();
        $this->fallbackService = $fallbackService ?? new DemandForecastFallbackService();
    }

    /**
     * Generate forecasts for an outlet on a target date, evaluate fallback where necessary,
     * and persist results idempotently via upsert into menu_demand_forecasts.
     *
     * @param User $user
     * @param Outlet $outlet
     * @param string $targetDate Format YYYY-MM-DD
     * @param array|null $menuIds Optional array of specific menu IDs
     * @param string|null $explicitCutoffDate Optional cutoff date override
     * @param bool $isDryRun Whether to skip database persistence
     * @param int|null $limit Optional limit of menus processed
     * @return array
     */
    public function generateAndCacheForecasts(
        User $user,
        Outlet $outlet,
        string $targetDate,
        ?array $menuIds = null,
        ?string $explicitCutoffDate = null,
        bool $isDryRun = false,
        ?int $limit = null
    ): array {
        // Enforce tenant & outlet ownership invariants
        if ((int) $outlet->user_id !== (int) $user->id) {
            throw new InvalidArgumentException("Tenant isolation violation: Outlet {$outlet->id} does not belong to User {$user->id}.");
        }

        $targetCarbon = Carbon::parse($targetDate, 'Asia/Jakarta');
        $cutoffCarbon = $explicitCutoffDate
            ? Carbon::parse($explicitCutoffDate, 'Asia/Jakarta')
            : $targetCarbon->copy()->subDay();

        $targetDateStr = $targetCarbon->toDateString();
        $cutoffDateStr = $cutoffCarbon->toDateString();

        // 1. Fetch eligible menus
        $query = Menu::where('outlet_id', $outlet->id)
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->where('is_available', true);

        if (!empty($menuIds)) {
            $query->whereIn('id', $menuIds);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $menus = $query->get();

        if ($menus->isEmpty()) {
            return [
                'total_menus' => 0,
                'forecasts_generated' => 0,
                'catboost_count' => 0,
                'fallback_count' => 0,
                'insufficient_history_count' => 0,
                'failed_count' => 0,
                'target_date' => $targetDateStr,
                'cutoff_date' => $cutoffDateStr,
                'dry_run' => $isDryRun,
                'forecasts' => [],
            ];
        }

        // 2. Extract features
        $extractedItems = [];
        $insufficientHistoryCount = 0;
        foreach ($menus as $menu) {
            $item = $this->featureExtractor->extractForMenu(
                $user,
                $outlet,
                $menu,
                $targetDateStr,
                null,
                $cutoffDateStr
            );
            $extractedItems[] = $item;
            if ($item['history_status'] === 'INSUFFICIENT_HISTORY') {
                $insufficientHistoryCount++;
            }
        }

        // 3. Partition into CatBoost eligible vs direct fallback
        $eligibleForCatboost = [];
        $directFallbackItems = [];

        foreach ($extractedItems as $item) {
            if ($item['history_status'] === 'SUFFICIENT') {
                $eligibleForCatboost[] = $item;
            } else {
                $directFallbackItems[] = $item;
            }
        }

        $finalForecasts = [];
        $catboostSuccessCount = 0;
        $fallbackCount = 0;

        // 4. Process direct fallback items
        foreach ($directFallbackItems as $item) {
            $fallbackResult = $this->fallbackService->computeFallback($item);
            $finalForecasts[] = $this->enrichForecastRecord($fallbackResult, $user, $outlet, $targetDateStr, $cutoffDateStr, $item);
            $fallbackCount++;
        }

        // 5. Process CatBoost eligible items in batches <= 100
        if (!empty($eligibleForCatboost)) {
            $catboostEnabled = (bool) config('services.catboost_serving.enabled', true);

            if (!$catboostEnabled) {
                Log::info('[DemandForecastOrchestrationService] CatBoost serving disabled via CATBOOST_SERVING_ENABLED=false, routing all eligible items to local fallback', [
                    'outlet_id' => $outlet->id,
                    'items_count' => count($eligibleForCatboost),
                ]);

                foreach ($eligibleForCatboost as $batchItem) {
                    $fb = $this->fallbackService->computeFallback($batchItem);
                    $finalForecasts[] = $this->enrichForecastRecord($fb, $user, $outlet, $targetDateStr, $cutoffDateStr, $batchItem);
                    $fallbackCount++;
                }
            } else {
                $batches = array_chunk($eligibleForCatboost, 100);

                foreach ($batches as $batch) {
                    try {
                        $response = $this->client->predict($outlet->id, $targetDateStr, $batch);
                        $forecastsData = $response['data']['forecasts'] ?? [];
                        $forecastsMap = [];
                        foreach ($forecastsData as $f) {
                            $forecastsMap[(string) $f['menu_id']] = $f;
                        }

                        foreach ($batch as $batchItem) {
                            $menuId = (string) $batchItem['menu_id'];
                            if (isset($forecastsMap[$menuId])) {
                                $f = $forecastsMap[$menuId];
                                $std7 = (float) ($batchItem['features']['f13_rolling_std_7'] ?? 0.0);
                                $predQty = max(0.0, round((float) $f['predicted_quantity'], 3));
                                $effectiveStd = $std7 > 0.0 ? $std7 : (0.15 * $predQty);
                                $lowerBound = max(0.0, round($predQty - (1.28 * $effectiveStd), 3));
                                $upperBound = max(0.0, round($predQty + (1.28 * $effectiveStd), 3));

                                $forecastRecord = [
                                    'menu_id' => $menuId,
                                    'menu_name' => $batchItem['menu_name'],
                                    'category' => $batchItem['category'],
                                    'uom' => $batchItem['uom'],
                                    'predicted_quantity' => $predQty,
                                    'lower_bound_p10' => $lowerBound,
                                    'upper_bound_p90' => $upperBound,
                                    'raw_model_output' => (float) ($f['raw_model_output'] ?? $predQty),
                                    'clipped' => (bool) ($f['clipped'] ?? false),
                                    'confidence_level' => (string) ($f['confidence_level'] ?? 'HIGH'),
                                    'strategy_used' => (string) ($f['strategy_used'] ?? 'NATIVE_CATBOOST_REGRESSOR'),
                                    'fallback_applied' => false,
                                    'model_version' => self::MODEL_VERSION_CATBOOST,
                                    'feature_version' => self::FEATURE_VERSION,
                                    'serving_origin' => self::SERVING_ORIGIN_CATBOOST,
                                    'history_status' => $batchItem['history_status'],
                                    'metadata' => [
                                        'daemon_response' => true,
                                        'std_dev_7' => round($std7, 4),
                                    ],
                                ];

                                $finalForecasts[] = $this->enrichForecastRecord($forecastRecord, $user, $outlet, $targetDateStr, $cutoffDateStr, $batchItem);
                                $catboostSuccessCount++;
                            } else {
                                // Fallback for missing item
                                $fb = $this->fallbackService->computeFallback($batchItem);
                                $finalForecasts[] = $this->enrichForecastRecord($fb, $user, $outlet, $targetDateStr, $cutoffDateStr, $batchItem);
                                $fallbackCount++;
                            }
                        }
                    } catch (CatBoostClientException | Throwable $e) {
                        Log::warning('[DemandForecastOrchestrationService] CatBoost inference failed, executing batch fallback', [
                            'outlet_id' => $outlet->id,
                            'batch_size' => count($batch),
                            'error_class' => get_class($e),
                            'error' => $e->getMessage(),
                        ]);

                        foreach ($batch as $batchItem) {
                            $fb = $this->fallbackService->computeFallback($batchItem);
                            $finalForecasts[] = $this->enrichForecastRecord($fb, $user, $outlet, $targetDateStr, $cutoffDateStr, $batchItem);
                            $fallbackCount++;
                        }
                    }
                }
            }
        }

        // 6. Validate & Bulk Upsert into database
        $now = Carbon::now('Asia/Jakarta')->toDateTimeString();
        $upsertRows = [];
        $failedCount = 0;

        foreach ($finalForecasts as $fRecord) {
            $predQty = $fRecord['predicted_quantity'];
            if (!is_numeric($predQty) || is_nan((float) $predQty) || is_infinite((float) $predQty) || (float) $predQty < 0) {
                Log::warning('[DemandForecastOrchestrationService] Skipping invalid forecast record', [
                    'menu_id' => $fRecord['menu_id'] ?? null,
                    'pred_qty' => $predQty,
                ]);
                $failedCount++;
                continue;
            }

            $meta = $fRecord['metadata'] ?? [];
            if (isset($fRecord['fallback_applied'])) {
                $meta['fallback_applied'] = (bool) $fRecord['fallback_applied'];
            }
            if (isset($fRecord['strategy_used'])) {
                $meta['strategy_used'] = (string) $fRecord['strategy_used'];
            }

            $upsertRows[] = [
                'user_id' => (int) $user->id,
                'outlet_id' => (int) $outlet->id,
                'menu_id' => (int) $fRecord['menu_id'],
                'target_date' => $targetDateStr,
                'cutoff_date' => $cutoffDateStr,
                'uom' => (string) $fRecord['uom'],
                'predicted_quantity' => round((float) $predQty, 3),
                'lower_bound_p10' => round((float) $fRecord['lower_bound_p10'], 3),
                'upper_bound_p90' => round((float) $fRecord['upper_bound_p90'], 3),
                'model_version' => (string) $fRecord['model_version'],
                'feature_version' => (string) $fRecord['feature_version'],
                'serving_origin' => (string) $fRecord['serving_origin'],
                'history_status' => (string) $fRecord['history_status'],
                'metadata' => json_encode($meta),
                'generated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!$isDryRun && !empty($upsertRows)) {
            MenuDemandForecast::upsert(
                $upsertRows,
                ['user_id', 'outlet_id', 'menu_id', 'target_date', 'uom'],
                [
                    'cutoff_date',
                    'predicted_quantity',
                    'lower_bound_p10',
                    'upper_bound_p90',
                    'model_version',
                    'feature_version',
                    'serving_origin',
                    'history_status',
                    'metadata',
                    'generated_at',
                    'updated_at',
                ]
            );
        }

        return [
            'total_menus' => count($menus),
            'forecasts_generated' => count($upsertRows),
            'catboost_count' => $catboostSuccessCount,
            'fallback_count' => $fallbackCount,
            'insufficient_history_count' => $insufficientHistoryCount,
            'failed_count' => $failedCount,
            'target_date' => $targetDateStr,
            'cutoff_date' => $cutoffDateStr,
            'dry_run' => $isDryRun,
            'forecasts' => $finalForecasts,
        ];
    }

    protected function enrichForecastRecord(
        array $record,
        User $user,
        Outlet $outlet,
        string $targetDateStr,
        string $cutoffDateStr,
        array $extractedItem
    ): array {
        $record['user_id'] = (int) $user->id;
        $record['outlet_id'] = (int) $outlet->id;
        $record['target_date'] = $targetDateStr;
        $record['cutoff_date'] = $cutoffDateStr;
        $record['menu_id'] = (int) $extractedItem['menu_id'];
        $record['menu_name'] = $extractedItem['menu_name'];
        $record['category'] = $extractedItem['category'];
        $record['uom'] = $extractedItem['uom'];
        return $record;
    }
}
