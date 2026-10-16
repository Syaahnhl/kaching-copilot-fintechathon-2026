<?php

namespace Tests\Feature\DemandForecast;

use App\Models\DailyMenuSalesSummary;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DemandForecastSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.demand_forecast.enabled' => true]);
        // Resolve the application Schedule instance which has routes/console.php loaded
        $this->schedule = $this->app->make(Schedule::class);
        Cache::flush();
    }

    /**
     * Helper to find all scheduled events matching a command substring.
     *
     * @param string $commandSubstring
     * @return Event[]
     */
    protected function getEventsForCommand(string $commandSubstring): array
    {
        return array_values(array_filter($this->schedule->events(), function (Event $event) use ($commandSubstring) {
            return str_contains($event->command, $commandSubstring);
        }));
    }

    /**
     * Test 1: Kedua command terdaftar tepat satu kali (Zero duplication).
     */
    public function test_both_commands_are_registered_exactly_once_without_duplication(): void
    {
        $summaryEvents = $this->getEventsForCommand('kaching:summarize-daily-sales');
        $forecastEvents = $this->getEventsForCommand('kaching:forecast-demand');

        $this->assertCount(1, $summaryEvents, 'Command kaching:summarize-daily-sales harus terdaftar tepat satu kali.');
        $this->assertCount(1, $forecastEvents, 'Command kaching:forecast-demand harus terdaftar tepat satu kali.');
    }

    /**
     * Test 2: Jadwal dan timezone Asia/Jakarta terkonfigurasi dengan tepat.
     */
    public function test_schedule_cron_expression_and_timezone_configuration(): void
    {
        $summaryEvents = $this->getEventsForCommand('kaching:summarize-daily-sales');
        $forecastEvents = $this->getEventsForCommand('kaching:forecast-demand');

        $summaryEvent = $summaryEvents[0];
        $forecastEvent = $forecastEvents[0];

        // 02:45 daily
        $this->assertEquals('45 2 * * *', $summaryEvent->expression);
        $this->assertEquals('Asia/Jakarta', $summaryEvent->timezone);

        // 03:00 daily with --horizon=7
        $this->assertEquals('0 3 * * *', $forecastEvent->expression);
        $this->assertEquals('Asia/Jakarta', $forecastEvent->timezone);
        $this->assertStringContainsString('--horizon=7', $forecastEvent->command);
    }

    /**
     * Test 3: Evaluasi withoutOverlapping dan penamaan event (Mutex lock).
     */
    public function test_without_overlapping_and_event_mutex_naming(): void
    {
        $summaryEvents = $this->getEventsForCommand('kaching:summarize-daily-sales');
        $forecastEvents = $this->getEventsForCommand('kaching:forecast-demand');

        $summaryEvent = $summaryEvents[0];
        $forecastEvent = $forecastEvents[0];

        $this->assertTrue($summaryEvent->withoutOverlapping, 'kaching:summarize-daily-sales harus menggunakan withoutOverlapping.');
        $this->assertTrue($forecastEvent->withoutOverlapping, 'kaching:forecast-demand harus menggunakan withoutOverlapping.');

        $this->assertEquals(120, $summaryEvent->expiresAt, 'Lock expiration summarizer harus 120 menit.');
        $this->assertEquals(120, $forecastEvent->expiresAt, 'Lock expiration forecast harus 120 menit.');

        $this->assertEquals('kaching-daily-sales-summary', $summaryEvent->description);
        $this->assertEquals('kaching-demand-forecast', $forecastEvent->description);
    }

    /**
     * Test 4: Forecast 'when' filter meloloskan eksekusi jika token status mencatat COMPLETED dan ALL_OUTLETS untuk tanggal kemarin.
     */
    public function test_forecast_when_filter_passes_when_all_outlets_summary_token_is_valid(): void
    {
        $forecastEvents = $this->getEventsForCommand('kaching:forecast-demand');
        $forecastEvent = $forecastEvents[0];

        $yesterdayWib = Carbon::yesterday('Asia/Jakarta')->toDateString();
        $statusPayload = [
            'status' => 'COMPLETED',
            'business_date' => $yesterdayWib,
            'scope' => 'ALL_OUTLETS',
            'completed_at' => Carbon::now('Asia/Jakarta')->toIso8601String(),
            'created_records' => 10,
            'updated_records' => 0,
            'items_count' => 25,
            'unresolved_count' => 0,
        ];
        Cache::put("kaching:scheduler:daily_sales_summary_status:{$yesterdayWib}", $statusPayload, 3600);
        Cache::put('kaching:scheduler:daily_sales_summary_status', $statusPayload, 3600);

        // Evaluate filters attached via ->when()
        $this->assertTrue($forecastEvent->filtersPass($this->app), 'Forecast harus lolos jika token status valid untuk seluruh outlet pada tanggal kemarin.');
    }

    /**
     * Test 5: Forecast 'when' filter MEMBLOKIR eksekusi jika scope agregasi adalah FILTERED (bukan ALL_OUTLETS).
     */
    public function test_forecast_when_filter_blocks_execution_when_summary_token_is_filtered_scope(): void
    {
        $forecastEvents = $this->getEventsForCommand('kaching:forecast-demand');
        $forecastEvent = $forecastEvents[0];

        $yesterdayWib = Carbon::yesterday('Asia/Jakarta')->toDateString();
        $statusPayload = [
            'status' => 'COMPLETED',
            'business_date' => $yesterdayWib,
            'scope' => 'FILTERED', // Partial / single outlet run
            'target_outlet_id' => 1,
            'completed_at' => Carbon::now('Asia/Jakarta')->toIso8601String(),
            'created_records' => 5,
        ];
        Cache::put("kaching:scheduler:daily_sales_summary_status:{$yesterdayWib}", $statusPayload, 3600);
        Cache::put('kaching:scheduler:daily_sales_summary_status', $statusPayload, 3600);

        $this->assertFalse($forecastEvent->filtersPass($this->app), 'Forecast harus diblokir jika agregasi hanya mencakup single outlet / filtered scope.');
    }

    /**
     * Test 6: Forecast 'when' filter MEMBLOKIR eksekusi jika status agregasi FAILED.
     */
    public function test_forecast_when_filter_blocks_execution_when_summary_status_is_failed(): void
    {
        $forecastEvents = $this->getEventsForCommand('kaching:forecast-demand');
        $forecastEvent = $forecastEvents[0];

        $yesterdayWib = Carbon::yesterday('Asia/Jakarta')->toDateString();
        $statusPayload = [
            'status' => 'FAILED',
            'business_date' => $yesterdayWib,
            'scope' => 'ALL_OUTLETS',
            'error' => 'Database connection timeout',
            'completed_at' => Carbon::now('Asia/Jakarta')->toIso8601String(),
        ];
        Cache::put("kaching:scheduler:daily_sales_summary_status:{$yesterdayWib}", $statusPayload, 3600);
        Cache::put('kaching:scheduler:daily_sales_summary_status', $statusPayload, 3600);

        $this->assertFalse($forecastEvent->filtersPass($this->app), 'Forecast harus diblokir jika status agregasi FAILED.');
    }

    /**
     * Test 7: Forecast 'when' filter MEMBLOKIR eksekusi jika tanggal bisnis token sudah kedaluwarsa / beda tanggal.
     */
    public function test_forecast_when_filter_blocks_execution_when_summary_token_has_stale_date(): void
    {
        $forecastEvents = $this->getEventsForCommand('kaching:forecast-demand');
        $forecastEvent = $forecastEvents[0];

        $twoDaysAgoWib = Carbon::yesterday('Asia/Jakarta')->subDay()->toDateString();
        $statusPayload = [
            'status' => 'COMPLETED',
            'business_date' => $twoDaysAgoWib,
            'scope' => 'ALL_OUTLETS',
            'completed_at' => Carbon::now('Asia/Jakarta')->toIso8601String(),
        ];
        Cache::put('kaching:scheduler:daily_sales_summary_status', $statusPayload, 3600);

        $this->assertFalse($forecastEvent->filtersPass($this->app), 'Forecast harus diblokir jika token status adalah untuk tanggal yang usang.');
    }

    /**
     * Test 8: Forecast 'when' filter MEMBLOKIR eksekusi MESKIPUN ada 1 baris summary di database jika token tidak ada (Eliminasi Risiko Fallback exists()).
     */
    public function test_forecast_when_filter_blocks_execution_even_if_single_summary_row_exists_in_database(): void
    {
        $forecastEvents = $this->getEventsForCommand('kaching:forecast-demand');
        $forecastEvent = $forecastEvents[0];

        Cache::forget('kaching:scheduler:daily_sales_summary_status');

        $user = User::factory()->create();
        $outlet = Outlet::create([
            'user_id' => $user->id,
            'name' => 'Outlet Cabang 1',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);
        $menu = Menu::create([
            'user_id' => $user->id,
            'outlet_id' => $outlet->id,
            'name' => 'Kopi Tubruk',
            'category' => 'Minuman',
            'price' => 10000,
            'cost_price' => 4000,
            'unit' => 'cup',
            'is_available' => true,
        ]);

        $yesterdayWib = Carbon::yesterday('Asia/Jakarta')->toDateString();

        // Buat 1 baris summary parsial di DB
        DailyMenuSalesSummary::create([
            'user_id' => $user->id,
            'outlet_id' => $outlet->id,
            'menu_id' => $menu->id,
            'business_date' => $yesterdayWib,
            'uom' => 'cup',
            'total_qty' => 10.0,
            'total_revenue' => 100000.0,
            'total_cost' => 40000.0,
            'transaction_count' => 3,
        ]);

        // Karena token cache belum ada / tidak membuktikan ALL_OUTLETS, guard harus FAIL-CLOSED (false)
        $this->assertFalse($forecastEvent->filtersPass($this->app), 'Forecast HARUS diblokir jika token ALL_OUTLETS tidak ada, walaupun ada summary baris parsial di database.');
    }

    /**
     * Test 9: SummarizeDailySalesCommand menghasilkan token ALL_OUTLETS saat dijalankan penuh.
     */
    public function test_summarize_command_creates_all_outlets_token_on_full_scope_run(): void
    {
        Cache::flush();
        $yesterdayWib = Carbon::yesterday('Asia/Jakarta')->toDateString();

        $exitCode = Artisan::call('kaching:summarize-daily-sales');
        $this->assertEquals(Command::SUCCESS, $exitCode);

        $status = Cache::get('kaching:scheduler:daily_sales_summary_status');
        $this->assertIsArray($status);
        $this->assertEquals('COMPLETED', $status['status']);
        $this->assertEquals($yesterdayWib, $status['business_date']);
        $this->assertEquals('ALL_OUTLETS', $status['scope']);
        $this->assertNotEmpty($status['completed_at']);
    }

    /**
     * Test 10: SummarizeDailySalesCommand menghasilkan token FILTERED saat dijalankan dengan opsi --outlet.
     */
    public function test_summarize_command_creates_filtered_token_when_scoped_to_outlet(): void
    {
        Cache::flush();
        $yesterdayWib = Carbon::yesterday('Asia/Jakarta')->toDateString();

        $exitCode = Artisan::call('kaching:summarize-daily-sales', ['--outlet' => 99]);
        $this->assertEquals(Command::SUCCESS, $exitCode);

        $status = Cache::get('kaching:scheduler:daily_sales_summary_status');
        $this->assertIsArray($status);
        $this->assertEquals('COMPLETED', $status['status']);
        $this->assertEquals('FILTERED', $status['scope']);
        $this->assertEquals(99, $status['target_outlet_id']);
    }

    /**
     * Test 11: SummarizeDailySalesCommand menangani hari tanpa transaksi secara valid (0 records, COMPLETED, ALL_OUTLETS).
     */
    public function test_summarize_command_handles_zero_transactions_day_validly(): void
    {
        Cache::flush();
        $yesterdayWib = Carbon::yesterday('Asia/Jakarta')->toDateString();

        // Pastikan tidak ada transaksi
        \App\Models\Transaction::whereBetween('created_at', [
            Carbon::yesterday('Asia/Jakarta')->startOfDay()->setTimezone('UTC'),
            Carbon::yesterday('Asia/Jakarta')->endOfDay()->setTimezone('UTC'),
        ])->delete();

        $exitCode = Artisan::call('kaching:summarize-daily-sales');
        $this->assertEquals(Command::SUCCESS, $exitCode);

        $status = Cache::get('kaching:scheduler:daily_sales_summary_status');
        $this->assertIsArray($status);
        $this->assertEquals('COMPLETED', $status['status']);
        $this->assertEquals('ALL_OUTLETS', $status['scope']);
        $this->assertEquals(0, $status['created_records']);
        $this->assertEquals(0, $status['items_count']);
    }

    /**
     * Test 12: Command-level lock contention pada kaching:summarize-daily-sales.
     */
    public function test_summarize_command_lock_contention_and_release(): void
    {
        $lockKey = 'kaching:lock:summarize-daily-sales';
        $lock = Cache::lock($lockKey, 60);

        // Acquire lock di proses luar
        $this->assertTrue($lock->get(), 'Lock simulasi harus berhasil diperoleh.');

        // Jalankan command saat lock sedang dipegang
        $exitCode = Artisan::call('kaching:summarize-daily-sales');
        $this->assertEquals(Command::FAILURE, $exitCode, 'Command harus gagal (FAILURE) jika lock sedang dipegang proses lain.');

        // Lepas lock
        $lock->release();

        // Jalankan ulang command setelah lock dilepas
        $exitCodeAfterRelease = Artisan::call('kaching:summarize-daily-sales');
        $this->assertEquals(Command::SUCCESS, $exitCodeAfterRelease, 'Command harus berhasil setelah lock dilepas.');
    }

    /**
     * Test 13: Command-level lock contention pada kaching:forecast-demand.
     */
    public function test_forecast_command_lock_contention_and_release(): void
    {
        $lockKey = 'kaching:lock:forecast-demand';
        $lock = Cache::lock($lockKey, 60);

        // Acquire lock di proses luar
        $this->assertTrue($lock->get(), 'Lock simulasi forecast harus berhasil diperoleh.');

        // Jalankan command saat lock sedang dipegang
        $exitCode = Artisan::call('kaching:forecast-demand', ['--dry-run' => true]);
        $this->assertEquals(Command::FAILURE, $exitCode, 'Forecast command harus gagal jika lock sedang dipegang.');

        // Lepas lock
        $lock->release();

        // Pastikan setelah release lock tidak lagi terkunci
        $newLock = Cache::lock($lockKey, 10);
        $this->assertTrue($newLock->get(), 'Lock harus dapat diperoleh kembali setelah dilepas.');
        $newLock->release();
    }

    /**
     * Test 14: Lock kaching:summarize-daily-sales tetap dilepas via finally block saat terjadi exception.
     */
    public function test_summarize_command_releases_lock_on_exception(): void
    {
        $lockKey = 'kaching:lock:summarize-daily-sales';

        // Simulasikan pemanggilan dengan parameter tanggal yang memicu error / parsing gagal
        try {
            Artisan::call('kaching:summarize-daily-sales', ['--date' => 'invalid-date-format']);
        } catch (\Throwable $e) {
            // Expected
        }

        // Pastikan lock tetap bebas (bisa di-acquire kembali)
        $newLock = Cache::lock($lockKey, 10);
        $this->assertTrue($newLock->get(), 'Lock harus dilepas dan dapat diperoleh kembali setelah exception.');
        $newLock->release();
    }

    /**
     * Test 15: Lock kaching:forecast-demand tetap dilepas via finally block saat terjadi exception.
     */
    public function test_forecast_command_releases_lock_on_exception(): void
    {
        $lockKey = 'kaching:lock:forecast-demand';

        // Simulasikan pemanggilan dengan parameter date invalid yang memicu exit failure
        $exitCode = Artisan::call('kaching:forecast-demand', ['--date' => 'invalid-date']);
        $this->assertEquals(Command::FAILURE, $exitCode);

        // Pastikan lock tetap bebas (bisa di-acquire kembali)
        $newLock = Cache::lock($lockKey, 10);
        $this->assertTrue($newLock->get(), 'Lock harus dilepas dan dapat diperoleh kembali setelah early return / error.');
        $newLock->release();
    }

    /**
     * Test 16: Forecast 'when' filter MEMBLOKIR eksekusi jika kill switch DEMAND_FORECASTING_ENABLED=false (TASK-08.4).
     */
    public function test_16_forecast_when_filter_blocks_execution_when_kill_switch_is_disabled(): void
    {
        config(['services.demand_forecast.enabled' => false]);

        $forecastEvents = $this->getEventsForCommand('kaching:forecast-demand');
        $forecastEvent = $forecastEvents[0];

        $yesterdayWib = Carbon::yesterday('Asia/Jakarta')->toDateString();
        $statusPayload = [
            'status' => 'COMPLETED',
            'business_date' => $yesterdayWib,
            'scope' => 'ALL_OUTLETS',
            'completed_at' => Carbon::now('Asia/Jakarta')->toIso8601String(),
            'created_records' => 10,
            'updated_records' => 0,
            'items_count' => 25,
            'unresolved_count' => 0,
        ];
        Cache::put("kaching:scheduler:daily_sales_summary_status:{$yesterdayWib}", $statusPayload, 3600);
        Cache::put('kaching:scheduler:daily_sales_summary_status', $statusPayload, 3600);

        // Even though summary token is valid, scheduler MUST block execution because kill switch is disabled
        $this->assertFalse($forecastEvent->filtersPass($this->app), 'Forecast HARUS diblokir oleh scheduler jika DEMAND_FORECASTING_ENABLED=false.');
    }
}
