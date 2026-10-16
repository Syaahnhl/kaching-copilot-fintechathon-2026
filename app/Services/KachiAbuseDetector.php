<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class KachiAbuseDetector
{
    private const MAX_ATTEMPTS = 15; // 15 reqs
    private const DECAY_MINUTES = 1; // per minute
    
    // Penalties in minutes: 1st, 2nd, 3rd, 4th+
    private const PENALTY_TIERS = [1, 3, 5, 15];

    /**
     * Checks if the user is currently penalized or has exceeded the limit.
     * Returns a string error message if penalized, null if allowed.
     */
    public static function checkRateLimit(string $ip, ?string $userId): ?string
    {
        $identifier = $userId ? "user_{$userId}" : "ip_{$ip}";
        $rateKey = "kachi_rate_{$identifier}";
        $penaltyKey = "kachi_penalty_{$identifier}";
        $tierKey = "kachi_tier_{$identifier}";

        // 1. Check if currently in active penalty cooldown
        if (Cache::has($penaltyKey)) {
            $timeLeft = ceil(Cache::tags(['abuse'])->get($penaltyKey, 0) - now()->timestamp) / 60;
            return "Tunggu bentar ya 👀 Kachi mendeteksi terlalu banyak pesan beruntun. Coba lagi nanti.";
        }

        // 2. Check standard rate limit
        if (RateLimiter::tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            // User exceeded limit -> Apply exponential penalty
            $currentTier = Cache::get($tierKey, 0);
            $penaltyMinutes = self::PENALTY_TIERS[min($currentTier, count(self::PENALTY_TIERS) - 1)];
            
            // Set penalty expiration timestamp
            Cache::put($penaltyKey, now()->addMinutes($penaltyMinutes)->timestamp, now()->addMinutes($penaltyMinutes));
            
            // Increment tier for next time (remember tier for 2 hours)
            Cache::put($tierKey, $currentTier + 1, now()->addHours(2));
            
            return "Kamu mengirim pesan terlalu cepat! Kachi butuh istirahat {$penaltyMinutes} menit ya 😅";
        }

        // Hit the rate limiter
        RateLimiter::hit($rateKey, self::DECAY_MINUTES * 60);

        return null;
    }

    /**
     * Optional: Call this if AI detects obvious prompt injection or nonsense
     * to immediately increment the abuse tier.
     */
    public static function flagAbuse(string $ip, ?string $userId): void
    {
        $identifier = $userId ? "user_{$userId}" : "ip_{$ip}";
        $tierKey = "kachi_tier_{$identifier}";
        $currentTier = Cache::get($tierKey, 0);
        Cache::put($tierKey, $currentTier + 1, now()->addHours(2));
    }
}
