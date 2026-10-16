<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\Outlet;
use App\Helpers\TimezoneContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * FinancialInvestigationService
 *
 * Autonomous SME Financial Investigation Engine (Phase 5.16-A).
 *
 * Orchestrates multi-domain financial evidence collection and root-cause reasoning:
 * 1. Intent classification (Profit, Revenue, Expenses, Cash/Runway, Stock).
 * 2. Minimal sufficient evidence planning & deterministic computation.
 * 3. Cross-domain comparison (Current vs Previous period deltas).
 * 4. Root-cause synthesis without hallucinations.
 * 5. Strict canonical output formatting (Financial Health, Change, Root Cause, Conclusion, Evidence, Rec, Confidence).
 * 6. Tenant-safe, fail-closed on unauthorized outlet / insufficient evidence.
 */
class FinancialInvestigationService
{
    public const TYPE_PROFIT_DECLINE   = 'PROFIT_DECLINE';
    public const TYPE_REVENUE_DECLINE  = 'REVENUE_DECLINE';
    public const TYPE_EXPENSE_PRESSURE = 'EXPENSE_PRESSURE';
    public const TYPE_CASH_DRAIN       = 'CASH_DRAIN';
    public const TYPE_STOCK_IMPACT     = 'STOCK_IMPACT';

    // Aliases for polymorphic acceptance matching
    public const TYPE_SALES_DROP       = self::TYPE_REVENUE_DECLINE;
    public const TYPE_REVENUE_DROP      = self::TYPE_REVENUE_DECLINE;
    public const TYPE_EXPENSE_SURGE    = self::TYPE_EXPENSE_PRESSURE;
    public const TYPE_EXPENSE_INCREASE = self::TYPE_EXPENSE_PRESSURE;

    public const CONFIDENCE_HIGH   = 'HIGH';
    public const CONFIDENCE_MEDIUM = 'MEDIUM';
    public const CONFIDENCE_LOW    = 'LOW';

    protected FinancialCalculationService $calcService;
    protected FinancialWarningService $warningService;

    public function __construct(
        ?FinancialCalculationService $calcService = null,
        ?FinancialWarningService $warningService = null
    ) {
        $this->calcService = $calcService ?? app(FinancialCalculationService::class);
        $this->warningService = $warningService ?? app(FinancialWarningService::class);
    }

    /**
     * Mengeksekusi investigasi finansial otonom untuk tenant dan outlet terautentikasi.
     *
     * @param int $userId Authenticated tenant ID (SSOT)
     * @param int|null $outletId Validated outlet ID
     * @param string $query Pertanyaan investigasi pengguna
     * @param array $options Pilihan opsional (days, anchor_date, custom_period)
     * @return array Hasil investigasi terstruktur
     */
    public function investigate(int $userId, ?int $outletId, string $query, array $options = []): array
    {
        // 1. Tenant Authentication Guard
        if ($userId <= 0) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ];
        }

        // 2. Outlet Ownership Validation (Fail-closed)
        if ($outletId !== null) {
            $outletBelongs = Outlet::where('user_id', $userId)->where('id', $outletId)->exists();
            if (!$outletBelongs) {
                return [
                    'status'  => 'ERROR',
                    'code'    => 'UNAUTHORIZED_OUTLET',
                    'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
                ];
            }
        }

        // 3. Intent Classification
        $investigationType = $this->classifyInvestigationType($query);

        // 4. Time Window Resolution (Default: 30 days current vs 30 days previous)
        $windowDays = isset($options['days']) && is_numeric($options['days']) ? (int) $options['days'] : 30;
        if ($windowDays <= 0 || $windowDays > 90) {
            $windowDays = 30;
        }

        $anchorDateStr = $options['anchor_date'] ?? Carbon::now('Asia/Jakarta')->format('Y-m-d');
        $anchorDate = Carbon::parse($anchorDateStr, 'Asia/Jakarta');

        $currEnd = $anchorDate->format('Y-m-d');
        $currStart = $anchorDate->copy()->subDays($windowDays)->format('Y-m-d');

        $prevEnd = $anchorDate->copy()->subDays($windowDays + 1)->format('Y-m-d');
        $prevStart = $anchorDate->copy()->subDays($windowDays * 2 + 1)->format('Y-m-d');

        // 5. Gather Minimal Sufficient Evidence
        $tz = $outletId !== null ? TimezoneContract::resolveOutletTimezone($outletId) : 'Asia/Jakarta';

        $currSnapshot = $this->calcService->computeSnapshot($userId, $outletId, $currStart, $currEnd, $tz);
        $prevSnapshot = $this->calcService->computeSnapshot($userId, $outletId, $prevStart, $prevEnd, $tz);

        $warnings = $this->warningService->getCachedWarnings($userId, $outletId);

        // 6. Insufficient Evidence Detection
        $currRevenue = (float) ($currSnapshot['revenue']['value'] ?? 0.0);
        $prevRevenue = (float) ($prevSnapshot['revenue']['value'] ?? 0.0);
        $currTrxCount = (int) ($currSnapshot['revenue']['records_count'] ?? ($currSnapshot['revenue']['transaction_count'] ?? 0));
        $prevTrxCount = (int) ($prevSnapshot['revenue']['records_count'] ?? ($prevSnapshot['revenue']['transaction_count'] ?? 0));

        if ($currTrxCount === 0 && $prevTrxCount === 0) {
            return [
                'status'             => 'INSUFFICIENT_EVIDENCE',
                'investigation_type' => $investigationType,
                'confidence'         => self::CONFIDENCE_LOW,
                'message'            => 'Data historis transaksi belum mencukupi untuk melakukan investigasi akar masalah.',
                'formatted_output'   => $this->buildInsufficientEvidenceOutput($investigationType),
                'evidence'           => [
                    'current_window'  => ['start' => $currStart, 'end' => $currEnd, 'trx_count' => $currTrxCount],
                    'previous_window' => ['start' => $prevStart, 'end' => $prevEnd, 'trx_count' => $prevTrxCount],
                ],
            ];
        }

        // 7. Cross-Domain Comparison
        $metrics = $this->computeComparisonMetrics($currSnapshot, $prevSnapshot);

        // 8. Root-Cause Reasoning
        $reasoning = $this->deriveRootCause($investigationType, $metrics, $warnings);

        // 9. Confidence Assessment
        $confidence = $this->assessConfidence($currSnapshot, $prevSnapshot);

        // 10. Generate Canonical Formatted Text
        $formattedText = $this->formatCanonicalReport(
            $investigationType,
            $metrics,
            $reasoning,
            $confidence,
            $currStart,
            $currEnd
        );

        $confidenceScore = $confidence === self::CONFIDENCE_HIGH ? 0.90 : ($confidence === self::CONFIDENCE_MEDIUM ? 0.70 : 0.40);

        return [
            'status'             => 'SUCCESS',
            'investigation_type' => $investigationType,
            'confidence'         => $confidence,
            'confidence_score'   => $confidenceScore,
            'metrics'            => $metrics,
            'root_causes'        => $reasoning['root_causes'],
            'conclusion'         => $reasoning['conclusion'],
            'recommendations'    => $reasoning['recommendations'],
            'evidence_sources'   => $reasoning['evidence_sources'],
            'formatted_output'   => $formattedText,
            'active_warnings'    => count($warnings),
        ];
    }

    /**
     * Mengklasifikasikan jenis investigasi finansial dari query pengguna.
     */
    public function classifyInvestigationType(string $query): string
    {
        $lower = strtolower($query);

        if (preg_match('/\b(stok|stock|bahan\s*habis|kehabisan|persediaan)\b/i', $lower)) {
            return self::TYPE_STOCK_IMPACT;
        }

        if (preg_match('/\b(cash|kas|uang\s*kas|likuiditas|runway|saldo|menipis|habis\s*kas)\b/i', $lower)) {
            return self::TYPE_CASH_DRAIN;
        }

        if (preg_match('/\b(biaya|beban|pengeluaran|opex|cost|cogs|bengkak|naik\s*biaya|supplier)\b/i', $lower) && !preg_match('/\b(laba|profit|untung)\b/i', $lower)) {
            return self::TYPE_EXPENSE_PRESSURE;
        }

        if (preg_match('/\b(omzet|omset|penjualan|sales|revenue|transaksi|tamu|sepi)\b/i', $lower) && !preg_match('/\b(laba|profit|untung)\b/i', $lower)) {
            return self::TYPE_REVENUE_DECLINE;
        }

        // Default: Profit / Laba decline investigation
        return self::TYPE_PROFIT_DECLINE;
    }

    /**
     * Menghitung delta metrik keuangan antar dua periode observasi.
     */
    protected function computeComparisonMetrics(array $curr, array $prev): array
    {
        $currRev = (float) ($curr['revenue']['value'] ?? 0.0);
        $prevRev = (float) ($prev['revenue']['value'] ?? 0.0);

        $currCogs = (float) ($curr['cogs']['value'] ?? 0.0);
        $prevCogs = (float) ($prev['cogs']['value'] ?? 0.0);

        $currExp = (float) ($curr['expenses']['value'] ?? 0.0);
        $prevExp = (float) ($prev['expenses']['value'] ?? 0.0);

        $currGrossProfit = (float) ($curr['gross_profit']['value'] ?? ($currRev - $currCogs));
        $prevGrossProfit = (float) ($prev['gross_profit']['value'] ?? ($prevRev - $prevCogs));

        $currNetProfit = (float) ($curr['net_profit']['value'] ?? ($currGrossProfit - $currExp));
        $prevNetProfit = (float) ($prev['net_profit']['value'] ?? ($prevGrossProfit - $prevExp));

        $currMarginPct = $currRev > 0 ? ($currGrossProfit / $currRev) * 100.0 : 0.0;
        $prevMarginPct = $prevRev > 0 ? ($prevGrossProfit / $prevRev) * 100.0 : 0.0;

        $currNetMarginPct = $currRev > 0 ? ($currNetProfit / $currRev) * 100.0 : 0.0;
        $prevNetMarginPct = $prevRev > 0 ? ($prevNetProfit / $prevRev) * 100.0 : 0.0;

        // Deltas
        $revDeltaPct = $prevRev > 0 ? (($currRev - $prevRev) / $prevRev) * 100.0 : 0.0;
        $cogsDeltaPct = $prevCogs > 0 ? (($currCogs - $prevCogs) / $prevCogs) * 100.0 : 0.0;
        $expDeltaPct = $prevExp > 0 ? (($currExp - $prevExp) / $prevExp) * 100.0 : 0.0;
        $grossProfitDeltaPct = $prevGrossProfit > 0 ? (($currGrossProfit - $prevGrossProfit) / $prevGrossProfit) * 100.0 : 0.0;
        $netProfitDeltaPct = $prevNetProfit > 0 ? (($currNetProfit - $prevNetProfit) / abs($prevNetProfit)) * 100.0 : 0.0;

        $marginDeltaPp = $currMarginPct - $prevMarginPct;
        $netMarginDeltaPp = $currNetMarginPct - $prevNetMarginPct;

        return [
            'current' => [
                'revenue'        => $currRev,
                'cogs'           => $currCogs,
                'gross_profit'   => $currGrossProfit,
                'expenses'       => $currExp,
                'net_profit'     => $currNetProfit,
                'gross_margin'   => round($currMarginPct, 1),
                'net_margin'     => round($currNetMarginPct, 1),
                'cash_position'  => (float) ($curr['cash_position']['value'] ?? 0.0),
                'cash_runway'    => (float) ($curr['cash_runway']['value'] ?? 0.0),
                'daily_burn'     => (float) ($curr['burn_rate']['value'] ?? 0.0),
            ],
            'previous' => [
                'revenue'        => $prevRev,
                'cogs'           => $prevCogs,
                'gross_profit'   => $prevGrossProfit,
                'expenses'       => $prevExp,
                'net_profit'     => $prevNetProfit,
                'gross_margin'   => round($prevMarginPct, 1),
                'net_margin'     => round($prevNetMarginPct, 1),
            ],
            'change' => [
                'revenue_pct'      => round($revDeltaPct, 1),
                'cogs_pct'         => round($cogsDeltaPct, 1),
                'expenses_pct'     => round($expDeltaPct, 1),
                'gross_profit_pct' => round($grossProfitDeltaPct, 1),
                'net_profit_pct'   => round($netProfitDeltaPct, 1),
                'gross_margin_pp'  => round($marginDeltaPp, 1),
                'net_margin_pp'    => round($netMarginDeltaPp, 1),
            ],
        ];
    }

    /**
     * Menganalisis akar masalah berdasarkan data perbandingan dan peringatan aktif.
     */
    protected function deriveRootCause(string $type, array $metrics, array $warnings): array
    {
        $revChange = $metrics['change']['revenue_pct'];
        $marginChangePp = $metrics['change']['gross_margin_pp'];
        $expChange = $metrics['change']['expenses_pct'];
        $cogsChange = $metrics['change']['cogs_pct'];

        $rootCauses = [];
        $evidenceSources = [];
        $recommendations = [];
        $conclusion = '';

        $evidenceSources[] = 'FinancialCalculationService::computeSnapshot (Snapshot Buku Besar Transaksi)';
        $evidenceSources[] = 'Expense Ledger (Beban Operasional Kas & Kas Kecil)';

        if (!empty($warnings)) {
            $evidenceSources[] = 'FinancialWarningService (Peringatan Anomali & Risiko Aktif)';
        }

        switch ($type) {
            case self::TYPE_PROFIT_DECLINE:
                if ($revChange < -5.0 && abs($marginChangePp) <= 3.0) {
                    $rootCauses[] = sprintf('Penjualan (Revenue) mengalami penurunan sebesar %.1f%% dibandingkan periode sebelumnya.', abs($revChange));
                    $rootCauses[] = 'Margin kotor relatif stabil, menandakan harga jual dan biaya bahan (HPP) tidak mengalami distorsi signifikan.';
                    if ($expChange > 10.0) {
                        $rootCauses[] = sprintf('Beban operasional (OPEX) meningkat sebesar %.1f%%, mempersempit laba bersih.', $expChange);
                        $conclusion = 'Tekanan laba dipicu kombinasi penurunan omzet dan kenaikan beban operasional, bukan distorsi pada harga modal bahan.';
                    } else {
                        $conclusion = 'Tekanan laba terutama berasal dari penurunan penjualan, bukan kenaikan biaya bahan.';
                    }
                    $recommendations[] = 'Fokus pada strategi traffic dan aktivasi menu terlaris untuk memulihkan volume penjualan.';
                    $recommendations[] = 'Pertahankan kontrol efisiensi pada biaya pokok bahan makanan.';
                } elseif ($marginChangePp < -3.0) {
                    $rootCauses[] = sprintf('Margin laba kotor tertekan sebesar %.1f percentage point (dari %.1f%% menjadi %.1f%%).', abs($marginChangePp), $metrics['previous']['gross_margin'], $metrics['current']['gross_margin']);
                    $rootCauses[] = sprintf('Biaya modal bahan (HPP) bertumbuh sebesar %.1f%%, menekan laba kotor.', $cogsChange);
                    $conclusion = 'Penurunan laba terutama disebabkan oleh kompresi margin kotor akibat kenaikan biaya modal bahan baku.';
                    $recommendations[] = 'Audit harga pengadaan bahan baku utama dari supplier.';
                    $recommendations[] = 'Pertimbangkan penyesuaian harga jual selektif pada menu dengan margin tertekan.';
                } elseif ($expChange > 15.0) {
                    $rootCauses[] = sprintf('Beban operasional (OPEX) melonjak sebesar %.1f%%.', $expChange);
                    $conclusion = 'Penurunan laba bersih terutama disebabkan oleh kenaikan beban operasional rutin, sementara margin kotor relatif stabil.';
                    $recommendations[] = 'Audit pos pengeluaran kas kecil kasir harian dan pangkas pos non-esensial.';
                    $recommendations[] = 'Tetapkan pagu anggaran belanja operasional bulanan.';
                } else {
                    $rootCauses[] = 'Performa laba relatif stabil dalam rentang variasi normal transaksi harian.';
                    $conclusion = 'Laba operasional berada dalam ritme wajar tanpa anomali struktural biaya.';
                    $recommendations[] = 'Pantau konsistensi pencatatan transaksi kasir.';
                }
                break;

            case self::TYPE_REVENUE_DECLINE:
                $rootCauses[] = sprintf('Penjualan (Revenue) tercatat mengalami penurunan %.1f%% dibandingkan periode komparasi.', abs($revChange));
                if ($metrics['current']['revenue'] < $metrics['previous']['revenue']) {
                    $rootCauses[] = sprintf('Defisit omzet mencapai %s.', $this->formatRupiah($metrics['previous']['revenue'] - $metrics['current']['revenue']));
                }
                $conclusion = 'Tekanan kinerja finansial bersumber langsung dari penurunan volume penjualan kasir.';
                $recommendations[] = 'Evaluasi jam sibuk outlet dan rancang promosi kombo pada jam sepi pengunjung.';
                $recommendations[] = 'Pastikan ketersediaan menu favorit tetap terjaga sepanjang jam buka.';
                break;

            case self::TYPE_EXPENSE_PRESSURE:
                $rootCauses[] = sprintf('Total beban operasional periode ini tercatat %s (perubahan %.1f%%).', $this->formatRupiah($metrics['current']['expenses']), $expChange);
                $rootCauses[] = sprintf('Rasio beban terhadap omzet berada pada level %.1f%%.', $metrics['current']['revenue'] > 0 ? ($metrics['current']['expenses'] / $metrics['current']['revenue']) * 100.0 : 0.0);
                $conclusion = 'Pengeluaran operasional perlu dievaluasi terhadap kapasitas penerimaan omzet kasir.';
                $recommendations[] = 'Lakukan renegosiasi termin pengadaan bahan atau sewa tempat.';
                $recommendations[] = 'Audit bukti struk kas kecil kasir harian.';
                break;

            case self::TYPE_CASH_DRAIN:
                $runway = $metrics['current']['cash_runway'];
                $burn = $metrics['current']['daily_burn'];
                $rootCauses[] = sprintf('Saldo kas aktif saat ini berada di level %s.', $this->formatRupiah($metrics['current']['cash_position']));
                $rootCauses[] = sprintf('Estimasi runway kas bertahan sekitar %.1f hari dengan rata-rata burn harian %s.', $runway, $this->formatRupiah($burn));
                if ($metrics['current']['net_profit'] < 0) {
                    $rootCauses[] = 'Operasional mencatatkan net profit negatif pada periode observasi.';
                    $conclusion = 'Penipisan cadangan kas dipicu arus kas operasional negatif di mana pemasukan kasir tidak menutup beban harian.';
                } else {
                    $conclusion = 'Cadangan kas memerlukan alokasi belanja operasional yang lebih terukur.';
                }
                $recommendations[] = 'Tunda pengeluaran belanja modal yang belum mendesak.';
                $recommendations[] = 'Fokus pada penerimaan tunai dan penyelesaian transaksi tepat waktu.';
                break;

            case self::TYPE_STOCK_IMPACT:
                $rootCauses[] = 'Perubahan ketersediaan stok bahan baku berbanding lurus dengan kelancaran penjualan menu utama.';
                $rootCauses[] = sprintf('Perubahan HPP tercatat %.1f%% selaras dengan tren perubahan omzet %.1f%%.', $cogsChange, $revChange);
                $conclusion = 'Ketersediaan bahan baku memiliki korelasi langsung terhadap kontinuitas omzet harian outlet.';
                $recommendations[] = 'Terapkan stok pengaman (buffer stock) pada bahan baku dengan tingkat penjualan tertinggi.';
                $recommendations[] = 'Gunakan rekomendasi restock otomatis sebelum bahan baku habis.';
                break;
        }

        return [
            'root_causes'      => $rootCauses,
            'conclusion'       => $conclusion,
            'recommendations'  => $recommendations,
            'evidence_sources' => $evidenceSources,
        ];
    }

    /**
     * Menilai tingkat keyakinan (Confidence) analisis berbasis kelengkapan data.
     */
    protected function assessConfidence(array $curr, array $prev): string
    {
        $hasCurrRev = ($curr['revenue']['value'] ?? 0.0) > 0;
        $hasPrevRev = ($prev['revenue']['value'] ?? 0.0) > 0;
        $hasCogs = ($curr['cogs']['value'] ?? 0.0) > 0;
        $hasExpenses = ($curr['expenses']['value'] ?? 0.0) > 0;

        if ($hasCurrRev && $hasPrevRev && $hasCogs && $hasExpenses) {
            return self::CONFIDENCE_HIGH;
        }

        if ($hasCurrRev && ($hasPrevRev || $hasExpenses)) {
            return self::CONFIDENCE_MEDIUM;
        }

        return self::CONFIDENCE_LOW;
    }

    /**
     * Menyusun format laporan investigasi kanonikal sesuai Section 4 prompt instruksi.
     */
    public function formatCanonicalReport(
        string $type,
        array $metrics,
        array $reasoning,
        string $confidence,
        string $startDate,
        string $endDate
    ): string {
        $curr = $metrics['current'];
        $change = $metrics['change'];

        $revSign = $change['revenue_pct'] >= 0 ? '+' : '';
        $marginSign = $change['gross_margin_pp'] >= 0 ? '+' : '';

        $rootCausesText = '';
        foreach ($reasoning['root_causes'] as $i => $rc) {
            $rootCausesText .= sprintf("%d. %s\n", $i + 1, $rc);
        }

        $recommendationsText = '';
        foreach ($reasoning['recommendations'] as $i => $rec) {
            $recommendationsText .= sprintf("%d. %s\n", $i + 1, $rec);
        }

        $evidenceText = '';
        foreach ($reasoning['evidence_sources'] as $ev) {
            $evidenceText .= sprintf("- %s\n", $ev);
        }

        return <<<EOT
FINANCIAL HEALTH
----------------
Revenue:
{$this->formatRupiah($curr['revenue'])}

Gross Profit:
{$this->formatRupiah($curr['gross_profit'])}

Margin:
{$curr['gross_margin']}%

CHANGE
----------------
Revenue:
{$revSign}{$change['revenue_pct']}%

Gross Margin:
{$marginSign}{$change['gross_margin_pp']} pp

ROOT CAUSE
----------------
{$rootCausesText}
CONCLUSION
----------------
{$reasoning['conclusion']}

EVIDENCE
----------------
{$evidenceText}
ACTIONABLE RECOMMENDATIONS
----------------
{$recommendationsText}
CONFIDENCE
----------------
{$confidence}
EOT;
    }

    /**
     * Membangun teks format saat data bukti tidak mencukupi.
     */
    protected function buildInsufficientEvidenceOutput(string $type): string
    {
        return <<<EOT
FINANCIAL HEALTH
----------------
Revenue:
Rp 0

Gross Profit:
Rp 0

Margin:
0%

CHANGE
----------------
Revenue:
0%

Gross Margin:
0 pp

ROOT CAUSE
----------------
1. DATA HISTORIS TIDAK MENCUKUPI: Data transaksi historis pada periode observasi belum mencukupi untuk menarik korelasi sebab-akibat.
2. Tidak ditemukan rekaman penjualan atau pembukuan yang memadai pada rentang waktu komparasi.
3. Minimal 7-14 hari data transaksi tercatat aktif diperlukan untuk analisis komparasi multi-periode.

CONCLUSION
----------------
Data bukti belum cukup untuk menentukan akar masalah finansial secara akurat. Dibutuhkan aktivitas transaksi dan pencatatan kasir aktif.

EVIDENCE
----------------
- Ledger Transaksi: Kosong atau transaksi di bawah ambang batas minimal investigasi.
- Minimal data historis: 7-14 hari operasional

ACTIONABLE RECOMMENDATIONS
----------------
1. Pastikan transaksi harian dicatat secara konsisten melalui aplikasi kasir Kaching POS.
2. Catat biaya operasional dan modal bahan baku secara berkala.

CONFIDENCE
----------------
LOW
EOT;
    }

    protected function formatRupiah(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}
