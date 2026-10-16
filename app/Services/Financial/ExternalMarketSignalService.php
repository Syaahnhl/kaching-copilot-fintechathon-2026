<?php

namespace App\Services\Financial;

use Carbon\Carbon;

/**
 * External Market Signal Pipeline (Phase 3.7)
 *
 * Ingests, normalizes, and sanitizes external signals (calendar holidays,
 * long weekend clusters, weather, commodity indices).
 *
 * PROVENANCE & SAFETY CONTRACT:
 * - Every signal records exact source, timestamp, retrieval status, and confidence.
 * - Live APIs without configured keys strictly return UNAVAILABLE.
 * - External news/text signals pass through strict prompt-injection defenses
 *   and are marked as DATA_ONLY (never executable instructions).
 */
class ExternalMarketSignalService
{
    public const STATUS_AVAILABLE   = 'AVAILABLE';
    public const STATUS_UNAVAILABLE = 'UNAVAILABLE';

    // Indonesian Public Holidays and Significant Observances 2026 (Deterministic Baseline)
    protected const HOLIDAYS_2026 = [
        '2026-01-01' => 'Tahun Baru 2026 Masehi',
        '2026-01-16' => 'Isra Mikraj Nabi Muhammad SAW',
        '2026-02-17' => 'Tahun Baru Imlek 2577 Kongzili',
        '2026-03-20' => 'Hari Suci Nyepi (Tahun Baru Saka 1948)',
        '2026-03-21' => 'Hari Raya Idul Fitri 1447 Hijriah (Hari 1)',
        '2026-03-22' => 'Hari Raya Idul Fitri 1447 Hijriah (Hari 2)',
        '2026-04-03' => 'Wafat Yesus Kristus (Jumat Agung)',
        '2026-05-01' => 'Hari Buruh Internasional',
        '2026-05-14' => 'Kenaikan Yesus Kristus',
        '2026-05-27' => 'Hari Raya Idul Adha 1447 Hijriah',
        '2026-05-31' => 'Hari Raya Waisak 2570',
        '2026-06-01' => 'Hari Lahir Pancasila',
        '2026-06-16' => 'Tahun Baru Islam 1448 Hijriah',
        '2026-08-17' => 'Hari Kemerdekaan Republik Indonesia ke-81',
        '2026-08-25' => 'Maulid Nabi Muhammad SAW',
        '2026-12-25' => 'Hari Raya Natal',
    ];

    /**
     * Retrieve all market signals for a target date.
     */
    public function getSignalsForDate(string $targetDate, string $timezone = 'Asia/Jakarta'): array
    {
        $dateObj = Carbon::parse($targetDate, $timezone);
        $dateStr = $dateObj->format('Y-m-d');
        $dow = (int) $dateObj->dayOfWeekIso; // 1 = Monday, 7 = Sunday
        $dom = (int) $dateObj->day;

        // 1. Calendar / Holiday Signal (Supported & Deterministic)
        $isHoliday = isset(self::HOLIDAYS_2026[$dateStr]);
        $holidayName = self::HOLIDAYS_2026[$dateStr] ?? null;

        // Long weekend detection (Holiday adjacent to Saturday/Sunday)
        $isLongWeekend = false;
        if ($isHoliday) {
            if ($dow === 5 || $dow === 1) { // Friday or Monday holiday
                $isLongWeekend = true;
            }
        } elseif ($dow === 6 || $dow === 7) { // Saturday/Sunday adjacent to Friday/Monday holiday
            $prevFri = (clone $dateObj)->previous(Carbon::FRIDAY)->format('Y-m-d');
            $nextMon = (clone $dateObj)->next(Carbon::MONDAY)->format('Y-m-d');
            if (isset(self::HOLIDAYS_2026[$prevFri]) || isset(self::HOLIDAYS_2026[$nextMon])) {
                $isLongWeekend = true;
            }
        }

        $calendarSignal = [
            'signal_type'      => 'CALENDAR_EVENT',
            'status'           => self::STATUS_AVAILABLE,
            'source'           => 'INDONESIAN_OFFICIAL_CALENDAR_2026',
            'retrieved_at'     => Carbon::now($timezone)->toIso8601String(),
            'target_date'      => $dateStr,
            'is_public_holiday'=> $isHoliday,
            'holiday_name'     => $holidayName,
            'is_weekend'       => ($dow >= 6),
            'is_long_weekend'  => $isLongWeekend,
            'is_payday_window' => ($dom >= 25),
            'confidence'       => 1.0,
            'demand_impact_indicator' => ($isHoliday || $isLongWeekend) ? 'HIGH_WEEKEND_TRAFFIC' : 'NORMAL_TRAFFIC',
        ];

        // 2. Weather Signal (Audit: No live weather API configured)
        $weatherSignal = [
            'signal_type'      => 'WEATHER_FORECAST',
            'status'           => self::STATUS_UNAVAILABLE,
            'source'           => 'METEOROLOGICAL_AGENCY_FEED',
            'retrieved_at'     => Carbon::now($timezone)->toIso8601String(),
            'reason'           => 'NO_EXTERNAL_WEATHER_API_KEY_CONFIGURED',
            'confidence'       => 0.0,
            'data'             => null,
            'message'          => 'Layanan cuaca eksternal belum dikonfigurasi. Tidak ada data sintetis yang dihasilkan.',
        ];

        // 3. Commodity Price Signal (Audit: No live commodity stream configured)
        $commoditySignal = [
            'signal_type'      => 'COMMODITY_PRICE_INDEX',
            'status'           => self::STATUS_UNAVAILABLE,
            'source'           => 'COMMODITY_MARKET_FEED',
            'retrieved_at'     => Carbon::now($timezone)->toIso8601String(),
            'reason'           => 'NO_COMMODITY_FEED_CONFIGURED',
            'confidence'       => 0.0,
            'data'             => null,
            'message'          => 'Indeks harga komoditas pasar belum terhubung secara langsung.',
        ];

        return [
            'status'      => 'SUCCESS',
            'target_date' => $dateStr,
            'signals'     => [
                'calendar'  => $calendarSignal,
                'weather'   => $weatherSignal,
                'commodity' => $commoditySignal,
            ],
            'governance'  => [
                'policy'           => 'DATA_ONLY',
                'action_execution' => 'FORBIDDEN_WITHOUT_HUMAN_APPROVAL',
            ],
        ];
    }

    /**
     * Sanitize and neutralize external news/market text against Prompt Injection.
     */
    public function sanitizeExternalNewsSignal(string $rawText, string $source, ?string $retrievedAt = null): array
    {
        $retrievedAt = $retrievedAt ?? Carbon::now('UTC')->toIso8601String();

        // 1. Detect prompt injection patterns
        $injectionPatterns = [
            '/ignore\s+(all\s+)?(previous|prior)\s+instructions/i',
            '/system\s*:/i',
            '/\badmin\s+override\b/i',
            '/\bapprove\s+(this\s+)?(loan|financing|payout)\b/i',
            '/\btransfer\s+funds\b/i',
            '/\bdrop\s+table\b/i',
            '/\bdelete\s+from\b/i',
            '/<script\b[^>]*>(.*?)<\/script>/is',
        ];

        $threatDetected = false;
        $matchedPatterns = [];

        foreach ($injectionPatterns as $pattern) {
            if (preg_match($pattern, $rawText)) {
                $threatDetected = true;
                $matchedPatterns[] = $pattern;
            }
        }

        // 2. Strip HTML tags and control characters
        $cleanText = strip_tags($rawText);
        $cleanText = preg_replace('/[\x00-\x1F\x7F]/u', '', $cleanText);
        $cleanText = trim($cleanText);

        // 3. If threat detected, neutralize text payload
        if ($threatDetected) {
            $cleanText = '[SECURITY NOTICE: Potensi prompt injection atau instruksi berbahaya dinetralisasi. Konten hanya berupa teks pasif.]';
        }

        return [
            'status'           => 'SANITIZED',
            'source'           => $source,
            'retrieved_at'     => $retrievedAt,
            'is_safe_data'     => true,
            'threat_detected'  => $threatDetected,
            'matched_patterns' => $matchedPatterns,
            'clean_content'    => $cleanText,
            'governance'       => 'DATA_ONLY_PASSIVE_CONTEXT',
        ];
    }
}
