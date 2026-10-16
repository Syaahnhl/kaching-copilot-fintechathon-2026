<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyMenuSalesSummary extends Model
{
    use HasFactory;

    protected $table = 'daily_menu_sales_summaries';

    protected $fillable = [
        'user_id',
        'outlet_id',
        'menu_id',
        'business_date',
        'uom',
        'total_qty',
        'total_revenue',
        'total_cost',
        'transaction_count',
        'avg_price',
        'avg_cost',
        'first_transaction_time',
        'last_transaction_time',
        'unresolved_items_count',
        'cost_provenance',
    ];

    protected $casts = [
        'business_date' => 'date:Y-m-d',
        'total_qty' => 'double',
        'total_revenue' => 'double',
        'total_cost' => 'double',
        'transaction_count' => 'integer',
        'avg_price' => 'double',
        'avg_cost' => 'double',
        'unresolved_items_count' => 'integer',
        'first_transaction_time' => 'datetime',
        'last_transaction_time' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }
}
