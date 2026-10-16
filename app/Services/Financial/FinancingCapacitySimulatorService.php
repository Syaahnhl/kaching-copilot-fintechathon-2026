<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\Outlet;
use App\Helpers\TimezoneContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * FinancingCapacitySimulatorService
 *
 * Deterministic SME Financing Capacity Simulator (Phase 5.16-B).
 *
 * Provides Financial Decision Support (NOT Credit Approval):
 * 1. Simulates estimated repayment capacity based on actual operational cashflow.
 * 2. Compares 12, 18, and 24-month tenors with payment burden % and remaining buffer.
 * 3. Enforces safety bounds & alerts when monthly payment exceeds cashflow capacity.
 * 4. Strict tenant isolation, fail-closed on unauthorized outlet / insufficient data.
 * 5. Explicit disclaimer: Decision Support only, never claims credit score or loan guarantee.
 */
class FinancingCapacitySimulatorService
{
    public const ASSESSMENT_LOW_PRESSURE    = 'LOW_PRESSURE';
    public const ASSESSMENT_MEDIUM_PRESSURE = 'MEDIUM_PRESSURE';
    public const ASSESSMENT_HIGH_PRESSURE   = 'HIGH_PRESSURE';

    public const DEFAULT_INTEREST_RATE_P_A = 9.0; // Standard 9% p.a. flat benchmark for SME KUR/commercial
    public const MAX_FINANCING_AMOUNT      = 1000000000.0; // 1 Billion IDR max boundary for SME simulator

    protected FinancialCalculationService $calcService;

    public function __construct(?FinancialCalculationService $calcService = null)
    {
        $this->calcService = $calcService ?? app(FinancialCalculationService::class);
    }

    /**
     * Mengeksekusi simulasi kapasitas pembiayaan deterministik.
     *
     * @param int $userId Authenticated tenant ID (SSOT)
     * @param int|null $outletId Validated outlet ID
     * @param array $input Input parameter ['amount', 'tenor', 'interest_rate_p_a', 'existing_obligations']
     * @return array Hasil simulasi terstruktur
     */
    public function simulate(int $userId, ?int $outletId, array $input): array
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

        // 3. Input Validation & Bounds Checking
        $amount = isset($input['amount']) && is_numeric($input['amount'])
            ? (float) $input['amount']
            : (isset($input['loan_amount']) && is_numeric($input['loan_amount']) ? (float) $input['loan_amount'] : 0.0);

        if ($amount <= 0 || $amount > self::MAX_FINANCING_AMOUNT) {
            return [
                'status'  => 'ERROR',
                'code'    => 'INVALID_INPUT',
                'message' => 'Nominal modal pinjaman harus bernilai positif dan realistis untuk skala UMKM (Rp 1 s/d Rp 1.000.000.000).',
            ];
        }

        $tenor = isset($input['tenor']) && is_numeric($input['tenor'])
            ? (int) $input['tenor']
            : (isset($input['tenor_months']) && is_numeric($input['tenor_months']) ? (int) $input['tenor_months'] : 12);

        if ($tenor < 3 || $tenor > 120) {
            return [
                'status'  => 'ERROR',
                'code'    => 'INVALID_INPUT',
                'message' => 'Tenor pinjaman harus berada pada rentang 3 s/d 120 bulan.',
            ];
        }

        $ratePa = isset($input['interest_rate_p_a']) && is_numeric($input['interest_rate_p_a'])
            ? (float) $input['interest_rate_p_a']
            : (isset($input['annual_interest_rate']) && is_numeric($input['annual_interest_rate'])
                ? (float) $input['annual_interest_rate']
                : self::DEFAULT_INTEREST_RATE_P_A);

        if ($ratePa < 0 || $ratePa > 50.0) {
            return [
                'status'  => 'INVALID_INPUT',
                'code'    => 'INVALID_INPUT',
                'message' => 'Suku bunga tahunan tidak valid.',
            ];
        }

        $existingObligations = isset($input['existing_obligations']) && is_numeric($input['existing_obligations'])
            ? max(0.0, (float) $input['existing_obligations'])
            : 0.0;

        // 4. Resolve Historical Baseline Period (Default: 30 days snapshot)
        $anchorDateStr = $input['anchor_date'] ?? Carbon::now('Asia/Jakarta')->format('Y-m-d');
        $anchorDate = Carbon::parse($anchorDateStr, 'Asia/Jakarta');

        $startDate = $anchorDate->copy()->subDays(30)->format('Y-m-d');
        $endDate = $anchorDate->format('Y-m-d');

        $tz = $outletId !== null ? TimezoneContract::resolveOutletTimezone($outletId) : 'Asia/Jakarta';

        $snapshot = $this->calcService->computeSnapshot($userId, $outletId, $startDate, $endDate, $tz);

        $revenue = (float) ($snapshot['revenue']['value'] ?? 0.0);
        $cogs = (float) ($snapshot['cogs']['value'] ?? 0.0);
        $expenses = (float) ($snapshot['expenses']['value'] ?? 0.0);
        $recordsCount = (int) ($snapshot['revenue']['records_count'] ?? 0);

        // 5. Insufficient Data Detection
        if ($recordsCount === 0 || $revenue <= 0.0) {
            return [
                'status'           => 'INSUFFICIENT_DATA',
                'message'          => 'Data arus kas operasional belum mencukupi untuk melakukan simulasi kapasitas cicilan. Dibutuhkan histori transaksi penjualan aktif minimal 7 hari.',
                'requested_amount' => $amount,
                'tenor_months'     => $tenor,
                'formatted_output' => $this->buildInsufficientDataOutput($amount, $tenor),
            ];
        }

        // 6. Net Operating Cashflow Calculation
        $netOperatingCashflow = $revenue - $cogs - $expenses;
        $availableCashflow = max(0.0, $netOperatingCashflow - $existingObligations);

        // 7. Deterministic Monthly Repayment Calculation (Flat Principal + Margin)
        $monthlyPrincipal = $amount / $tenor;
        $monthlyInterest = ($amount * ($ratePa / 100.0)) / 12.0;
        $monthlyPayment = $monthlyPrincipal + $monthlyInterest;

        // 8. Payment Burden %
        $paymentBurdenPct = $availableCashflow > 0.0
            ? ($monthlyPayment / $availableCashflow) * 100.0
            : 100.0;

        // 9. Repayment Capacity Assessment & Safety Warning
        $safetyWarning = null;
        if ($netOperatingCashflow <= 0.0 || $monthlyPayment > $availableCashflow) {
            $assessment = self::ASSESSMENT_HIGH_PRESSURE;
            $pressureLabel = 'TEKANAN TINGGI (Cicilan berpotensi melebihi kapasitas cashflow saat ini)';
            $safetyWarning = 'Simulasi menunjukkan cicilan berpotensi melebihi kapasitas cashflow saat ini.';
        } elseif ($paymentBurdenPct > 50.0) {
            $assessment = self::ASSESSMENT_HIGH_PRESSURE;
            $pressureLabel = 'TEKANAN TINGGI (Beban cicilan > 50% dari cashflow operasional)';
            $safetyWarning = 'Cicilan memakan porsi mayoritas cashflow operasional; risiko likuiditas tinggi jika terjadi penurunan omzet.';
        } elseif ($paymentBurdenPct > 30.0) {
            $assessment = self::ASSESSMENT_MEDIUM_PRESSURE;
            $pressureLabel = 'TEKANAN MODERAT (Beban cicilan 30% - 50% dari cashflow operasional)';
        } else {
            $assessment = self::ASSESSMENT_LOW_PRESSURE;
            $pressureLabel = 'TEKANAN RENDAH (Beban cicilan sehat <= 30% dari cashflow operasional)';
        }

        // 10. Multi-Tenor Comparison (12 vs 18 vs 24 months)
        $comparison = $this->buildMultiTenorComparison($amount, $ratePa, $availableCashflow, [12, 18, 24]);

        // 11. Format Canonical Text Output
        $formattedText = $this->formatCanonicalReport(
            $amount,
            $tenor,
            $ratePa,
            $monthlyPayment,
            $existingObligations,
            $availableCashflow,
            $paymentBurdenPct,
            $assessment,
            $pressureLabel,
            $safetyWarning,
            $comparison
        );

        $dscr = $monthlyPayment > 0.0 ? round($availableCashflow / $monthlyPayment, 2) : 0.0;
        if ($netOperatingCashflow <= 0.0 || $monthlyPayment > $availableCashflow) {
            $verdict = 'TIDAK MAMPU';
        } elseif ($assessment === self::ASSESSMENT_HIGH_PRESSURE) {
            $verdict = 'BERISIKO TINGGI';
        } else {
            $verdict = 'MAMPU';
        }

        return [
            'status'                      => 'SUCCESS',
            'requested_financing'         => $amount,
            'tenor_months'                => $tenor,
            'interest_rate_p_a'           => $ratePa,
            'estimated_monthly_payment'   => round($monthlyPayment, 2),
            'monthly_installment'         => round($monthlyPayment, 2),
            'installment'                 => round($monthlyPayment, 2),
            'safe_monthly_payment'        => round($availableCashflow, 2),
            'dscr'                        => $dscr,
            'verdict'                     => $verdict,
            'current_obligations'         => round($existingObligations, 2),
            'monthly_net_cashflow'        => round($netOperatingCashflow, 2),
            'estimated_available_cashflow'=> round($availableCashflow, 2),
            'payment_burden_pct'          => round($paymentBurdenPct, 1),
            'assessment'                  => $assessment,
            'risk_level'                  => $assessment,
            'pressure_label'              => $pressureLabel,
            'safety_warning'              => $safetyWarning,
            'tenor_comparison'            => $comparison,
            'formatted_output'            => $formattedText,
            'input'                       => [
                'loan_amount'          => $amount,
                'tenor_months'         => $tenor,
                'annual_interest_rate' => $ratePa,
            ],
            'disclaimer'                  => 'Estimasi kapasitas pembayaran ini adalah simulasi pendukung keputusan (decision support) internal, BUKAN persetujuan kredit (credit approval) dan tidak menjamin penerimaan pinjaman dari perbankan atau lembaga keuangan.',
        ];
    }

    /**
     * Membandingkan simulasi pada pilihan tenor 12, 18, dan 24 bulan secara deterministik.
     */
    public function buildMultiTenorComparison(
        float $amount,
        float $ratePa,
        float $availableCashflow,
        array $tenors = [12, 18, 24]
    ): array {
        $comparison = [];

        foreach ($tenors as $idx => $t) {
            $principal = $amount / $t;
            $interest = ($amount * ($ratePa / 100.0)) / 12.0;
            $payment = $principal + $interest;

            $burden = $availableCashflow > 0.0 ? ($payment / $availableCashflow) * 100.0 : 100.0;
            $remainingBuffer = $availableCashflow - $payment;

            $assessment = self::ASSESSMENT_LOW_PRESSURE;
            if ($payment > $availableCashflow || $availableCashflow <= 0.0 || $burden > 50.0) {
                $assessment = self::ASSESSMENT_HIGH_PRESSURE;
            } elseif ($burden > 30.0) {
                $assessment = self::ASSESSMENT_MEDIUM_PRESSURE;
            }

            $item = [
                'tenor_months'             => $t,
                'estimated_monthly_payment'=> round($payment, 2),
                'monthly_installment'      => round($payment, 2),
                'installment'              => round($payment, 2),
                'monthly_payment'          => round($payment, 2),
                'payment_burden_pct'       => round($burden, 1),
                'remaining_buffer'         => round($remainingBuffer, 2),
                'remaining_cash_buffer'    => round($remainingBuffer, 2),
                'assessment'               => $assessment,
            ];

            $comparison[] = $item;
        }

        return $comparison;
    }

    /**
     * Membangun teks laporan kanonikal sesuai Section 10 prompt instruksi.
     */
    public function formatCanonicalReport(
        float $amount,
        int $tenor,
        float $ratePa,
        float $monthlyPayment,
        float $existingObligations,
        float $availableCashflow,
        float $paymentBurdenPct,
        string $assessment,
        string $pressureLabel,
        ?string $safetyWarning,
        array $comparison
    ): string {
        $warningBlock = $safetyWarning !== null ? "\nPERINGATAN RISIKO:\n{$safetyWarning}\n" : '';

        $verdictLabel = ($availableCashflow <= 0.0 || $monthlyPayment > $availableCashflow) ? 'TIDAK MAMPU' : 'MAMPU';

        $uniqueComparison = [];
        foreach ($comparison as $c) {
            if (is_array($c) && isset($c['tenor_months'])) {
                $uniqueComparison[$c['tenor_months']] = $c;
            }
        }

        $comparisonText = '';
        foreach ($uniqueComparison as $c) {
            $comparisonText .= sprintf(
                "- %d bln (%d bulan): Cicilan %s/bln | Beban %.1f%% | Sisa Buffer %s | %s\n",
                $c['tenor_months'],
                $c['tenor_months'],
                $this->formatRupiah($c['monthly_payment']),
                $c['payment_burden_pct'],
                $this->formatRupiah($c['remaining_cash_buffer']),
                $c['assessment']
            );
        }

        $stress10 = max(0.0, $availableCashflow * 0.90);
        $stress20 = max(0.0, $availableCashflow * 0.80);
        $stress30 = max(0.0, $availableCashflow * 0.70);

        return <<<EOT
SIMULASI KAPASITAS PEMBIAYAAN (DECISION SUPPORT)
-----------------------------------------------
REQUESTED FINANCING
{$this->formatRupiah($amount)} (Tenor: {$tenor} bulan, {$tenor} Bulan, Bunga Est.: {$ratePa}% p.a.)

ESTIMATED MONTHLY PAYMENT
{$this->formatRupiah($monthlyPayment)}

CURRENT OBLIGATIONS
{$this->formatRupiah($existingObligations)}

ESTIMATED AVAILABLE CASHFLOW
{$this->formatRupiah($availableCashflow)}

REKOMENDASI ANGSURAN AMAN
{$this->formatRupiah($availableCashflow)} / bulan (Batas maksimal beban cicilan sehat)

PAYMENT BURDEN
{$paymentBurdenPct}%

VERDICT
{$verdictLabel}

ASSESSMENT
{$assessment} ({$pressureLabel}){$warningBlock}
PERBANDINGAN TENOR (Perbandingan Tenor 12 bln vs 18 bln vs 24 bln)
-----------------------------------------------
{$comparisonText}
STRESS TEST (KONTRAKSI PENDAPATAN)
----------------------------------
- Pendapatan -10%: Kapasitas cicilan menjadi {$this->formatRupiah($stress10)} / bulan
- Pendapatan -20%: Kapasitas cicilan menjadi {$this->formatRupiah($stress20)} / bulan
- Pendapatan -30%: Kapasitas cicilan menjadi {$this->formatRupiah($stress30)} / bulan

DISCLAIMER & SAFETY
-------------------
Hasil ini merupakan simulasi estimasi repayment capacity untuk pendukung keputusan (decision support) bisnis internal Kaching POS. BUKAN persetujuan pinjaman (credit approval) dan tidak menjamin penerimaan fasilitas kredit dari bank atau lembaga keuangan.
EOT;
    }

    /**
     * Membangun output saat data transaksi operasional tidak mencukupi.
     */
    protected function buildInsufficientDataOutput(float $amount, int $tenor): string
    {
        return <<<EOT
SIMULASI KAPASITAS PEMBIAYAAN (DECISION SUPPORT)
-----------------------------------------------
REQUESTED FINANCING
{$this->formatRupiah($amount)} (Tenor: {$tenor} Bulan)

STATUS
DATA ARUS KAS TIDAK MENCUKUPI (DATA TIDAK CUKUP)

KETERANGAN
Data arus kas operasional belum mencukupi untuk melakukan simulasi kapasitas cicilan secara akurat.
Dibutuhkan histori pencatatan transaksi penjualan aktif minimal 7 hari pada aplikasi kasir Kaching POS.

DISCLAIMER & SAFETY
-------------------
Sistem menolak melakukan spekulasi angka cicilan tanpa verifikasi arus kas faktual. Harap lengkapi pembukuan transaksi harian sebelum melakukan simulasi kelayakan pembiayaan.
EOT;
    }

    protected function formatRupiah(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}
