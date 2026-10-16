<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'category',
        'moq',
        'payment_terms',
        'lead_time',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function items()
    {
        return $this->belongsToMany(Item::class, 'item_supplier')
            ->withPivot('supplier_price', 'lead_time_days')
            ->withTimestamps();
    }

    public function priceHistories()
    {
        return $this->hasMany(ItemSupplierPriceHistory::class);
    }
}
