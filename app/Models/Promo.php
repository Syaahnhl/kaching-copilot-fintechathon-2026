<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promo extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code',
        'discount_type',
        'discount_value',
        'min_purchase',
        'is_active',
        'expired_at',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'is_active' => 'boolean',
        'expired_at' => 'date',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', 1)
            ->where(function($q) {
                $q->whereNull('expired_at')->orWhere('expired_at', '>=', now()->toDateString());
            });
    }
}
