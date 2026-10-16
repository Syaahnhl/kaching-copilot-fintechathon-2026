<?php

namespace App\Helpers;

use Carbon\Carbon;
use App\Models\Outlet;

/**
 * Phase 2: Canonical UTC Timestamp + Outlet Business Timezone
 *
 * SSOT contract:
 * - Database stores created_at_device as UTC instant
 * - Business date = calendar date derived from UTC + outlet timezone
 * - Reporting uses half-open UTC range: [startUtc, nextDayStartUtc)
 * - API response timestamps use ISO-8601 with explicit Z suffix
 *
 * @see references/timezone-ssot-and-midnight-boundary-forensic.md
 */
class TimezoneContract
{
    /**
     * Resolve the canonical business timezone for an outlet.
     *
     * Priority: outlet.timezone column > fallback 'Asia/Jakarta'
     * NEVER uses server timezone or device timezone for business-date authority.
     */
    public static function resolveOutletTimezone($outletId = null): string
    {
        if ($outletId) {
            $outlet = Outlet::find($outletId);
            if ($outlet && !empty($outlet->timezone)) {
                return $outlet->timezone;
            }
        }
        return 'Asia/Jakarta';
    }

    /**
     * Compute UTC half-open range for a business date in a given timezone.
     *
     * Returns [$startUtc, $nextDayStartUtc] where:
     *   - $startUtc     = start of business day in UTC (inclusive)
     *   - $nextDayStartUtc = start of NEXT business day in UTC (exclusive)
     *
     * Usage: WHERE created_at_device >= $startUtc AND created_at_device < $nextDayStartUtc
     *
     * Example for 2026-09-04 Asia/Jakarta (+07):
     *   startUtc       = 2026-09-03 17:00:00
     *   nextDayStartUtc = 2026-09-04 17:00:00
     */
    public static function businessDateToUtcRange(string $date, string $timezone): array
    {
        $startUtc = Carbon::parse($date, $timezone)
            ->startOfDay()
            ->timezone('UTC')
            ->format('Y-m-d H:i:s');

        $nextDayStartUtc = Carbon::parse($date, $timezone)
            ->addDay()
            ->startOfDay()
            ->timezone('UTC')
            ->format('Y-m-d H:i:s');

        return [$startUtc, $nextDayStartUtc];
    }

    /**
     * Compute UTC half-open range for a business date RANGE (multi-day).
     *
     * Returns [$startUtc, $endExclusiveUtc] spanning startDate 00:00 to endDate+1 00:00 in outlet tz.
     *
     * Usage: WHERE created_at_device >= $startUtc AND created_at_device < $endExclusiveUtc
     */
    public static function businessDateRangeToUtcRange(string $startDate, string $endDate, string $timezone): array
    {
        $startUtc = Carbon::parse($startDate, $timezone)
            ->startOfDay()
            ->timezone('UTC')
            ->format('Y-m-d H:i:s');

        $endExclusiveUtc = Carbon::parse($endDate, $timezone)
            ->addDay()
            ->startOfDay()
            ->timezone('UTC')
            ->format('Y-m-d H:i:s');

        return [$startUtc, $endExclusiveUtc];
    }

    /**
     * Format a UTC database timestamp as ISO-8601 with explicit Z suffix.
     *
     * Input:  "2026-09-04 16:59:59" (UTC from DB)
     * Output: "2026-09-04T16:59:59Z"
     *
     * Handles null, empty, Carbon instances, and raw strings.
     * Does NOT shift the timezone — value is already UTC.
     */
    public static function formatUtcAsIso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            // Parse as UTC (it IS UTC in the DB) and format as ISO-8601 Z
            return Carbon::parse($value, 'UTC')->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            // If parsing fails, return raw value to avoid data loss
            return $value;
        }
    }
}
