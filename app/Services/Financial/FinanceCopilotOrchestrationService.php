<?php

namespace App\Services\Financial;

use App\Services\Policy\ActionPolicyService;
use App\Services\Supplier\SupplierRestockMessageService;
use App\Models\User;
use App\Models\Outlet;
use App\Helpers\TimezoneContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Copilot Intelligence Orchestration Service (Phase 3.8)
 *
 * Coordinates and routes user queries to deterministic intelligence tools
 * across CONTROL, PREDICT, ACT, and PREPARE pillars.
 *
 * GOVERNANCE RULES:
 * 1. AI is PROPOSER, not EXECUTOR. Direct database mutations are strictly forbidden.
 * 2. Every factual number originates from deterministic underlying services.
 * 3. Every output fulfills the Explainability Contract:
 *    [WHAT, WHY, CALCULATION, EVIDENCE, LIMITATIONS, SOURCE, TIME_PERIOD].
 * 4. All actions route through ActionPolicyService with PIN/Role approval firewall.
 */
class FinanceCopilotOrchestrationService
{
    public function __construct(
        protected FinancialCalculationService $calcService,
        protected FinancialWarningService $warningService,
        protected CashFlowForecastService $cashFlowService,
        protected MenuDemandForecastService $demandService,
        protected StockoutIntelligenceService $stockoutService,
        protected MaterialPriceIntelligenceService $priceService,
        protected ExternalMarketSignalService $marketSignalService,
        protected FinancialScenarioService $scenarioService,
        protected FinancialActionRecommendationService $actionService,
        protected MenuMarginActionBridgeService $marginBridgeService,
        protected FinancingReadinessService $financingService,
        protected FinancialEvidenceService $evidenceService,
        protected ActionPolicyService $policyService,
        protected ?FinancialPerformanceIntelligenceService $performanceService = null,
        protected ?SmartRestockOrchestratorService $restockService = null,
        protected ?SupplierRestockMessageService $restockMessageService = null,
    ) {
        $this->performanceService = $performanceService ?? app(FinancialPerformanceIntelligenceService::class);
        $this->restockService = $restockService ?? app(SmartRestockOrchestratorService::class);
        $this->restockMessageService = $restockMessageService ?? (class_exists(SupplierRestockMessageService::class) ? app(SupplierRestockMessageService::class) : null);
    }

    /**
     * Get list of all available deterministic tools with their schemas.
     */
    public function getRegisteredTools(): array
    {
        return [
            'get_today_sales' => [
                'pillar'      => 'CONTROL',
                'description' => 'Mengecek total omzet dan transaksi hari ini milik tenant aktif.',
                'parameters'  => ['outlet_id' => 'int|null'],
            ],
            'get_sales_summary' => [
                'pillar'      => 'CONTROL',
                'description' => 'Mengecek total omzet dan jumlah transaksi penjualan pada periode tertentu (today, yesterday, this_week, this_month, last_month, atau rentang tanggal custom).',
                'parameters'  => [
                    'outlet_id'  => 'int|null',
                    'period'     => 'string|null',
                    'start_date' => 'string|null',
                    'end_date'   => 'string|null',
                ],
            ],
            'get_top_selling_products' => [
                'pillar'      => 'CONTROL',
                'description' => 'Mengambil daftar produk/menu terlaris berdasarkan kuantitas penjualan, omzet, atau laba pada periode tertentu.',
                'parameters'  => [
                    'outlet_id'  => 'int|null',
                    'period'     => 'string|null',
                    'start_date' => 'string|null',
                    'end_date'   => 'string|null',
                    'limit'      => 'int|null',
                    'sort_by'    => 'string|null',
                ],
            ],
            'get_suppliers' => [
                'pillar'      => 'CONTROL',
                'description' => 'Mengambil daftar vendor/supplier bahan baku aktif milik tenant terautentikasi.',
                'parameters'  => [
                    'outlet_id' => 'int|null',
                    'query'     => 'string|null',
                    'limit'     => 'int|null',
                ],
            ],
            'get_financial_summary' => [
                'pillar'      => 'CONTROL',
                'description' => 'Mengambil ringkasan kesehatan finansial (pendapatan, HPP, beban, laba kotor, laba bersih).',
                'parameters'  => ['outlet_id' => 'int|null', 'days' => 'int|null'],
            ],
            'get_risk_radar' => [
                'pillar'      => 'CONTROL',
                'description' => 'Mengecek peringatan risiko keuangan aktif (penurunan omzet, lonjakan beban, kompresi margin).',
                'parameters'  => ['outlet_id' => 'int|null'],
            ],
            'get_cash_flow_forecast' => [
                'pillar'      => 'PREDICT',
                'description' => 'Mengambil proyeksi arus kas operasional 7 hari ke depan (DOW median + WMA).',
                'parameters'  => ['outlet_id' => 'int|null', 'anchor_date' => 'string|null'],
            ],
            'get_demand_forecast' => [
                'pillar'      => 'PREDICT',
                'description' => 'Mengambil proyeksi permintaan menu deterministik (DOW median, payday multiplier).',
                'parameters'  => ['menu_id' => 'int|null', 'outlet_id' => 'int|null', 'anchor_date' => 'string|null'],
            ],
            'get_stock_risk' => [
                'pillar'      => 'PREDICT',
                'description' => 'Mengecek risiko kehabisan stok bahan baku via BOM recipe run rate dan lead time.',
                'parameters'  => ['menu_id' => 'int|null', 'outlet_id' => 'int|null'],
            ],
            'get_material_price_forecast' => [
                'pillar'      => 'PREDICT',
                'description' => 'Mengecek tren harga bahan baku (fail-closed jika riwayat pembelian tidak tersedia).',
                'parameters'  => ['item_id' => 'int', 'outlet_id' => 'int|null'],
            ],
            'get_financial_scenario' => [
                'pillar'      => 'PREDICT',
                'description' => 'Menjalankan simulasi what-if finansial (kenaikan harga, HPP, omzet, efisiensi beban).',
                'parameters'  => ['scenario_type' => 'string', 'price_change_percent' => 'float|null', 'cost_change_percent' => 'float|null'],
            ],
            'get_financial_action_recommendations' => [
                'pillar'      => 'ACT',
                'description' => 'Mengambil rekomendasi tindakan finansial prioritas berbasis anomali.',
                'parameters'  => ['outlet_id' => 'int|null'],
            ],
            'get_menu_margin_candidates' => [
                'pillar'      => 'ACT',
                'description' => 'Mengambil kandidat menu ber-margin tipis untuk ditinjau penyesuaian harga.',
                'parameters'  => ['outlet_id' => 'int|null'],
            ],
            'prepare_action_proposal' => [
                'pillar'      => 'ACT',
                'description' => 'Menyusun proposal tindakan bisnis melalui firewall kebijakan ActionPolicyService.',
                'parameters'  => ['action' => 'string', 'parameters' => 'array'],
            ],
            'get_financing_readiness' => [
                'pillar'      => 'PREPARE',
                'description' => 'Mengecek kesiapan dossier bukti finansial untuk pengajuan pendanaan/pinjaman.',
                'parameters'  => ['outlet_id' => 'int|null'],
            ],
            'get_financial_evidence' => [
                'pillar'      => 'PREPARE',
                'description' => 'Mengambil paket bukti operasional finansial (dossier audit-proof) untuk pihak ketiga.',
                'parameters'  => ['outlet_id' => 'int|null'],
            ],
            'get_market_signals' => [
                'pillar'      => 'PREDICT',
                'description' => 'Mengambil sinyal pasar eksternal (hari libur nasional, long weekend, siklus gajian).',
                'parameters'  => ['target_date' => 'string|null'],
            ],
            'get_financial_performance_intelligence' => [
                'pillar'      => 'CONTROL',
                'description' => 'Mengambil analisis performa finansial menu deterministik berbasis matriks kecepatan penjualan (velocity), margin kontribusi estimasi, dan sinyal anomali operasional.',
                'parameters'  => [
                    'outlet_id'               => 'int|null',
                    'anchor_date'             => 'string|null',
                    'observation_window_days' => 'int|null',
                    'menu_id'                 => 'int|null',
                    'performance_state'       => 'string|null',
                    'limit'                   => 'int|null',
                ],
            ],
            'get_restock_recommendations' => [
                'pillar'      => 'ACT',
                'description' => 'Mengambil rekomendasi restock bahan baku kritis, pencocokan supplier terdaftar, estimasi biaya pembelian, evaluasi kecukupan kas operasional, dan tautan draf pesanan WhatsApp (wa.me).',
                'parameters'  => [
                    'outlet_id'    => 'int|null',
                    'horizon_days' => 'int|null',
                    'limit'        => 'int|null',
                ],
            ],
            'generate_supplier_restock_message' => [
                'pillar'      => 'ACT',
                'description' => 'Menyusun draf pesan restock supplier terstruktur dan tautan WhatsApp (wa.me) langsung untuk konfirmasi pesanan tanpa membuat PO otomatis.',
                'parameters'  => [
                    'supplier_id'   => 'int|null',
                    'supplier_name' => 'string|null',
                    'items'         => 'array',
                    'delivery_date' => 'string|null',
                ],
            ],
        ];
    }

    /**
     * Dispatch a deterministic tool execution.
     * Enforces tenant isolation, input validation, and explainability wrapping.
     */
    public function dispatchTool(string $toolName, ?int $userId = null, ?int $outletId = null, array $arguments = []): array
    {
        if ($userId === null || $userId <= 0) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNAUTHORIZED_USER',
                'message' => 'Pengguna tidak terautentikasi.',
                'data'    => null,
            ];
        }

        $startT = microtime(true);

        switch ($toolName) {
            case 'get_sales_summary':
            case 'get_today_sales':
                $period = $arguments['period'] ?? ($toolName === 'get_today_sales' ? 'today' : null);
                $startDate = $arguments['start_date'] ?? null;
                $endDate = $arguments['end_date'] ?? null;

                $range = $this->resolvePeriodRange($period, $startDate, $endDate);
                $resolvedStart = $range['start_date'];
                $resolvedEnd = $range['end_date'];
                $label = $range['label'];

                $data = $this->calcService->calculateRevenue($userId, $outletId, $resolvedStart, $resolvedEnd);
                $revenueVal = (float) ($data['value'] ?? 0.0);
                $recordsCount = (int) ($data['records_count'] ?? 0);
                $formattedOmzet = 'Rp ' . number_format($revenueVal, 0, ',', '.');
                $isEn = $this->isEnglishLocale();

                if ($toolName === 'get_today_sales' || $range['period'] === 'today') {
                    $rawMessage = $isEn
                        ? "Information for AI: Today's revenue is {$formattedOmzet} across {$recordsCount} transactions."
                        : "Informasi untuk AI: Omzet hari ini bernilai {$formattedOmzet} dengan {$recordsCount} transaksi.";
                } else {
                    $rawMessage = $isEn
                        ? "Information for AI: Revenue for period {$label} ({$resolvedStart} to {$resolvedEnd}) is {$formattedOmzet} across {$recordsCount} transactions."
                        : "Informasi untuk AI: Omzet periode {$label} ({$resolvedStart} s/d {$resolvedEnd}) bernilai {$formattedOmzet} dengan {$recordsCount} transaksi.";
                }

                $salesEvidence = $this->evidenceService->wrapSales(
                    $revenueVal,
                    $resolvedStart,
                    'Asia/Jakarta'
                );

                return $this->wrapExplainability([
                    'what'        => $isEn
                        ? "Total revenue and non-cancelled sales transactions for {$label}."
                        : "Total omzet dan transaksi penjualan non-batal periode {$label}.",
                    'why'         => $isEn
                        ? 'Aggregation of non-cancelled transactions per date range in business tenant timezone (Asia/Jakarta to UTC range).'
                        : 'Agregasi transaksi non-batal per rentang tanggal di timezone bisnis tenant (Asia/Jakarta ke UTC range).',
                    'calculation' => $isEn
                        ? 'SUM(total_amount) of completed/non-cancelled transactions within selected date range.'
                        : 'SUM(total_amount) dari transaksi berstatus Selesai/non-batal dalam rentang waktu terpilih.',
                    'evidence'    => $isEn
                        ? "Transactions recorded in database for tenant ID {$userId}."
                        : "Transaksi tercatat di database tenant ID {$userId}.",
                    'limitations' => $isEn
                        ? 'Only includes transactions synced to server and not Void/Cancelled/Refunded.'
                        : 'Hanya mencakup transaksi yang sudah tersinkronisasi ke server dan tidak berstatus Batal/Cancelled/Refund.',
                    'source'      => 'FinancialCalculationService::calculateRevenue',
                    'time_period' => $isEn ? "{$resolvedStart} to {$resolvedEnd} ({$label})" : "{$resolvedStart} s/d {$resolvedEnd} ({$label})",
                    'result'      => [
                        'period'          => $range['period'],
                        'label'           => $label,
                        'start_date'      => $resolvedStart,
                        'end_date'        => $resolvedEnd,
                        'revenue'         => $revenueVal,
                        'records_count'   => $recordsCount,
                        'formatted_sales' => $formattedOmzet,
                        'message'         => $rawMessage,
                        'evidence'        => $salesEvidence,
                        'value'           => $revenueVal, // Backward compatibility
                        'amount'          => $revenueVal, // Backward compatibility
                        'date'            => $resolvedStart, // Backward compatibility
                    ],
                ]);

            case 'get_top_selling_products':
                $result = $this->computeTopSellingProducts($userId, $outletId, $arguments);
                $isEn = $this->isEnglishLocale();
                return $this->wrapExplainability([
                    'what'        => $isEn
                        ? "List of top {$result['limit']} selling products by {$result['sort_by']} for period {$result['label']}."
                        : "Daftar {$result['limit']} produk terlaris berdasarkan {$result['sort_by']} untuk periode {$result['label']}.",
                    'why'         => $isEn
                        ? 'Identify products contributing highest sales volume and revenue.'
                        : 'Mengidentifikasi produk dengan kontribusi volume penjualan dan pendapatan tertinggi.',
                    'calculation' => $isEn
                        ? "Aggregation of SUM(ti.qty) and SUM(ti.subtotal) of non-cancelled transaction items sorted by {$result['sort_by']}."
                        : "Agregasi SUM(ti.qty) dan SUM(ti.subtotal) dari item transaksi non-batal yang diurutkan berdasarkan {$result['sort_by']}.",
                    'evidence'    => $isEn
                        ? "Sales history of transaction items for tenant ID {$userId} between {$result['start_date']} and {$result['end_date']}."
                        : "Riwayat penjualan item transaksi tenant ID {$userId} pada rentang {$result['start_date']} s/d {$result['end_date']}.",
                    'limitations' => $isEn
                        ? 'Only includes items from synced, non-cancelled, non-refunded transactions.'
                        : 'Hanya mencakup item pada transaksi yang tersinkronisasi dan tidak dibatalkan/direfund.',
                    'source'      => 'FinanceCopilotOrchestrationService::computeTopSellingProducts',
                    'time_period' => $isEn ? "{$result['start_date']} to {$result['end_date']} ({$result['label']})" : "{$result['start_date']} s/d {$result['end_date']} ({$result['label']})",
                    'result'      => $result,
                ]);

            case 'get_suppliers':
                $result = $this->fetchSuppliers($userId, $outletId, $arguments);
                $isEn = $this->isEnglishLocale();
                return $this->wrapExplainability([
                    'what'        => $isEn ? 'List of active procurement vendors/suppliers for tenant.' : 'Daftar vendor/supplier pengadaan aktif milik tenant.',
                    'why'         => $isEn ? 'Lookup contact info, payment terms, and supplier catalog.' : 'Lookup informasi kontak, syarat pembayaran, dan katalog bahan baku pemasok.',
                    'calculation' => $isEn ? 'Relational query on suppliers table isolated by tenant (user_id).' : 'Query relasional tabel suppliers terisolasi tenant (user_id).',
                    'evidence'    => $isEn ? "Registered vendor data for tenant ID {$userId}." : "Data vendor terdaftar milik tenant ID {$userId}.",
                    'limitations' => $isEn ? 'Only displays active non-deleted (soft-deleted) vendor records.' : 'Hanya menampilkan data vendor milik tenant aktif yang tidak dihapus (soft-deleted).',
                    'source'      => 'FinanceCopilotOrchestrationService::fetchSuppliers',
                    'time_period' => $isEn ? 'Current Master Data' : 'Data Master Terkini',
                    'result'      => $result,
                ]);

            case 'get_financial_summary':
                $endDate = Carbon::now('Asia/Jakarta')->format('Y-m-d');
                $startDate = Carbon::now('Asia/Jakarta')->subDays(30)->format('Y-m-d');
                $data = $this->calcService->computeSnapshot($userId, $outletId, $startDate, $endDate);
                $isEn = $this->isEnglishLocale();
                return $this->wrapExplainability([
                    'what'        => $isEn
                        ? 'Operational financial summary (Revenue, COGS, Expenses, Gross Profit, Net Profit, Cash Runway).'
                        : 'Ringkasan finansial operasional (Omzet, HPP, Beban, Laba Kotor, Laba Bersih, Runway Kas).',
                    'why'         => $isEn
                        ? 'Comprehensive financial performance evaluation over the last 30 days.'
                        : 'Evaluasi menyeluruh performa keuangan bisnis 30 hari terakhir.',
                    'calculation' => $isEn
                        ? 'Gross Profit = Revenue - COGS; Net Profit = Gross Profit - Operating Expenses.'
                        : 'Laba Kotor = Omzet - HPP; Laba Bersih = Laba Kotor - Beban Operasional.',
                    'evidence'    => $isEn ? 'Transaction and cashier ledger snapshot.' : 'Snapshot ledger transaksi dan kasir.',
                    'limitations' => $isEn ? 'COGS computed based on menu/item cost_price at transaction time.' : 'HPP dihitung berbasis cost_price menu/item saat transaksi dibuat.',
                    'source'      => 'FinancialCalculationService::computeFinancialSnapshot',
                    'time_period' => $isEn ? 'Last 30 Days' : '30 Hari Terakhir',
                    'result'      => $data,
                ]);

            case 'get_risk_radar':
                $warnings = $this->warningService->evaluateAllWarnings($userId, $outletId);
                $isEn = $this->isEnglishLocale();
                return $this->wrapExplainability([
                    'what'        => $isEn ? 'Financial risk radar early warning status.' : 'Status peringatan dini radar risiko finansial.',
                    'why'         => $isEn ? 'Monitoring revenue drop anomalies, expense spikes, and cash leaks.' : 'Pemantauan anomali penurunan omzet, lonjakan pengeluaran, dan kebocoran kas.',
                    'calculation' => $isEn ? 'Deviation threshold evaluation against 30-day median baseline.' : 'Evaluasi ambang deviasi terhadap baseline median 30 hari.',
                    'evidence'    => $warnings['evidence'] ?? [],
                    'limitations' => $isEn ? 'Requires minimum 14 days of activity for full evaluation.' : 'Membutuhkan minimal 14 hari aktivitas untuk evaluasi penuh.',
                    'source'      => 'FinancialWarningService::evaluateAllWarnings',
                    'time_period' => 'Rolling 30-Day Window',
                    'result'      => $warnings,
                ]);

            case 'get_cash_flow_forecast':
                $anchorDate = $arguments['anchor_date'] ?? null;
                $forecast = $this->cashFlowService->forecast($userId, $outletId, $anchorDate);
                $isEn = $this->isEnglishLocale();
                return $this->wrapExplainability([
                    'what'        => $isEn ? 'Projected cash inflows and outflows for the next 7 days.' : 'Proyeksi arus kas masuk dan keluar 7 hari ke depan.',
                    'why'         => $isEn ? 'Deterministic DOW Median model with WMA-14 fallback and payroll adjustment.' : 'Model deterministik DOW Median dengan WMA-14 fallback dan penyesuaian gajian.',
                    'calculation' => $isEn ? 'Median sales per day of week multiplied by empirical payroll factor.' : 'Median penjualan per hari dalam sepekan dikalikan faktor gajian empiris.',
                    'evidence'    => $isEn ? 'Historical 28-60 days cashier ledger vectors.' : 'Vektor historis 28-60 hari ledger kasir.',
                    'limitations' => $isEn ? 'Deterministic projection assumes stable patterns and does not predict external shocks.' : 'Proyeksi deterministik mengasumsikan pola operasional stabil dan tidak memprediksi guncangan eksternal.',
                    'source'      => 'CashFlowForecastService::forecast',
                    'time_period' => $isEn ? 'Next 7 Days' : '7 Hari ke Depan',
                    'result'      => $forecast,
                ]);

            case 'get_demand_forecast':
                $menuId = isset($arguments['menu_id']) ? (int) $arguments['menu_id'] : null;
                $anchorDate = $arguments['anchor_date'] ?? null;

                if ($menuId !== null) {
                    $demand = $this->demandService->forecastMenu($userId, $menuId, $outletId, $anchorDate);
                } else {
                    $demand = $this->demandService->forecastAllMenus($userId, $outletId, $anchorDate);
                }
                $isEn = $this->isEnglishLocale();

                return $this->wrapExplainability([
                    'what'        => $isEn ? 'Deterministic menu portion demand projection.' : 'Proyeksi permintaan porsi menu deterministik.',
                    'why'         => $isEn ? 'Menu-specific DOW median pattern with integer rounding and stock caps.' : 'Pola DOW median menu spesifik dengan pembulatan integer dan batas stok.',
                    'calculation' => 'DOW Median(28d) * Payday Multiplier; fallback WMA-14 jika observasi < 2.',
                    'evidence'    => $isEn ? 'Point-in-time transaction item ledger free of future data leaks.' : 'Ledger item transaksi point-in-time bebas kebocoran masa depan.',
                    'limitations' => $isEn ? 'Past demand does not guarantee future sales.' : 'Permintaan masa lalu tidak menjamin penjualan masa depan.',
                    'source'      => 'MenuDemandForecastService',
                    'time_period' => $isEn ? 'Next 7 Days' : '7 Hari ke Depan',
                    'result'      => $demand,
                ]);

            case 'get_stock_risk':
                $menuId = isset($arguments['menu_id']) ? (int) $arguments['menu_id'] : null;
                if ($menuId !== null) {
                    $stockRisk = $this->stockoutService->evaluateMenuStockRisk($userId, $menuId, $outletId);
                } else {
                    $stockRisk = $this->stockoutService->evaluateAllMenusStockRisk($userId, $outletId);
                }

                $isEn = $this->isEnglishLocale();
                return $this->wrapExplainability([
                    'what'        => $isEn ? 'Stockout risk analysis for raw materials or finished goods.' : 'Analisis risiko kehabisan stok bahan baku atau barang jadi.',
                    'why'         => $isEn ? 'Run-rate calculation of BOM recipe consumption against remaining stock and lead time.' : 'Perhitungan run rate konsumsi resep BOM terhadap sisa stok dan lead time pasokan.',
                    'calculation' => $isEn ? 'Days Remaining = Available Stock / (Daily Menu Demand * Recipe per Portion).' : 'Hari Sisa = Stok Tersedia / (Permintaan Menu Harian * Resep per Porsi).',
                    'evidence'    => $isEn ? 'Stock mutation ledger of inbound/outbound goods and BOM recipes.' : 'Ledger mutasi stok barang masuk/keluar dan resep BOM.',
                    'limitations' => $isEn ? 'Menus without BOM recipes or untracked stock will be strictly UNSUPPORTED (fail-closed).' : 'Menu tanpa resep BOM atau stok tak terlacak akan berstatus UNSUPPORTED (fail-closed).',
                    'source'      => 'StockoutIntelligenceService',
                    'time_period' => 'Lead Time Horizon',
                    'result'      => $stockRisk,
                ]);

            case 'get_material_price_forecast':
                $itemId = (int) ($arguments['item_id'] ?? 0);
                $priceTrend = $this->priceService->evaluateItemPriceTrend($userId, $itemId, $outletId);
                $isEn = $this->isEnglishLocale();

                return $this->wrapExplainability([
                    'what'        => $isEn ? 'Raw material purchase price trend analysis.' : 'Analisis tren harga pembelian bahan mentah.',
                    'why'         => $isEn ? 'Deterministic linear regression of inbound purchase invoice price time series.' : 'Regresi linier deterministik deret waktu harga invoice pembelian barang masuk.',
                    'calculation' => $isEn ? 'Least-Squares daily slope; classification UP (>+2%), DOWN (<-2%), STABLE.' : 'Slope Least-Squares per hari; klasifikasi UP (>+2%), DOWN (<-2%), STABLE.',
                    'evidence'    => $isEn ? 'Historical purchase invoices of raw materials.' : 'Faktur pembelian historis bahan baku.',
                    'limitations' => $isEn ? 'If historical invoice table is missing, status is strictly PRICE_HISTORY_UNAVAILABLE.' : 'Jika tabel invoice historis belum ada, status strictly PRICE_HISTORY_UNAVAILABLE.',
                    'source'      => 'MaterialPriceIntelligenceService',
                    'time_period' => 'Historical Invoices',
                    'result'      => $priceTrend,
                ]);

            case 'get_financial_scenario':
                $scenarioInput = $arguments;
                if (!isset($scenarioInput['scenario_type'])) {
                    $scenarioInput['scenario_type'] = 'PRICE_CHANGE';
                }
                $simResult = $this->scenarioService->simulate($userId, $outletId, $scenarioInput);
                $isEn = $this->isEnglishLocale();

                return $this->wrapExplainability([
                    'what'        => $isEn ? 'What-if financial scenario simulation of price, cost, or revenue changes.' : 'Simulasi skenario what-if dampak perubahan harga, biaya, atau omzet.',
                    'why'         => $isEn ? 'Helping business owners evaluate financial decisions before execution.' : 'Membantu pemilik bisnis menguji keputusan finansial sebelum dieksekusi.',
                    'calculation' => $isEn ? 'Application of percentage/nominal delta on 30-day baseline P&L model.' : 'Penerapan delta persentase/nominal terhadap model P&L baseline 30 hari.',
                    'evidence'    => $isEn ? '30-day baseline transactions and tenant expenses.' : 'Baseline 30 hari transaksi dan beban tenant.',
                    'limitations' => $isEn ? 'Simulation assumes demand elasticity ceteris paribus.' : 'Simulasi mengasumsikan elastisitas permintaan ceteris paribus.',
                    'source'      => 'FinancialScenarioService::simulate',
                    'time_period' => $isEn ? '30-Day Projection' : 'Proyeksi 30 Hari',
                    'result'      => $simResult,
                ]);

            case 'get_financial_action_recommendations':
                $recs = $this->actionService->generateRecommendations($userId, $outletId);
                $isEn = $this->isEnglishLocale();

                return $this->wrapExplainability([
                    'what'        => $isEn ? 'Prioritized financial mitigation action recommendations.' : 'Rekomendasi tindakan mitigasi finansial terprioritas.',
                    'why'         => $isEn ? 'Correlation of risk anomalies with margin recovery and efficiency SOPs.' : 'Korelasi anomali risiko dengan SOP perbaikan margin dan efisiensi.',
                    'calculation' => $isEn ? 'Urgency scoring based on active risk early warning severity.' : 'Scoring urgensi berbasis severity peringatan dini risiko aktif.',
                    'evidence'    => $isEn ? 'Active financial risk radar.' : 'Radar risiko finansial aktif.',
                    'limitations' => $isEn ? 'Recommendations are advisory; all executions require Owner approval.' : 'Rekomendasi bersifat saran; seluruh eksekusi membutuhkan persetujuan Owner.',
                    'source'      => 'FinancialActionRecommendationService::generateRecommendations',
                    'time_period' => $isEn ? 'Today' : 'Hari Ini',
                    'result'      => $recs,
                ]);

            case 'get_menu_margin_candidates':
                $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 10;
                $candidates = $this->marginBridgeService->getDiagnosticCandidates($userId, $outletId, $limit);
                $isEn = $this->isEnglishLocale();

                return $this->wrapExplainability([
                    'what'        => $isEn ? 'List of slim margin menu items candidate for price adjustment.' : 'Daftar menu dengan margin tipis yang menjadi kandidat penyesuaian harga.',
                    'why'         => $isEn ? 'Helping business owners restore compressed gross profit margins.' : 'Membantu pemilik bisnis memulihkan margin laba kotor yang tertekan.',
                    'calculation' => $isEn ? 'Margin = (Price - Cost) / Price * 100%. Sorted ascending by margin.' : 'Margin = (Harga Jual - HPP) / Harga Jual * 100%. Diurutkan dari margin terkecil.',
                    'evidence'    => $isEn ? 'Active menu catalog and COGS.' : 'Katalog menu dan biaya HPP aktif.',
                    'limitations' => $isEn ? 'Menus without COGS marked as incomplete data.' : 'Menu tanpa HPP ditandai sebagai data tidak lengkap.',
                    'source'      => 'MenuMarginActionBridgeService::getDiagnosticCandidates',
                    'time_period' => $isEn ? 'Active Catalog' : 'Katalog Aktif',
                    'result'      => $candidates,
                ]);

            case 'prepare_action_proposal':
                // Action Policy Firewall Integration
                $actionName = $arguments['action'] ?? '';
                $actionParams = $arguments['parameters'] ?? [];

                $proposal = $this->policyService->evaluateProposal(
                    $userId,
                    $outletId,
                    $actionName,
                    $actionParams
                );
                $isEn = $this->isEnglishLocale();

                return $this->wrapExplainability([
                    'what'        => $isEn ? 'Business action proposal and policy firewall evaluation.' : 'Proposal tindakan bisnis dan evaluasi firewall kebijakan.',
                    'why'         => $isEn ? 'Ensuring all mutation actions pass human approval and security validation.' : 'Memastikan seluruh tindakan mutasi melalui persetujuan manusia dan validasi keamanan.',
                    'calculation' => $isEn ? 'Action whitelist evaluation, role verification, PIN authorization, and reversibility check.' : 'Evaluasi whitelist aksi, pengecekan role, otorisasi PIN, dan reversibilitas.',
                    'evidence'    => 'ActionPolicyService Whitelist & Security Invariants.',
                    'limitations' => $isEn ? 'AI acts as PROPOSER only. Execution strictly requires PIN verification.' : 'AI bertindak sebagai PROPOSER saja. Eksekusi mutlak membutuhkan verifikasi PIN.',
                    'source'      => 'ActionPolicyService::evaluateProposal',
                    'time_period' => $isEn ? '15-Minute TTL' : 'TTL 15 Menit',
                    'result'      => $proposal,
                ]);

            case 'get_financing_readiness':
                $readiness = $this->financingService->calculate($userId, $outletId);
                $isEn = $this->isEnglishLocale();
                return $this->wrapExplainability([
                    'what'        => $isEn ? 'Evaluation of financial evidence dossier readiness for credit/financing application.' : 'Evaluasi kesiapan dossier bukti finansial untuk pengajuan kredit/pembiayaan.',
                    'why'         => $isEn ? 'Helping merchants prepare a transparent operational track record.' : 'Membantu UMKM menyiapkan rekam jejak operasional yang transparan.',
                    'calculation' => $isEn ? 'Cash record integrity verification, COGS completeness, shift consistency, and profit ratio.' : 'Verifikasi integritas catatan kas, kelengkapan HPP, konsistensi shift, dan rasio laba.',
                    'evidence'    => 'Rekam jejak operasional dan laporan audit POS.',
                    'limitations' => $isEn
                        ? 'Kaching does NOT provide loans (Kaching TIDAK memberikan pinjaman), does not evaluate credit scores, and does not guarantee financial institution approval.'
                        : 'Kaching TIDAK memberikan pinjaman, tidak menilai credit score, dan tidak menjamin persetujuan lembaga keuangan.',
                    'source'      => 'FinancingReadinessService::assessReadiness',
                    'time_period' => $isEn ? 'Historical Operational Records' : 'Catatan Operasional Historis',
                    'result'      => $readiness,
                ]);

            case 'get_financial_evidence':
                // Fail-closed: FinancialEvidenceService is an evidence envelope transformer per module,
                // not a standalone dossier aggregator. PDF dossier export is handled by KachingIntelligenceController::exportDossier().
                $isEn = $this->isEnglishLocale();
                return $this->wrapExplainability([
                    'what'        => $isEn ? 'Financial operational evidence dossier.' : 'Dossier bukti operasional finansial.',
                    'why'         => $isEn ? 'Verification of financial operational evidence for third parties.' : 'Verifikasi bukti operasional finansial untuk pihak ketiga.',
                    'calculation' => 'FAIL_CLOSED: Tool get_financial_evidence memerlukan penentuan sumber bukti spesifik atau ekspor via KachingIntelligenceController::exportDossier.',
                    'evidence'    => [],
                    'limitations' => 'FinancialEvidenceService berfungsi sebagai transformer envelope bukti per modul, bukan agregator dossier mandiri.',
                    'source'      => 'FinancialEvidenceService',
                    'time_period' => $isEn ? 'Monthly Report' : 'Laporan Bulanan',
                    'result'      => [
                        'status'  => 'UNSUPPORTED_DIRECT_AGGREGATION',
                        'code'    => 'REQUIRES_CONTRACT_SPECIFICATION',
                        'message' => 'FinancialEvidenceService berfungsi sebagai transformer envelope bukti per modul. Gunakan ekspor dossier PDF atau panggil tool bukti spesifik (get_risk_radar, get_cash_flow_forecast, get_financial_scenario, dsb).',
                    ],
                ]);

            case 'get_market_signals':
                $targetDate = $arguments['target_date'] ?? Carbon::now('Asia/Jakarta')->format('Y-m-d');
                $signals = $this->marketSignalService->getSignalsForDate($targetDate);
                $isEn = $this->isEnglishLocale();
                return $this->wrapExplainability([
                    'what'        => $isEn ? 'External market signals (holidays, long weekends, payroll periods).' : 'Sinyal pasar eksternal (hari libur, long weekend, periode gajian).',
                    'why'         => $isEn ? 'Additional context explaining consumer demand fluctuations.' : 'Konteks tambahan penjelas fluktuasi permintaan konsumen.',
                    'calculation' => $isEn ? 'Official Indonesian national holidays and business calendar.' : 'Kalender resmi libur nasional Indonesia dan kalender bisnis.',
                    'evidence'    => 'Kalender Resmi Indonesia 2026.',
                    'limitations' => $isEn ? 'External data is strictly passive information (DATA ONLY) and cannot execute actions.' : 'Data eksternal hanya bersifat informasi pasif (DATA ONLY) dan tidak dapat mengeksekusi aksi.',
                    'source'      => 'ExternalMarketSignalService',
                    'time_period' => $targetDate,
                    'result'      => $signals,
                ]);

            case 'get_financial_performance_intelligence':
                $anchorDate = isset($arguments['anchor_date']) && is_string($arguments['anchor_date']) && trim($arguments['anchor_date']) !== ''
                    ? trim($arguments['anchor_date'])
                    : null;

                $days = isset($arguments['observation_window_days']) && is_numeric($arguments['observation_window_days'])
                    ? (int) $arguments['observation_window_days']
                    : (isset($arguments['days']) && is_numeric($arguments['days']) ? (int) $arguments['days'] : 90);

                $days = max(7, min(365, $days));

                $filterMenuId = isset($arguments['menu_id']) && is_numeric($arguments['menu_id']) && (int) $arguments['menu_id'] > 0
                    ? (int) $arguments['menu_id']
                    : null;

                $filterState = isset($arguments['performance_state']) && is_string($arguments['performance_state']) && trim($arguments['performance_state']) !== ''
                    ? strtoupper(trim($arguments['performance_state']))
                    : null;

                if ($filterState === 'INSUFFICIENT_MARGIN_DATA') {
                    $filterState = 'INSUFFICIENT_MARGIN_EVIDENCE';
                }

                $limit = isset($arguments['limit']) && is_numeric($arguments['limit'])
                    ? max(1, min(100, (int) $arguments['limit']))
                    : 20;

                $analysis = $this->performanceService->analyze(
                    $userId,
                    $outletId,
                    $anchorDate,
                    $days
                );

                $allRecords = $analysis['menus'] ?? [];
                $filteredRecords = [];

                foreach ($allRecords as $mId => $record) {
                    $mIdInt = (int) $mId;

                    if ($filterMenuId !== null && $mIdInt !== $filterMenuId) {
                        continue;
                    }

                    if ($filterState !== null) {
                        $recordState = (string) ($record['performance_state'] ?? '');
                        if ($recordState === 'INSUFFICIENT_MARGIN_DATA') {
                            $recordState = 'INSUFFICIENT_MARGIN_EVIDENCE';
                        }
                        if ($recordState !== $filterState) {
                            continue;
                        }
                    }

                    $filteredRecords[] = $record;
                }

                $pagedRecords = array_slice($filteredRecords, 0, $limit);

                $status = empty($allRecords) ? 'INSUFFICIENT_EVIDENCE' : 'SUCCESS';

                $summaryData = array_merge($analysis['summary'] ?? [], [
                    'gross_revenue'                 => (float) ($analysis['summary']['financial_overview']['total_gross_revenue'] ?? 0.0),
                    'covered_gross_revenue'         => (float) ($analysis['summary']['financial_overview']['covered_gross_revenue'] ?? 0.0),
                    'revenue_cost_coverage_pct'     => (float) ($analysis['summary']['financial_overview']['revenue_cost_coverage_pct'] ?? 0.0),
                    'estimated_cogs'                => (float) ($analysis['summary']['financial_overview']['estimated_cogs'] ?? 0.0),
                    'estimated_contribution_margin' => (float) ($analysis['summary']['financial_overview']['estimated_contribution_margin'] ?? 0.0),
                    'estimated_margin_pct'          => (float) ($analysis['summary']['financial_overview']['estimated_margin_pct'] ?? 0.0),
                ]);

                $toolPayload = [
                    'status'                  => $status,
                    'source'                  => 'FinancialPerformanceIntelligenceService',
                    'anchor_date'             => $analysis['metadata']['anchor_date'] ?? $anchorDate,
                    'observation_window_days' => $days,
                    'filter_applied'          => [
                        'menu_id'           => $filterMenuId,
                        'performance_state' => $filterState,
                        'limit'             => $limit,
                        'matched_count'     => count($filteredRecords),
                    ],
                    'summary'                 => $summaryData,
                    'state_distribution'      => $analysis['state_distribution'] ?? [],
                    'items'                   => array_values($pagedRecords),
                    'unresolved_items'        => $analysis['unresolved_items'] ?? ['count' => 0, 'total_revenue' => 0.0],
                    'anomaly_overview'        => $analysis['anomaly_overview'] ?? [],
                    'limitations'             => $analysis['limitations'] ?? [],
                ];
                $isEn = $this->isEnglishLocale();

                return $this->wrapExplainability([
                    'what'        => $isEn
                        ? 'Multi-dimensional menu financial performance analysis (sales velocity, estimated contribution margin, and operational anomalies).'
                        : 'Analisis performa finansial menu multi-dimensi (kecepatan penjualan, estimasi margin kontribusi, dan anomali operasional).',
                    'why'         => $isEn
                        ? 'Menu portfolio evaluation to identify star products, margin risks, and performance drops.'
                        : 'Evaluasi portofolio menu untuk mengidentifikasi produk unggulan, berisiko margin tipis, dan penurunan performa.',
                    'calculation' => $isEn
                        ? 'Deterministic fusion of MenuSalesVelocityService (velocity), ContributionMarginService (COGS snapshot), and SalesAnomalyService (quantity deviation).'
                        : 'Fusi deterministik MenuSalesVelocityService (velocity), ContributionMarginService (HPP snapshot), dan SalesAnomalyService (deviasi kuantitas).',
                    'evidence'    => empty($allRecords)
                        ? ($isEn ? 'Menu catalog is empty for this tenant/outlet.' : 'Katalog menu kosong untuk tenant/outlet ini.')
                        : ($isEn
                            ? "POS transaction history and catalog COGS snapshot (" . ($analysis['summary']['active_selling_menus'] ?? 0) . " active menus, " . ($analysis['summary']['financial_overview']['revenue_cost_coverage_pct'] ?? 0) . "% cost coverage)."
                            : "Riwayat transaksi POS dan snapshot HPP katalog (" . ($analysis['summary']['active_selling_menus'] ?? 0) . " menu aktif, " . ($analysis['summary']['financial_overview']['revenue_cost_coverage_pct'] ?? 0) . "% cost coverage)."),
                    'limitations' => $isEn
                        ? 'Margin estimation assumes master COGS snapshot due to absence of dynamic purchasing ledger; NO_SALES is not physical dead stock; anomaly signals are not proof of cashier fraud.'
                        : 'Estimasi margin mengasumsikan snapshot HPP master karena ketiadaan ledger pembelian historis dinamis; NO_SALES bukan status dead stock fisik; sinyal anomali bukan bukti fraud kasir.',
                    'source'      => 'FinancialPerformanceIntelligenceService',
                    'time_period' => $isEn
                        ? "Window of {$days} days up to " . ($analysis['metadata']['anchor_date'] ?? 'N/A')
                        : "Jendela {$days} hari sampai " . ($analysis['metadata']['anchor_date'] ?? 'N/A'),
                    'result'      => $toolPayload,
                ]);

            case 'get_restock_recommendations':
                $targetOutletId = isset($arguments['outlet_id']) ? (int) $arguments['outlet_id'] : $outletId;
                $options = [
                    'horizon_days' => isset($arguments['horizon_days']) ? (int) $arguments['horizon_days'] : 7,
                    'limit'        => isset($arguments['limit']) ? (int) $arguments['limit'] : 20,
                ];

                $res = $this->restockService->getRestockRecommendations($userId, $targetOutletId, $options);
                $isEn = $this->isEnglishLocale();

                return $this->wrapExplainability([
                    'what'        => $isEn
                        ? 'Critical raw material restock recommendations, supplier matching, estimated purchase cost, and WhatsApp order draft links (wa.me).'
                        : 'Rekomendasi restock bahan baku kritis, pencocokan supplier, estimasi biaya pembelian, dan tautan draf pesanan WhatsApp (wa.me).',
                    'why'         => $res['message'] ?? ($isEn ? 'Evaluation of raw material restock requirements.' : 'Evaluasi kebutuhan restock bahan baku.'),
                    'calculation' => $isEn
                        ? 'Restock requirement calculated from safe stock target minus current available stock, matched with connected supplier list.'
                        : 'Kebutuhan restock dihitung dari target stok aman (estimasi kebutuhan penjualan dan stok pengaman atau batas minimum) dikurangi stok yang tersedia saat ini, disesuaikan dengan daftar pemasok terhubung.',
                    'evidence'    => [
                        'user_id'      => $userId,
                        'outlet_id'    => $targetOutletId,
                        'horizon_days' => $options['horizon_days'],
                        'items_count'  => count($res['recommendations'] ?? []),
                    ],
                    'limitations' => $isEn
                        ? 'WhatsApp draft messages and estimated costs are confirmation aids; not automated Purchase Orders (PO) and do not mutate cash.'
                        : 'Draf pesan WhatsApp dan estimasi biaya adalah alat bantu konfirmasi; bukan Purchase Order (PO) otomatis dan tidak melakukan mutasi kas.',
                    'source'      => 'Sistem Manajemen Restock Kaching POS',
                    'time_period' => $isEn ? sprintf('%d days ahead', $options['horizon_days']) : sprintf('%d hari ke depan', $options['horizon_days']),
                    'result'      => $res,
                ]);

            case 'generate_supplier_restock_message':
                $targetOutletId = isset($arguments['outlet_id']) ? (int) $arguments['outlet_id'] : $outletId;
                $supplierId = isset($arguments['supplier_id']) ? (int) $arguments['supplier_id'] : 0;
                $supplierName = isset($arguments['supplier_name']) ? trim((string) $arguments['supplier_name']) : '';
                $items = (array) ($arguments['items'] ?? []);
                $deliveryDate = isset($arguments['delivery_date']) ? trim((string) $arguments['delivery_date']) : null;
                if ($deliveryDate === '') {
                    $deliveryDate = null;
                }

                if ($supplierId <= 0 && $supplierName !== '') {
                    $foundSup = \App\Models\Supplier::where('user_id', $userId)
                        ->where(function ($q) use ($supplierName) {
                            $q->where('name', $supplierName)
                              ->orWhere('name', 'like', '%' . $supplierName . '%');
                        })->first();
                    if ($foundSup) {
                        $supplierId = (int) $foundSup->id;
                    }
                }

                $messageService = $this->getRestockMessageService();
                $res = $messageService->generateForSupplier($userId, $supplierId, $items, $deliveryDate);
                $isEn = $this->isEnglishLocale();

                if (!empty($res['success'])) {
                    $supName = $res['supplier']['name'] ?? ($supplierName ?: 'Supplier');
                    $cleanPhone = $res['supplier']['clean_phone'] ?? '-';
                    return $this->wrapExplainability([
                        'what'        => $isEn
                            ? "Draft restock message and WhatsApp direct link for {$supName}."
                            : "Draf pesan restock dan tautan langsung WhatsApp untuk {$supName}.",
                        'why'         => $isEn
                            ? "Prepares structured order draft for confirmation without automated purchase order or database mutation."
                            : "Menyiapkan draf pesanan terstruktur untuk konfirmasi tanpa pembuatan purchase order otomatis atau mutasi database.",
                        'calculation' => $isEn
                            ? "Formatted items, normalized Indonesian phone ({$cleanPhone}) into international format 62..., and generated RFC 3986 wa.me URL."
                            : "Memformat daftar bahan, menormalisasi nomor telepon ({$cleanPhone}) ke format internasional 62..., dan membuat URL wa.me berbasis RFC 3986.",
                        'evidence'    => [
                            'user_id'            => $userId,
                            'outlet_id'          => $targetOutletId,
                            'supplier_id'        => $supplierId,
                            'items_count'        => count($items),
                            'delivery_date'      => $deliveryDate,
                            'whatsapp_available' => $res['whatsapp_available'] ?? false,
                        ],
                        'limitations' => $isEn
                            ? 'Draft message and WhatsApp link are confirmation aids only; messages are never sent automatically and no stock or cash mutation occurs.'
                            : 'Draf pesan dan tautan WhatsApp hanya merupakan alat bantu konfirmasi; pesan tidak dikirim otomatis dan tidak ada mutasi stok atau kas.',
                        'source'      => 'Sistem Manajemen Supplier & Restock Kaching POS',
                        'time_period' => $isEn ? 'Immediate' : 'Saat Ini',
                        'result'      => $res,
                    ]);
                }

                return [
                    'status' => 'SUCCESS',
                    'data'   => $res,
                ];

            default:
                return [
                    'status'  => 'UNSUPPORTED_TOOL',
                    'message' => "Tool '{$toolName}' tidak dikenal dalam katalog intelligence Copilot.",
                ];
        }
    }

    /**
     * Wrap payload in standard explainability contract.
     */
    protected function wrapExplainability(array $payload): array
    {
        return [
            'status'         => 'SUCCESS',
            'explainability' => [
                'what'        => $payload['what'],
                'why'         => $payload['why'],
                'calculation' => $payload['calculation'],
                'evidence'    => $payload['evidence'],
                'limitations' => $payload['limitations'],
                'source'      => $payload['source'],
                'time_period' => $payload['time_period'],
            ],
            'governance'     => [
                'ai_role'           => 'PROPOSER_ONLY',
                'mutation_allowed'  => false,
                'approval_firewall' => 'ActionPolicyService',
            ],
            'data'           => $payload['result'],
        ];
    }

    /**
     * Cek apakah locale sesi aktif adalah Bahasa Inggris.
     */
    protected function isEnglishLocale(): bool
    {
        return strtolower(trim((string) app()->getLocale())) === 'en';
    }

    /**
     * Resolve business date range and human-readable label from period string or custom dates.
     */
    public function resolvePeriodRange(?string $period, ?string $startDate = null, ?string $endDate = null, string $timezone = 'Asia/Jakarta'): array
    {
        $now = Carbon::now($timezone);
        $normPeriod = strtolower(trim($period ?? ''));
        $isEn = $this->isEnglishLocale();

        switch ($normPeriod) {
            case 'yesterday':
                $start = $now->copy()->subDay()->toDateString();
                $end = $start;
                $label = $isEn ? 'Yesterday' : 'Kemarin';
                $resolvedPeriod = 'yesterday';
                break;

            case 'this_week':
                $start = $now->copy()->startOfWeek()->toDateString();
                $end = $now->toDateString();
                $label = $isEn ? 'This Week' : 'Minggu Ini';
                $resolvedPeriod = 'this_week';
                break;

            case 'this_month':
                $start = $now->copy()->startOfMonth()->toDateString();
                $end = $now->toDateString();
                $label = $isEn ? 'This Month' : 'Bulan Ini';
                $resolvedPeriod = 'this_month';
                break;

            case 'last_month':
                $start = $now->copy()->subMonth()->startOfMonth()->toDateString();
                $end = $now->copy()->subMonth()->endOfMonth()->toDateString();
                $label = $isEn ? 'Last Month' : 'Bulan Lalu';
                $resolvedPeriod = 'last_month';
                break;

            case 'today':
                $start = $now->toDateString();
                $end = $start;
                $label = $isEn ? 'Today' : 'Hari Ini';
                $resolvedPeriod = 'today';
                break;

            case 'custom':
            default:
                if (!empty($startDate) && !empty($endDate)) {
                    try {
                        $parsedStart = Carbon::parse($startDate, $timezone);
                        $parsedEnd = Carbon::parse($endDate, $timezone);
                        if ($parsedStart->gt($parsedEnd)) {
                            $start = $parsedEnd->toDateString();
                            $end = $parsedStart->toDateString();
                        } else {
                            $start = $parsedStart->toDateString();
                            $end = $parsedEnd->toDateString();
                        }
                        $label = $isEn ? "Range {$start} to {$end}" : "Rentang {$start} s/d {$end}";
                        $resolvedPeriod = 'custom';
                    } catch (\Throwable $e) {
                        $start = $now->toDateString();
                        $end = $start;
                        $label = $isEn ? 'Today' : 'Hari Ini';
                        $resolvedPeriod = 'today';
                    }
                } elseif (!empty($startDate)) {
                    try {
                        $start = Carbon::parse($startDate, $timezone)->toDateString();
                        $end = $start;
                        $label = $isEn ? "Date {$start}" : "Tanggal {$start}";
                        $resolvedPeriod = 'custom';
                    } catch (\Throwable $e) {
                        $start = $now->toDateString();
                        $end = $start;
                        $label = $isEn ? 'Today' : 'Hari Ini';
                        $resolvedPeriod = 'today';
                    }
                } else {
                    $start = $now->toDateString();
                    $end = $start;
                    $label = $isEn ? 'Today' : 'Hari Ini';
                    $resolvedPeriod = 'today';
                }
                break;
        }

        return [
            'start_date' => $start,
            'end_date'   => $end,
            'label'      => $label,
            'period'     => $resolvedPeriod,
        ];
    }

    /**
     * Compute top selling products ranked by quantity, revenue, or profit.
     * Enforces tenant isolation, outlet scope, UTC conversion, and cancellation filters.
     */
    public function computeTopSellingProducts(int $userId, ?int $outletId = null, array $arguments = []): array
    {
        $period = $arguments['period'] ?? null;
        $startDate = $arguments['start_date'] ?? null;
        $endDate = $arguments['end_date'] ?? null;
        $limit = max(1, min((int) ($arguments['limit'] ?? 5), 20));
        $sortBy = in_array(strtolower($arguments['sort_by'] ?? 'quantity'), ['quantity', 'revenue', 'profit'], true)
            ? strtolower($arguments['sort_by'] ?? 'quantity')
            : 'quantity';

        $range = $this->resolvePeriodRange($period, $startDate, $endDate);
        $resolvedStart = $range['start_date'];
        $resolvedEnd = $range['end_date'];
        $label = $range['label'];

        $tz = 'Asia/Jakarta';
        [$utcStart, $utcEnd] = TimezoneContract::businessDateRangeToUtcRange($resolvedStart, $resolvedEnd, $tz);

        $query = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->where('t.user_id', $userId);

        if ($outletId !== null && (int) $outletId > 0) {
            $query->where('t.outlet_id', $outletId);
        }

        if (Schema::hasColumn('transactions', 'is_training')) {
            $query->where('t.is_training', 0);
        }
        if (Schema::hasColumn('transaction_items', 'is_training')) {
            $query->where('ti.is_training', 0);
        }

        if (Schema::hasColumn('transactions', 'created_at_device')) {
            $query->where(function ($q) use ($utcStart, $utcEnd) {
                $q->where(function ($sub) use ($utcStart, $utcEnd) {
                    $sub->whereNotNull('t.created_at_device')
                        ->where('t.created_at_device', '>=', $utcStart)
                        ->where('t.created_at_device', '<', $utcEnd);
                })->orWhere(function ($sub) use ($utcStart, $utcEnd) {
                    $sub->whereNull('t.created_at_device')
                        ->where('t.created_at', '>=', $utcStart)
                        ->where('t.created_at', '<', $utcEnd);
                });
            });
        } else {
            $query->where('t.created_at', '>=', $utcStart)
                  ->where('t.created_at', '<', $utcEnd);
        }

        $query->whereNotIn('t.status', ['Batal', 'Cancelled', 'Refund']);
        if (Schema::hasColumn('transaction_items', 'status')) {
            $query->where(function ($q) {
                $q->whereNotIn('ti.status', ['Batal', 'Cancelled', 'Refund'])->orWhereNull('ti.status');
            });
        }

        $rawRows = $query->select([
            DB::raw("COALESCE(ti.menu_name, 'Produk Tanpa Nama') as menu_name"),
            DB::raw("SUM(ti.qty) as total_qty"),
            DB::raw("SUM(ti.qty * COALESCE(ti.price, 0)) as total_revenue"),
            DB::raw("SUM(ti.qty * COALESCE(ti.cost_price, 0)) as total_cost"),
        ])
        ->groupBy(DB::raw("COALESCE(ti.menu_name, 'Produk Tanpa Nama')"))
        ->get();

        $items = [];
        foreach ($rawRows as $row) {
            $qty = (float) $row->total_qty;
            $rev = (float) $row->total_revenue;
            $cost = (float) $row->total_cost;
            $profit = $rev - $cost;

            $items[] = [
                'menu_name'         => (string) $row->menu_name,
                'qty_sold'          => $qty,
                'total_revenue'     => $rev,
                'formatted_revenue' => 'Rp ' . number_format($rev, 0, ',', '.'),
                'total_cost'        => $cost,
                'total_profit'      => $profit,
                'formatted_profit'  => 'Rp ' . number_format($profit, 0, ',', '.'),
            ];
        }

        usort($items, function ($a, $b) use ($sortBy) {
            if ($sortBy === 'revenue') {
                if ($b['total_revenue'] == $a['total_revenue']) {
                    return $b['qty_sold'] <=> $a['qty_sold'];
                }
                return $b['total_revenue'] <=> $a['total_revenue'];
            }
            if ($sortBy === 'profit') {
                if ($b['total_profit'] == $a['total_profit']) {
                    return $b['qty_sold'] <=> $a['qty_sold'];
                }
                return $b['total_profit'] <=> $a['total_profit'];
            }
            // Default: quantity
            if ($b['qty_sold'] == $a['qty_sold']) {
                return $b['total_revenue'] <=> $a['total_revenue'];
            }
            return $b['qty_sold'] <=> $a['qty_sold'];
        });

        $pagedItems = array_slice($items, 0, $limit);
        foreach ($pagedItems as $idx => &$item) {
            $item['rank'] = $idx + 1;
        }
        unset($item);

        $summaryLines = [];
        foreach ($pagedItems as $item) {
            $summaryLines[] = "{$item['rank']}. {$item['menu_name']} ({$item['qty_sold']} terjual, {$item['formatted_revenue']})";
        }

        $formattedMessage = empty($pagedItems)
            ? "Tidak ada transaksi penjualan produk yang tercatat pada periode {$label} ({$resolvedStart} s/d {$resolvedEnd})."
            : "Produk terlaris periode {$label} ({$resolvedStart} s/d {$resolvedEnd}):\n" . implode("\n", $summaryLines);

        return [
            'period'      => $range['period'],
            'label'       => $label,
            'start_date'  => $resolvedStart,
            'end_date'    => $resolvedEnd,
            'sort_by'     => $sortBy,
            'limit'       => $limit,
            'items_count' => count($pagedItems),
            'items'       => $pagedItems,
            'message'     => $formattedMessage,
        ];
    }

    /**
     * Mengambil data supplier aktif milik tenant terautentikasi (user_id).
     *
     * @param int $userId
     * @param int|null $outletId
     * @param array $arguments
     * @return array
     */
    protected function fetchSuppliers(int $userId, ?int $outletId = null, array $arguments = []): array
    {
        $limit = isset($arguments['limit']) ? min(max((int) $arguments['limit'], 1), 10) : 5;
        $search = trim((string) ($arguments['query'] ?? $arguments['supplier_name'] ?? $arguments['name'] ?? $arguments['search'] ?? ''));

        $query = \App\Models\Supplier::query();
        if (\Illuminate\Support\Facades\Schema::hasColumn('suppliers', 'is_active')) {
            $query->where('suppliers.is_active', true);
        }

        // Tenant isolation: direct user_id column if present, or relational via items.outlet.user_id
        $tenantOutletIds = \App\Models\Outlet::where('user_id', $userId)->pluck('id')->toArray();
        if ($outletId !== null && in_array((int) $outletId, $tenantOutletIds, true)) {
            $effectiveOutletIds = [(int) $outletId];
        } else {
            $effectiveOutletIds = $tenantOutletIds;
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn('suppliers', 'user_id')) {
            $query->where(function ($q) use ($userId, $effectiveOutletIds) {
                $q->where('suppliers.user_id', $userId);
                if (!empty($effectiveOutletIds)) {
                    $q->orWhere(function ($oq) use ($effectiveOutletIds) {
                        $oq->whereNull('suppliers.user_id')
                           ->whereHas('items', function ($iq) use ($effectiveOutletIds) {
                               $iq->whereIn('items.outlet_id', $effectiveOutletIds);
                           });
                    });
                }
            });
        } else {
            $query->whereHas('items', function ($q) use ($effectiveOutletIds) {
                $q->whereIn('items.outlet_id', $effectiveOutletIds);
            });
        }

        // Query search filter (case-insensitive)
        if ($search !== '') {
            $lowerSearch = mb_strtolower($search);
            $query->where(function ($q) use ($search, $lowerSearch) {
                $q->where('suppliers.name', 'like', "%{$search}%")
                  ->orWhere('suppliers.contact_person', 'like', "%{$search}%")
                  ->orWhereRaw('LOWER(suppliers.name) LIKE ?', ["%{$lowerSearch}%"]);
            });
        }

        $suppliers = $query->with(['items' => function ($q) use ($effectiveOutletIds) {
            $q->select('items.id', 'items.name', 'items.sku', 'items.outlet_id');
            if (!empty($effectiveOutletIds)) {
                $q->whereIn('items.outlet_id', $effectiveOutletIds);
            }
        }])
        ->orderBy('name', 'asc')
        ->limit($limit)
        ->get();

        $itemsList = [];
        foreach ($suppliers as $s) {
            $suppliedItems = $s->items ? $s->items->map(fn($it) => [
                'id'   => (int) $it->id,
                'name' => (string) $it->name,
                'sku'  => (string) ($it->sku ?? ''),
            ])->values()->all() : [];

            $itemsList[] = [
                'id'             => (int) $s->id,
                'name'           => (string) $s->name,
                'contact_person' => (string) ($s->contact_person ?? ''),
                'phone'          => (string) ($s->phone ?? ''),
                'items_count'    => count($suppliedItems),
                'items'          => $suppliedItems,
            ];
        }

        $count = count($itemsList);
        if ($count > 0) {
            $message = $search !== ''
                ? "Ditemukan {$count} supplier yang cocok dengan kata kunci '{$search}'."
                : "Ditemukan {$count} supplier terdaftar untuk akun Anda.";
        } else {
            $message = $search !== ''
                ? "Tidak ditemukan data supplier yang cocok dengan kata kunci '{$search}'."
                : "Belum ada data supplier yang terdaftar pada akun Anda.";
        }

        return [
            'count'     => $count,
            'suppliers' => $itemsList,
            'message'   => $message,
        ];
    }

    /**
     * Resolve the SupplierRestockMessageService instance.
     */
    public function getRestockMessageService(): SupplierRestockMessageService
    {
        if ($this->restockMessageService === null) {
            $this->restockMessageService = app(SupplierRestockMessageService::class);
        }
        return $this->restockMessageService;
    }
}
