<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id',
        'menu_id',
        'outlet_id',
        'user_id',
        'staff_id',
        'device_id',
        'type',
        'qty',
        'quantity_delta',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'tx_uuid',
        'notes',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'quantity_delta' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
