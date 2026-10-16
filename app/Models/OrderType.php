<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderType extends Model
{
    use HasFactory;

    protected $table = 'order_types';

    protected $fillable = [
        'user_id',
        'uuid',
        'name',
        'is_default',
        'is_active',
        'sort_order',
        'commission_type',
        'commission_value',
        'commission_rate',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
            if (empty($model->commission_type)) {
                $model->commission_type = 'PERCENTAGE';
            }
        });
    }

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'commission_value' => 'float',
        'commission_rate' => 'float',
    ];

    /**
     * Relasi ke User (Tenant)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
