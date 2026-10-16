<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Helpers\TimezoneContract;
use App\Models\Transaction;
use Carbon\Carbon;

/**
 * Phase 2.1 & 2: Canonical UTC Timestamp + Outlet Business Timezone Tests
 *
 * Verifies:
 * 1. TimezoneContract UTC half-open range derivation
 * 2. WIB, WITA, WIT, UTC midnight boundary matrix
 * 3. Invariant: 23:59:59.999 belongs to previous day, 00:00:00.000 belongs to new day
 * 4. Multi-day range derivation
 * 5. Transaction model created_at_device serialization (ISO-8601 with Z)
 * 6. Round-trip epoch invariance: epoch_before == epoch_after (0 ms difference)
 */
class TimezoneCanonicalContractTest extends TestCase
{
    /**
     * Test 1: Single Business Date to UTC Half-Open Range for WIB (Asia/Jakarta = UTC+7)
     */
    public function test_wib_business_date_utc_range()
    {
        [$startUtc, $nextDayStartUtc] = TimezoneContract::businessDateToUtcRange('2026-09-04', 'Asia/Jakarta');

        // WIB is UTC+7:
        // 2026-09-04 00:00:00 WIB = 2026-09-03 17:00:00 UTC
        // 2026-09-05 00:00:00 WIB = 2026-09-04 17:00:00 UTC
        $this->assertEquals('2026-09-03 17:00:00', $startUtc);
        $this->assertEquals('2026-09-04 17:00:00', $nextDayStartUtc);
    }

    /**
     * Test 2: Single Business Date to UTC Half-Open Range for WITA (Asia/Makassar = UTC+8)
     */
    public function test_wita_business_date_utc_range()
    {
        [$startUtc, $nextDayStartUtc] = TimezoneContract::businessDateToUtcRange('2026-09-04', 'Asia/Makassar');

        // WITA is UTC+8:
        // 2026-09-04 00:00:00 WITA = 2026-09-03 16:00:00 UTC
        // 2026-09-05 00:00:00 WITA = 2026-09-04 16:00:00 UTC
        $this->assertEquals('2026-09-03 16:00:00', $startUtc);
        $this->assertEquals('2026-09-04 16:00:00', $nextDayStartUtc);
    }

    /**
     * Test 3: Single Business Date to UTC Half-Open Range for WIT (Asia/Jayapura = UTC+9)
     */
    public function test_wit_business_date_utc_range()
    {
        [$startUtc, $nextDayStartUtc] = TimezoneContract::businessDateToUtcRange('2026-09-04', 'Asia/Jayapura');

        // WIT is UTC+9:
        // 2026-09-04 00:00:00 WIT = 2026-09-03 15:00:00 UTC
        // 2026-09-05 00:00:00 WIT = 2026-09-04 15:00:00 UTC
        $this->assertEquals('2026-09-03 15:00:00', $startUtc);
        $this->assertEquals('2026-09-04 15:00:00', $nextDayStartUtc);
    }

    /**
     * Test 4: Single Business Date to UTC Half-Open Range for UTC (+00:00)
     */
    public function test_utc_business_date_utc_range()
    {
        [$startUtc, $nextDayStartUtc] = TimezoneContract::businessDateToUtcRange('2026-09-04', 'UTC');

        $this->assertEquals('2026-09-04 00:00:00', $startUtc);
        $this->assertEquals('2026-09-05 00:00:00', $nextDayStartUtc);
    }

    /**
     * Test 5: Multi-Day Business Date Range (2026-09-01 to 2026-09-04 in WIB)
     */
    public function test_multi_day_business_date_range()
    {
        [$startUtc, $endExclusiveUtc] = TimezoneContract::businessDateRangeToUtcRange(
            '2026-09-01',
            '2026-09-04',
            'Asia/Jakarta'
        );

        // 2026-09-01 00:00:00 WIB = 2026-08-31 17:00:00 UTC
        // 2026-09-05 00:00:00 WIB = 2026-09-04 17:00:00 UTC
        $this->assertEquals('2026-08-31 17:00:00', $startUtc);
        $this->assertEquals('2026-09-04 17:00:00', $endExclusiveUtc);
    }

    /**
     * Test 6: Midnight Boundary Invariants (WIB, WITA, WIT)
     *
     * Invariant:
     * - 2026-09-04 23:59:59 local is INSIDE the 2026-09-04 business date window
     * - 2026-09-05 00:00:00 local is OUTSIDE (at boundary, belongs to next day)
     */
    public function test_midnight_boundary_matrix()
    {
        $zones = [
            'WIB'  => ['tz' => 'Asia/Jakarta',  'offset' => 7],
            'WITA' => ['tz' => 'Asia/Makassar', 'offset' => 8],
            'WIT'  => ['tz' => 'Asia/Jayapura', 'offset' => 9],
        ];

        foreach ($zones as $label => $config) {
            $tz = $config['tz'];
            [$startUtc, $nextDayStartUtc] = TimezoneContract::businessDateToUtcRange('2026-09-04', $tz);

            // Last second of 2026-09-04 in this timezone
            $lastSecondLocal = Carbon::parse('2026-09-04 23:59:59', $tz);
            $lastSecondUtcStr = $lastSecondLocal->copy()->timezone('UTC')->format('Y-m-d H:i:s');

            // First second of 2026-09-05 in this timezone
            $firstSecondLocal = Carbon::parse('2026-09-05 00:00:00', $tz);
            $firstSecondUtcStr = $firstSecondLocal->copy()->timezone('UTC')->format('Y-m-d H:i:s');

            // Verification:
            // lastSecond must be >= startUtc AND < nextDayStartUtc
            $this->assertGreaterThanOrEqual(
                $startUtc,
                $lastSecondUtcStr,
                "[$label] 23:59:59 UTC ($lastSecondUtcStr) must be >= startUtc ($startUtc)"
            );
            $this->assertLessThan(
                $nextDayStartUtc,
                $lastSecondUtcStr,
                "[$label] 23:59:59 UTC ($lastSecondUtcStr) must be < nextDayStartUtc ($nextDayStartUtc)"
            );

            // firstSecond must NOT be in 2026-09-04 range (it equals nextDayStartUtc, excluded by half-open)
            $this->assertEquals(
                $nextDayStartUtc,
                $firstSecondUtcStr,
                "[$label] 00:00:00 of next day must exactly equal exclusive boundary ($nextDayStartUtc)"
            );
        }
    }

    /**
     * Test 7: Report Inclusion / Exclusion Test Matrix
     *
     * For Business Date = 2026-09-04, Timezone = Asia/Jakarta:
     * - 2026-09-03 16:59:59 UTC -> EXCLUDED (< startUtc)
     * - 2026-09-03 17:00:00 UTC -> INCLUDED (== startUtc)
     * - 2026-09-04 16:59:59 UTC -> INCLUDED (< nextDayStartUtc)
     * - 2026-09-04 17:00:00 UTC -> EXCLUDED (== nextDayStartUtc, exclusive)
     */
    public function test_report_boundary_inclusion_exclusion()
    {
        [$startUtc, $nextDayStartUtc] = TimezoneContract::businessDateToUtcRange('2026-09-04', 'Asia/Jakarta');

        $isIncluded = function ($timestamp) use ($startUtc, $nextDayStartUtc) {
            return $timestamp >= $startUtc && $timestamp < $nextDayStartUtc;
        };

        $this->assertFalse($isIncluded('2026-09-03 16:59:59'), '1 sec before window -> excluded');
        $this->assertTrue($isIncluded('2026-09-03 17:00:00'), 'Exact start of window -> included');
        $this->assertTrue($isIncluded('2026-09-04 00:00:00'), 'Mid-day -> included');
        $this->assertTrue($isIncluded('2026-09-04 16:59:59'), 'Last second of window -> included');
        $this->assertFalse($isIncluded('2026-09-04 17:00:00'), 'Exact next day start -> excluded (half-open)');
        $this->assertFalse($isIncluded('2026-09-04 17:00:01'), '1 sec after window -> excluded');
    }

    /**
     * Test 8: Transaction Model created_at_device Accessor Serialization
     */
    public function test_transaction_created_at_device_serialization()
    {
        // 1. Raw DB string "2026-09-04 16:59:59" (UTC) -> "2026-09-04T16:59:59Z"
        $isoFormatted = TimezoneContract::formatUtcAsIso('2026-09-04 16:59:59');
        $this->assertEquals('2026-09-04T16:59:59Z', $isoFormatted);

        // 2. Already ISO formatted string -> re-formatted cleanly with Z
        $isoFormatted2 = TimezoneContract::formatUtcAsIso('2026-09-04T16:59:59Z');
        $this->assertEquals('2026-09-04T16:59:59Z', $isoFormatted2);

        // 3. Null handling
        $this->assertNull(TimezoneContract::formatUtcAsIso(null));

        // 4. Empty string handling
        $this->assertEquals('', TimezoneContract::formatUtcAsIso(''));

        // 5. Eloquent model accessor integration
        $trx = new Transaction();
        $trx->setRawAttributes(['created_at_device' => '2026-09-04 16:59:59']);
        $this->assertEquals('2026-09-04T16:59:59Z', $trx->created_at_device);
    }

    /**
     * Test 9: Mandatory Deterministic Round-Trip Test (0 ms drift)
     *
     * Android Source:     2026-09-04 23:59:59 WIB (Epoch: 1788541199000)
     * Backend DB Storage: 2026-09-04 16:59:59 UTC
     * API Response:       2026-09-04T16:59:59Z
     * Invariant:          Carbon parse of API response == Carbon parse of input source
     */
    public function test_deterministic_round_trip()
    {
        // Android source timestamp in WIB
        $androidSourceEpochMs = 1788541199000;
        $androidSourceCarbon = Carbon::createFromTimestampMs($androidSourceEpochMs, 'Asia/Jakarta');

        // Android formats for wire: "2026-09-04 23:59:59"
        $wireString = $androidSourceCarbon->format('Y-m-d H:i:s');
        $this->assertEquals('2026-09-04 23:59:59', $wireString);

        // Backend ingestion (ProcessBulkTransactions pattern):
        // Parse wire string using outlet timezone -> convert to UTC
        $dbStoredCarbon = Carbon::parse($wireString, 'Asia/Jakarta')->timezone('UTC');
        $dbStoredString = $dbStoredCarbon->format('Y-m-d H:i:s');
        $this->assertEquals('2026-09-04 16:59:59', $dbStoredString);

        // Backend serialization (Transaction model accessor):
        $apiResponseString = TimezoneContract::formatUtcAsIso($dbStoredString);
        $this->assertEquals('2026-09-04T16:59:59Z', $apiResponseString);

        // Android parse (ISO-8601 UTC):
        $androidParsedCarbon = Carbon::parse($apiResponseString, 'UTC');
        $androidParsedEpochMs = (int) ($androidParsedCarbon->getPreciseTimestamp(3));

        // Invariant: epoch_before == epoch_after
        $this->assertEquals($androidSourceEpochMs, $androidParsedEpochMs);
        $this->assertEquals(0, abs($androidSourceEpochMs - $androidParsedEpochMs), 'Round-trip drift MUST be 0 ms');
    }
}
