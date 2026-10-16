<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Shift;
use App\Models\Expense;
use Carbon\Carbon;

class TransactionMetricService
{
    /**
     * Mengambil metrik shift secara kanonikal.
     * Guardrails:
     * 1. Validasi relasi shift terhadap user_id dan outlet_id.
     * 2. Transaksi baru difilter strictly via shift_id.
     * 3. Transaksi legacy (shift_id NULL) difilter via created_at_device di dalam rentang shift.
     * 4. created_at (server timestamp) DILARANG digunakan untuk menentukan shift transaksi.
     */
    public static function getShiftMetrics(int $userId, int $shiftId, ?int $outletId = null): array
    {
        $shiftQuery = Shift::where('user_id', $userId)->where('id', $shiftId);
        if ($outletId && $outletId > 0) {
            $shiftQuery->where('outlet_id', $outletId);
        }
        $shift = $shiftQuery->first();

        if (!$shift) {
            return self::emptyMetrics($shiftId);
        }

        $nowUtc = Carbon::now('UTC');
        $shiftStartUtc = Carbon::parse($shift->start_time)->timezone('UTC')->format('Y-m-d H:i:s');
        $shiftEndUtc = $shift->end_time
            ? Carbon::parse($shift->end_time)->timezone('UTC')->format('Y-m-d H:i:s')
            : $nowUtc->format('Y-m-d H:i:s');

        // Query Transaksi
        $txQuery = Transaction::where('user_id', $userId)
            ->where(function ($q) use ($shiftId, $shiftStartUtc, $shiftEndUtc) {
                // Transaksi baru: bind eksplisit ke shift_id
                $q->where('shift_id', $shiftId)
                  // Transaksi legacy: fallback HANYA jika shift_id NULL berdasarkan created_at_device
                  ->orWhere(function ($legacy) use ($shiftStartUtc, $shiftEndUtc) {
                      $legacy->whereNull('shift_id')
                             ->whereBetween('created_at_device', [$shiftStartUtc, $shiftEndUtc]);
                  });
            })
            ->whereNotIn('status', ['Batal', 'Cancelled', 'Refund']);

        if ($outletId && $outletId > 0) {
            $txQuery->where('outlet_id', $outletId);
        }

        $totalPaidSql = Transaction::getActualPaidSql();
        $txResult = $txQuery->selectRaw("
            COALESCE(SUM(total_amount), 0) as gross_sales,
            COALESCE(SUM({$totalPaidSql}), 0) as paid_amount,
            COUNT(*) as total_count
        ")->first();

        $grossSales = (int) ($txResult->gross_sales ?? 0);
        $paidAmount = (int) ($txResult->paid_amount ?? 0);
        $totalCount = (int) ($txResult->total_count ?? 0);
        $outstanding = max(0, $grossSales - $paidAmount);

        // Query Pengeluaran (Expense) selama shift (menggunakan kolom `date`)
        $expenseQuery = Expense::where('user_id', $userId)
            ->whereBetween('date', [$shiftStartUtc, $shiftEndUtc]);

        if ($outletId && $outletId > 0) {
            $expenseQuery->where('outlet_id', $outletId);
        }
        $totalExpense = (int) ($expenseQuery->sum('amount') ?? 0);

        return [
            'gross_sales' => $grossSales,
            'total_omset' => $paidAmount, // Cash Basis SSOT
            'paid_amount' => $paidAmount,
            'total_piutang' => $outstanding,
            'total_expense' => $totalExpense,
            'total_count' => $totalCount,
            'shift_id' => $shiftId,
            'shift_status' => $shift->status,
            'shift_start_time' => $shift->start_time
        ];
    }

    private static function emptyMetrics(?int $shiftId = null): array
    {
        return [
            'gross_sales' => 0,
            'total_omset' => 0,
            'paid_amount' => 0,
            'total_piutang' => 0,
            'total_expense' => 0,
            'total_count' => 0,
            'shift_id' => $shiftId,
            'shift_status' => null,
            'shift_start_time' => null
        ];
    }
}
