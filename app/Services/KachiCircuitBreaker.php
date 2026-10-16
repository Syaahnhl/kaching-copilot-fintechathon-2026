<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class KachiCircuitBreaker
{
    private const FAILURES_KEY = 'kachi_cb_failures';
    private const OPEN_STATE_KEY = 'kachi_cb_open';
    private const MAX_FAILURES = 3;
    private const FAILURE_WINDOW_SECONDS = 30;
    private const OPEN_TTL_SECONDS = 45;

    /**
     * Check if the circuit is closed (allow requests).
     * If the open state expired, it implicitly acts as HALF_OPEN.
     */
    public static function isAvailable(): bool
    {
        if (Cache::has(self::OPEN_STATE_KEY)) {
            return false;
        }

        return true;
    }

    public static function recordFailure(): void
    {
        // Increment atomic counter
        $failures = Cache::increment(self::FAILURES_KEY);
        
        // If it's the first failure, set expiration for the window
        if ($failures === 1) {
            Cache::put(self::FAILURES_KEY, 1, self::FAILURE_WINDOW_SECONDS);
        }

        // Trip the breaker if threshold reached
        if ($failures >= self::MAX_FAILURES) {
            Cache::put(self::OPEN_STATE_KEY, true, self::OPEN_TTL_SECONDS);
            Cache::forget(self::FAILURES_KEY);
        }
    }

    public static function recordSuccess(): void
    {
        if (Cache::has(self::FAILURES_KEY)) {
            Cache::forget(self::FAILURES_KEY);
        }
        if (Cache::has(self::OPEN_STATE_KEY)) {
            Cache::forget(self::OPEN_STATE_KEY);
        }
    }
}
