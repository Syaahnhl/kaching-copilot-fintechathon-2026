<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'outlet_id',
        'sku',
        'name',
        'type',
        'base_uom',
        'cost_price',
        'sales_price',
        'reorder_point',
        'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'sales_price' => 'decimal:2',
        'reorder_point' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function menus()
    {
        return $this->belongsToMany(Menu::class, 'menu_ingredients')
            ->withPivot('qty');
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'item_supplier')
            ->withPivot('supplier_price', 'lead_time_days')
            ->withTimestamps();
    }

    public function supplierPriceHistories()
    {
        return $this->hasMany(ItemSupplierPriceHistory::class);
    }
}
