<?php

namespace App\Services\DemandForecast;

class DemandForecastFallbackService
{
    public const MODEL_VERSION = 'statistical-baseline-v1';
    public const FEATURE_VERSION = 'native-37f-v1';
    public const SERVING_ORIGIN = 'FALLBACK_LOCAL';

    /**
     * Compute deterministic fallback forecast for an extracted feature item.
     *
     * @param array $extractedItem
     * @return array
     */
    public function computeFallback(array $extractedItem): array
    {
        $features = $extractedItem['features'] ?? [];
        $historyStatus = $extractedItem['history_status'] ?? 'INSUFFICIENT_HISTORY';

        $f10 = (float) ($features['f10_rolling_mean_7'] ?? 0.0);
        $f13 = (float) ($features['f13_rolling_std_7'] ?? 0.0);
        $f16 = (float) ($features['f16_same_dow_lag_7'] ?? 0.0);
        $f20 = (float) ($features['f20_same_dow_mean_4w'] ?? 0.0);

        $predictedQty = 0.0;
        $strategyUsed = 'INSUFFICIENT_HISTORY';
        $confidenceLevel = 'LOW';

        if ($historyStatus === 'SUFFICIENT') {
            if ($f16 > 0.0) {
                $predictedQty = $f16;
                $strategyUsed = 'SEASONAL_NAIVE_DOW7';
                $confidenceLevel = 'MEDIUM';
            } elseif ($f20 > 0.0) {
                $predictedQty = $f20;
                $strategyUsed = 'SAME_DOW_MEAN_4W';
                $confidenceLevel = 'MEDIUM';
            } elseif ($f10 > 0.0) {
                $predictedQty = $f10;
                $strategyUsed = 'ROLLING_MEAN_7';
                $confidenceLevel = 'MEDIUM';
            } else {
                $predictedQty = 0.0;
                $strategyUsed = 'ROLLING_MEAN_7_ZERO';
                $confidenceLevel = 'LOW';
            }
        } elseif ($historyStatus === 'ZERO_DEMAND') {
            $predictedQty = 0.0;
            $strategyUsed = 'ZERO_DEMAND';
            $confidenceLevel = 'MEDIUM';
        } else {
            // INSUFFICIENT_HISTORY
            $predictedQty = $f10 > 0.0 ? $f10 : 0.0;
            $strategyUsed = 'INSUFFICIENT_HISTORY';
            $confidenceLevel = 'LOW';
        }

        // Clip negative predictions to zero
        $predictedQty = max(0.0, round($predictedQty, 3));

        // Bounds estimation (p10 / p90 heuristic using z=1.28)
        $effectiveStd = $f13 > 0.0 ? $f13 : (0.2 * $predictedQty);
        $lowerBound = max(0.0, round($predictedQty - (1.28 * $effectiveStd), 3));
        $upperBound = max(0.0, round($predictedQty + (1.28 * $effectiveStd), 3));

        return [
            'menu_id' => (string) ($extractedItem['menu_id'] ?? ''),
            'menu_name' => (string) ($extractedItem['menu_name'] ?? ''),
            'category' => (string) ($extractedItem['category'] ?? ''),
            'uom' => (string) ($extractedItem['uom'] ?? 'porsi'),
            'predicted_quantity' => $predictedQty,
            'lower_bound_p10' => $lowerBound,
            'upper_bound_p90' => $upperBound,
            'raw_model_output' => $predictedQty,
            'clipped' => false,
            'confidence_level' => $confidenceLevel,
            'strategy_used' => $strategyUsed,
            'fallback_applied' => true,
            'model_version' => self::MODEL_VERSION,
            'feature_version' => self::FEATURE_VERSION,
            'serving_origin' => self::SERVING_ORIGIN,
            'history_status' => $historyStatus,
            'metadata' => [
                'reason' => 'daemon_offline_or_insufficient_history',
                'std_dev_7' => round($f13, 4),
                'rolling_mean_7' => round($f10, 4),
                'same_dow_lag_7' => round($f16, 4),
            ],
        ];
    }

    /**
     * Compute fallback for multiple extracted items.
     *
     * @param array $extractedItems
     * @return array
     */
    public function computeBatchFallback(array $extractedItems): array
    {
        $results = [];
        foreach ($extractedItems as $item) {
            $results[] = $this->computeFallback($item);
        }
        return $results;
    }
}
