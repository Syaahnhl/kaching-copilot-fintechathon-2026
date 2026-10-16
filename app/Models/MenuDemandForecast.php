<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuDemandForecast extends Model
{
    use HasFactory;

    protected $table = 'menu_demand_forecasts';

    protected $fillable = [
        'user_id',
        'outlet_id',
        'menu_id',
        'target_date',
        'cutoff_date',
        'uom',
        'predicted_quantity',
        'lower_bound_p10',
        'upper_bound_p90',
        'model_version',
        'feature_version',
        'serving_origin',
        'history_status',
        'metadata',
        'generated_at',
    ];

    protected $attributes = [
        'uom' => 'Porsi',
        'model_version' => 'catboost_v1.0.0',
        'feature_version' => 'f37_native_v1',
        'serving_origin' => 'CATBOOST_BATCH',
        'history_status' => 'SUFFICIENT',
    ];

    protected $casts = [
        'target_date' => 'date:Y-m-d',
        'cutoff_date' => 'date:Y-m-d',
        'predicted_quantity' => 'double',
        'lower_bound_p10' => 'double',
        'upper_bound_p90' => 'double',
        'metadata' => 'array',
        'generated_at' => 'datetime',
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
