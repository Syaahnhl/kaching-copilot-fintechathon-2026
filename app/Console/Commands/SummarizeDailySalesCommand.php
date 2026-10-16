<?php

namespace App\Console\Commands;

use App\Models\DailyMenuSalesSummary;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SummarizeDailySalesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kaching:summarize-daily-sales
                            {--date= : Tanggal bisnis spesifik (YYYY-MM-DD, default: kemarin)}
                            {--start-date= : Tanggal awal rentang (YYYY-MM-DD)}
                            {--end-date= : Tanggal akhir rentang (YYYY-MM-DD)}
                            {--outlet= : Filter ID outlet tertentu}
                            {--user= : Filter ID tenant/user tertentu}
                            {--force : Paksa kalkulasi ulang data summary yang sudah ada}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Melakukan agregasi penjualan harian (daily sales summaries) per menu, outlet, tanggal bisnis (WIB), dan UOM secara idempotent untuk fondasi demand forecasting';

    /**
     * List status transaksi yang secara tegas dieksklusikan dari kalkulasi permintaan.
     */
    protected array $ineligibleStatuses = [
        'batal',
        'cancelled',
        'canceled',
        'refund',
        'refunded',
        'pending',
        'pending payment',
        'void',
        'draft',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $lock = Cache::lock('kaching:lock:summarize-daily-sales', 7200);

        if (! $lock->get()) {
            $this->warn("⚠️  [LOCKED] Command kaching:summarize-daily-sales sedang berjalan di proses lain.");
            $this->info("   Eksekusi dibatalkan untuk mencegah konflik konkurensi (command-level lock active).");
            return Command::FAILURE;
        }

        $outletId = $this->option('outlet') ? (int) $this->option('outlet') : null;
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $scope = ($outletId !== null || $userId !== null) ? 'FILTERED' : 'ALL_OUTLETS';
        $currentBusinessDate = null;

        try {
            $this->info("==================================================================");
            $this->info("   KACHING NATIVE FORECAST — DAILY SALES SUMMARY AGGREGATOR       ");
            $this->info("==================================================================");

            $dates = $this->resolveDateRange();

            if (empty($dates)) {
                $this->warn("⚠️ Tidak ada rentang tanggal valid yang diproses.");
                return Command::SUCCESS;
            }

            $totalDays = count($dates);
            $this->line("📅 Memproses {$totalDays} hari bisnis (Timezone: Asia/Jakarta)...");

            $totalSummariesCreated = 0;
            $totalSummariesUpdated = 0;
            $totalItemsAggregated = 0;
            $totalUnresolvedItems = 0;

            foreach ($dates as $dateIndex => $businessDate) {
                $currentBusinessDate = $businessDate;
                $this->line("\n[ " . ($dateIndex + 1) . "/{$totalDays} ] Memproses tanggal: <comment>{$businessDate}</comment>");

                $stats = $this->aggregateForDate($businessDate, $outletId, $userId);

                $totalSummariesCreated += $stats['created'];
                $totalSummariesUpdated += $stats['updated'];
                $totalItemsAggregated += $stats['items_count'];
                $totalUnresolvedItems += $stats['unresolved_count'];

                $this->line("  ✓ Ringkasan: {$stats['created']} dibuat, {$stats['updated']} diperbarui | Items: {$stats['items_count']} | Unresolved: {$stats['unresolved_count']}");

                // Simpan structured completion token untuk dependency guard downstream (TASK-07.2 SSOT)
                $statusPayload = [
                    'status' => 'COMPLETED',
                    'business_date' => $businessDate,
                    'scope' => $scope,
                    'target_outlet_id' => $outletId,
                    'target_user_id' => $userId,
                    'completed_at' => Carbon::now('Asia/Jakarta')->toIso8601String(),
                    'created_records' => $stats['created'],
                    'updated_records' => $stats['updated'],
                    'items_count' => $stats['items_count'],
                    'unresolved_count' => $stats['unresolved_count'],
                ];
                Cache::put("kaching:scheduler:daily_sales_summary_status:{$businessDate}", $statusPayload, Carbon::now('Asia/Jakarta')->addHours(24));
                Cache::put('kaching:scheduler:daily_sales_summary_status', $statusPayload, Carbon::now('Asia/Jakarta')->addHours(24));
            }

            $this->info("\n==================================================================");
            $this->info("✅ Agregasi Penjualan Harian Selesai Berhasil!");
            $this->table(
                ['Metrik', 'Nilai'],
                [
                    ['Total Hari Bisnis Diproses', $totalDays],
                    ['Summary Dibuat (Baru)', $totalSummariesCreated],
                    ['Summary Diperbarui (Existing)', $totalSummariesUpdated],
                    ['Total Item Transaksi Teragregasi', $totalItemsAggregated],
                    ['Total Item Unresolved', $totalUnresolvedItems],
                    ['Scope Agregasi', $scope],
                ]
            );

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $failedDate = $currentBusinessDate ?? Carbon::yesterday('Asia/Jakarta')->toDateString();
            $errorPayload = [
                'status' => 'FAILED',
                'business_date' => $failedDate,
                'scope' => $scope,
                'target_outlet_id' => $outletId,
                'target_user_id' => $userId,
                'error' => $e->getMessage(),
                'completed_at' => Carbon::now('Asia/Jakarta')->toIso8601String(),
            ];
            Cache::put("kaching:scheduler:daily_sales_summary_status:{$failedDate}", $errorPayload, Carbon::now('Asia/Jakarta')->addHours(24));
            Cache::put('kaching:scheduler:daily_sales_summary_status', $errorPayload, Carbon::now('Asia/Jakarta')->addHours(24));

            $this->error("❌ Terjadi kesalahan fatal saat agregasi: " . $e->getMessage());
            Log::error('[SummarizeDailySalesCommand] Fatal error during aggregation', [
                'business_date' => $failedDate,
                'scope' => $scope,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Resolve range tanggal yang akan diproses.
     */
    protected function resolveDateRange(): array
    {
        $singleDate = $this->option('date');
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');

        if ($singleDate) {
            return [$singleDate];
        }

        if ($startDate && $endDate) {
            $period = CarbonPeriod::create($startDate, $endDate);
            $dates = [];
            foreach ($period as $dt) {
                $dates[] = $dt->format('Y-m-d');
            }
            return $dates;
        }

        if ($startDate && !$endDate) {
            return [$startDate];
        }

        // Default: Kemarin (Asia/Jakarta)
        return [Carbon::yesterday('Asia/Jakarta')->format('Y-m-d')];
    }

    /**
     * Lakukan agregasi transaksi untuk 1 tanggal bisnis tertentu.
     */
    protected function aggregateForDate(string $businessDate, ?int $targetOutletId, ?int $targetUserId): array
    {
        // 1. Definisikan batas awal dan akhir hari bisnis dalam WIB (Asia/Jakarta)
        $startWib = Carbon::createFromFormat('Y-m-d H:i:s', $businessDate . ' 00:00:00', 'Asia/Jakarta');
        $endWib = Carbon::createFromFormat('Y-m-d H:i:s', $businessDate . ' 23:59:59', 'Asia/Jakarta');

        // 2. Konversikan batas WIB ke rentang waktu database server UTC
        $startUtc = $startWib->copy()->setTimezone('UTC')->toDateTimeString();
        $endUtc = $endWib->copy()->setTimezone('UTC')->toDateTimeString();

        // 3. Query transaksi yang berada di rentang UTC tersebut
        $query = Transaction::query()
            ->with(['items'])
            ->whereBetween('created_at', [$startUtc, $endUtc]);

        if ($targetOutletId) {
            $query->where('outlet_id', $targetOutletId);
        }

        if ($targetUserId) {
            $query->where('user_id', $targetUserId);
        }

        // Bucket akumulator agregasi dalam memori per grain
        // Grain: user_id + outlet_id + menu_id + business_date + uom
        $grainBuckets = [];
        $unresolvedCount = 0;
        $totalItemsCount = 0;

        // Menu master cache untuk menghindari query N+1 menu saat fallback mapping
        $menuCache = [];

        $query->chunk(200, function ($transactions) use (
            &$grainBuckets,
            &$unresolvedCount,
            &$totalItemsCount,
            &$menuCache,
            $businessDate
        ) {
            foreach ($transactions as $tx) {
                // Evaluasi status transaksi header
                if (!$this->isTransactionEligible($tx)) {
                    continue;
                }

                $txCreatedAt = Carbon::parse($tx->created_at)->setTimezone('Asia/Jakarta');

                foreach ($tx->items as $item) {
                    // Evaluasi status item
                    if (!$this->isItemEligible($item)) {
                        continue;
                    }

                    $totalItemsCount++;

                    // Resolusi identitas menu
                    $resolvedMenu = $this->resolveMenuForItem($item, $tx, $menuCache);

                    if (!$resolvedMenu) {
                        $unresolvedCount++;
                        continue;
                    }

                    $menuId = (int) $resolvedMenu->id;
                    $userId = (int) ($tx->user_id ?: $resolvedMenu->user_id);
                    $outletId = (int) ($tx->outlet_id ?: $resolvedMenu->outlet_id);
                    $uom = trim($resolvedMenu->unit ?: 'Porsi');

                    $grainKey = "{$userId}_{$outletId}_{$menuId}_{$businessDate}_{$uom}";

                    $itemPrice = (float) ($item->price ?? $resolvedMenu->price ?? 0.0);
                    $itemQty = (float) $item->qty;
                    $itemRevenue = $itemQty * $itemPrice;

                    // Evaluasi Cost dan Provenance
                    $itemCostPrice = 0.0;
                    $provenance = 'FALLBACK_MENU_MASTER';

                    if (isset($item->cost_price) && $item->cost_price !== null && (float) $item->cost_price > 0) {
                        $itemCostPrice = (float) $item->cost_price;
                        $provenance = 'ACTUAL_SNAPSHOT';
                    } elseif (isset($resolvedMenu->cost_price) && (float) $resolvedMenu->cost_price > 0) {
                        $itemCostPrice = (float) $resolvedMenu->cost_price;
                        $provenance = 'FALLBACK_MENU_MASTER';
                    } else {
                        $itemCostPrice = 0.0;
                        $provenance = 'ZERO_ESTIMATE';
                    }

                    $itemCost = $itemQty * $itemCostPrice;

                    if (!isset($grainBuckets[$grainKey])) {
                        $grainBuckets[$grainKey] = [
                            'user_id' => $userId,
                            'outlet_id' => $outletId,
                            'menu_id' => $menuId,
                            'business_date' => $businessDate,
                            'uom' => $uom,
                            'total_qty' => 0.0,
                            'total_revenue' => 0.0,
                            'total_cost' => 0.0,
                            'transaction_ids' => [],
                            'first_transaction_time' => $txCreatedAt->copy(),
                            'last_transaction_time' => $txCreatedAt->copy(),
                            'unresolved_items_count' => 0,
                            'cost_provenance' => $provenance,
                        ];
                    }

                    $bucket = &$grainBuckets[$grainKey];
                    $bucket['total_qty'] += $itemQty;
                    $bucket['total_revenue'] += $itemRevenue;
                    $bucket['total_cost'] += $itemCost;
                    $bucket['transaction_ids'][$tx->id] = true;

                    if ($txCreatedAt->lt($bucket['first_transaction_time'])) {
                        $bucket['first_transaction_time'] = $txCreatedAt->copy();
                    }
                    if ($txCreatedAt->gt($bucket['last_transaction_time'])) {
                        $bucket['last_transaction_time'] = $txCreatedAt->copy();
                    }

                    // Jika ada satu saja item dengan snapshot aktual, utamakan provenance ACTUAL_SNAPSHOT
                    if ($provenance === 'ACTUAL_SNAPSHOT') {
                        $bucket['cost_provenance'] = 'ACTUAL_SNAPSHOT';
                    }
                }
            }
        });

        // 4. Lakukan Upsert Idempotent ke tabel daily_menu_sales_summaries
        $createdCount = 0;
        $updatedCount = 0;

        foreach ($grainBuckets as $bucket) {
            $totalQty = (float) $bucket['total_qty'];
            $totalRevenue = (float) $bucket['total_revenue'];
            $totalCost = (float) $bucket['total_cost'];
            $txCount = count($bucket['transaction_ids']);

            $avgPrice = $totalQty > 0 ? round($totalRevenue / $totalQty, 2) : 0.0;
            $avgCost = $totalQty > 0 ? round($totalCost / $totalQty, 2) : 0.0;

            $existing = DailyMenuSalesSummary::where('user_id', $bucket['user_id'])
                ->where('outlet_id', $bucket['outlet_id'])
                ->where('menu_id', $bucket['menu_id'])
                ->where('business_date', $bucket['business_date'])
                ->where('uom', $bucket['uom'])
                ->first();

            $payload = [
                'total_qty' => round($totalQty, 3),
                'total_revenue' => round($totalRevenue, 2),
                'total_cost' => round($totalCost, 2),
                'transaction_count' => $txCount,
                'avg_price' => $avgPrice,
                'avg_cost' => $avgCost,
                'first_transaction_time' => $bucket['first_transaction_time']->setTimezone('UTC')->toDateTimeString(),
                'last_transaction_time' => $bucket['last_transaction_time']->setTimezone('UTC')->toDateTimeString(),
                'unresolved_items_count' => $bucket['unresolved_items_count'],
                'cost_provenance' => $bucket['cost_provenance'],
            ];

            if ($existing) {
                $existing->update($payload);
                $updatedCount++;
            } else {
                DailyMenuSalesSummary::create(array_merge([
                    'user_id' => $bucket['user_id'],
                    'outlet_id' => $bucket['outlet_id'],
                    'menu_id' => $bucket['menu_id'],
                    'business_date' => $bucket['business_date'],
                    'uom' => $bucket['uom'],
                ], $payload));
                $createdCount++;
            }
        }

        return [
            'created' => $createdCount,
            'updated' => $updatedCount,
            'items_count' => $totalItemsCount,
            'unresolved_count' => $unresolvedCount,
        ];
    }

    /**
     * Memeriksa apakah status transaksi sah untuk agregasi permintaan.
     */
    protected function isTransactionEligible(Transaction $tx): bool
    {
        $status = strtolower(trim((string) $tx->status));

        // Eksklusikan status void/batal/refund/pending
        if (in_array($status, $this->ineligibleStatuses, true)) {
            return false;
        }

        // Cek soft delete jika ada
        if (isset($tx->deleted_at) && $tx->deleted_at !== null) {
            return false;
        }

        return true;
    }

    /**
     * Memeriksa apakah item transaksi sah.
     */
    protected function isItemEligible(TransactionItem $item): bool
    {
        $status = strtolower(trim((string) ($item->status ?? '')));

        if (in_array($status, $this->ineligibleStatuses, true)) {
            return false;
        }

        $qty = (float) ($item->qty ?? 0);
        if ($qty <= 0) {
            return false;
        }

        return true;
    }

    /**
     * Resolusi Menu Entity dari TransactionItem dengan fallback terstruktur.
     */
    protected function resolveMenuForItem(TransactionItem $item, Transaction $tx, array &$menuCache): ?Menu
    {
        // 1. Coba resolusi langsung via menu_id
        if (!empty($item->menu_id)) {
            $menuId = (int) $item->menu_id;
            if (isset($menuCache[$menuId])) {
                return $menuCache[$menuId];
            }

            $menu = Menu::withTrashed()->find($menuId);
            if ($menu) {
                $menuCache[$menuId] = $menu;
                return $menu;
            }
        }

        // 2. Fallback multi-tier: cocokkan berdasarkan nama menu + tenant user_id
        $menuName = trim((string) ($item->menu_name ?? ''));
        if ($menuName === '') {
            return null;
        }

        $cacheKey = "name_" . ($tx->user_id ?? 0) . "_" . strtolower($menuName);
        if (isset($menuCache[$cacheKey])) {
            return $menuCache[$cacheKey];
        }

        // Cari di menu milik tenant bersangkutan
        $menuByName = Menu::withTrashed()
            ->where('user_id', $tx->user_id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($menuName)])
            ->first();

        if ($menuByName) {
            $menuCache[$cacheKey] = $menuByName;
            $menuCache[(int) $menuByName->id] = $menuByName;
            return $menuByName;
        }

        return null;
    }
}
