<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    // [PENTING] Tambahkan  agar sistem SaaS berjalan
    protected $fillable = [
        'user_id',
        'outlet_id',
        'app_uuid',
        'customer_name',
        'total_amount',
        'pay_amount',
        'payment_method',
        'status',
        'table_number',
        'created_at_device',
        'cashier_name',
        'reservation_id',
        'cancel_reason',
        'snap_token',
        'discount_amount',
        'discount_note',
        'delivery_fee',
        'packaging_fee',
        'order_type',
        'commission_type',
        'commission_rate',
        'commission_amount',
        'subtotal',
        'ppn_percentage',
        'ppn_amount',
        'service_charge_percentage',
        'service_charge_amount',
        'tip_amount',
        'promo_id',
        'promo_code_applied',
        'promo_discount',
        'customer_id',
        'staff_id',
        'shift_id',
        'customer_phone',
        'delivery_address'
    ];

    // [BARU] Pastikan format data benar saat keluar/masuk
    // created_at_device = UTC instant yang disimpan sebagai string di DB.
    // Phase 2 contract: accessor mengekspos ISO-8601 UTC ("2026-09-04T16:59:59Z").
    // Android parseSyncDeviceTimestamp mendeteksi Z marker → parse sebagai UTC.
    // JANGAN cast ke datetime: biarkan accessor yang format.
    protected $casts = [
        'total_amount' => 'double',
        'pay_amount' => 'double',
        'reservation_id' => 'integer',
        'commission_rate' => 'float',
        'commission_amount' => 'double',
    ];

    /**
     * Phase 2: Serialize created_at_device as ISO-8601 UTC with explicit Z suffix.
     *
     * DB stores UTC instant. API consumers MUST receive unambiguous timezone info.
     * Android KachingDateFormatter.parseSyncDeviceTimestamp() detects 'Z'/'T' and
     * routes to parseServerIsoUtc() for correct epoch conversion.
     *
     * Before: "2026-09-04 16:59:59"      (ambiguous — UTC? WIB? unknown)
     * After:  "2026-09-04T16:59:59Z"     (explicit UTC instant)
     */
    public function getCreatedAtDeviceAttribute($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Carbon/DateTime from query builders — format as UTC ISO-8601
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s\Z');
        }

        return \App\Helpers\TimezoneContract::formatUtcAsIso((string) $value);
    }

    // Relasi: Satu Transaksi punya banyak Item
    public function items()
    {
        return $this->hasMany(TransactionItem::class, 'transaction_id', 'id');
    }

    // Relasi ke Reservasi
    public function reservation()
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    // Relasi ke Toko/User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // 🏢 [BARU] Relasi ke Cabang/Outlet
    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    // ⏰ [RELATIONAL SHIFT BINDING] Relasi ke Shift
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function scopeUnpaid($query)
    {
        return $query->whereColumn('pay_amount', '<', 'total_amount')
            ->whereNotIn('status', ['Batal', 'Cancelled', 'Refund']);
    }

    // [BARU] Helper tersentralisasi untuk daftar status "Sukses / Lunas"
    public static function getCompletedStatuses()
    {
        return ['Selesai'];
    }

    // [BARU] Helper status aktif untuk visualisasi Dashboard & Laporan Web Owner
    public static function getActiveStatusesForWeb()
    {
        return ['Selesai', 'Served', 'Proses'];
    }

    // [BARU] Helper SQL untuk menghitung Omset secara akurat (melindungi data Piutang/Kasbon & Transaksi Jadul)
    public static function getActualPaidSql($customStatuses = null)
    {
        $targetStatuses = $customStatuses ?? self::getCompletedStatuses();
        $statuses = implode("','", $targetStatuses);
        // [PORTABILITAS 2026-07-13] Pakai CASE WHEN (bukan IF + LEAST) agar SQL jalan
        // di MySQL (production) DAN SQLite (test). Logika identik:
        //   - pay_amount=0 + status lunas (transaksi jadul/non-piutang) -> total_amount
        //   - bayar nanti/piutang belum lunas -> pay_amount (0 jika belum bayar, atau DP jika sebagian)
        //   - else -> LEAST(pay_amount, total_amount) = uang riil masuk.
        return "CASE
                    WHEN pay_amount = 0 AND status IN ('{$statuses}') AND (payment_method IS NULL OR (LOWER(payment_method) NOT LIKE '%bayar nanti%' AND LOWER(payment_method) NOT LIKE '%hutang%')) THEN total_amount
                    WHEN pay_amount < total_amount THEN pay_amount
                    ELSE total_amount
                END";
    }

    // [BARU] Accessor untuk pemanggilan di PHP/Blade (Sama dengan logika getActualPaidSql)
    public function getActualPaidAttribute()
    {
        $method = strtolower($this->payment_method ?? '');
        $isBayarNanti = str_contains($method, 'bayar nanti') || str_contains($method, 'hutang');
        if ($this->pay_amount == 0 && in_array($this->status, self::getCompletedStatuses()) && !$isBayarNanti) {
            return $this->total_amount; // Transaksi jadul yang terkena default migration pay_amount = 0
        }
        return min($this->pay_amount ?? 0, $this->total_amount); // Transaksi normal, DP, atau Piutang
    }

    // --- AKSESOR (Kodingan Mas sudah bagus, saya pertahankan) ---

    public function getOrderTypeAttribute($value)
    {
        // 1. Jika kolom fisik 'order_type' sudah terisi (New Data), pakai itu!
        if (!empty($value)) return $value;

        // 2. Jika kosong (Old Data), coba parse dari customer_name pakai Regex
        if (preg_match('/\((.*?)\)/', $this->customer_name, $matches)) {
            $type = $matches[1];
            if (str_contains($type, ' - ')) {
                $parts = explode(' - ', $type);
                return $parts[0];
            }
            return $type;
        }

        // 3. Fallback terakhir
        return 'Dine In';
    }

    public function getStatusStyleAttribute()
    {
        // 1. BATAL
        if ($this->status === 'Batal' || $this->status === 'Cancelled') {
            return ['color' => 'bg-red-100 text-red-600 border-red-200', 'label' => 'DIBATALKAN', 'icon' => '✕'];
        }

        // 2. MENUNGGU PEMBAYARAN (Online)
        if ($this->status === 'Pending Payment') {
            return ['color' => 'bg-slate-100 text-slate-600 border-slate-200', 'label' => 'MENUNGGU BAYAR', 'icon' => '💳'];
        }

        // 3. SELESAI
        if (in_array($this->status, self::getCompletedStatuses())) {
            return ['color' => 'bg-green-100 text-green-700 border-green-200', 'label' => 'SELESAI', 'icon' => '✓'];
        }

        // 4. BELUM LUNAS (Kasbon Kasir)
        if ($this->pay_amount < $this->total_amount && $this->payment_method === 'Kasir') {
            return ['color' => 'bg-orange-100 text-orange-700 border-orange-200', 'label' => 'BELUM LUNAS', 'icon' => '⏳'];
        }

        // 5. DIPROSES (Sudah Bayar Online / Sudah Diterima Kasir)
        return ['color' => 'bg-blue-100 text-blue-700 border-blue-200', 'label' => 'DIPROSES', 'icon' => '👨‍🍳'];
    }
}
