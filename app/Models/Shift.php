<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'outlet_id',
        'staff_id',
        'app_uuid',         // ID Unik dari HP (untuk sinkronisasi)
        'cashier_name',
        'cashier_type',     // [NEW] Tunai / Non-Tunai / Mixed
        'start_time',
        'end_time',
        'start_cash',
        'end_cash',
        'total_cash_sales',
        'total_expense',
        'cash_drop',
        'expected_cash',
        'difference',
        'status',
        'payment_details'   // Menyimpan rincian pembayaran (JSON)
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function getReconciliationStatusAttribute(): string
    {
        if ($this->status === 'open') {
            return 'BERJALAN';
        }

        $diff = (float) ($this->difference ?? 0.0);
        if ($diff == 0.0) {
            return 'SEIMBANG';
        }

        return $diff > 0.0 ? 'LEBIH' : 'KURANG';
    }

    protected $casts = [
        'start_cash' => 'double',
        'end_cash' => 'double',
        'total_cash_sales' => 'double',
        'total_expense' => 'double',
        'cash_drop' => 'double',
        'expected_cash' => 'double',
        'difference' => 'double',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];
}