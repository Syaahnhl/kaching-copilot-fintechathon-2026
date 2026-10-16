<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Outlet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'address',
        'phone',
        'latitude',
        'longitude',
        'attendance_radius',
        'attendance_token',
        'attendance_link_active',
    ];

    protected $casts = [
        'attendance_link_active' => 'boolean',
        'latitude' => 'double',
        'longitude' => 'double',
        'attendance_radius' => 'integer',
    ];

    // Relasi balik ke Pemilik (Owner)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relasi ke Cache Data Cuaca Outlet
     */
    public function weatherForecasts()
    {
        return $this->hasMany(OutletWeatherForecast::class);
    }

    protected static function booted()
    {
        static::created(function ($outlet) {
            $user = $outlet->user;
            if ($user && strtolower($user->subscription_plan) === 'ultimate') {
                $staffData = [
                    'user_id' => $user->id,
                    'outlet_id' => $outlet->id,
                    'staff_name' => 'Dapur',
                    'role' => 'KITCHEN',
                    'pin' => '888888',
                    'hourly_rate' => 0,
                    'employment_type' => 'full_time',
                    'base_salary' => 0,
                    'daily_allowance' => 0,
                    'is_active' => true,
                ];
                if (\Illuminate\Support\Facades\Schema::hasColumn('staff', 'must_change_pin')) {
                    $staffData['must_change_pin'] = true;
                }
                \App\Models\Staff::create($staffData);
            }
        });
    }
}
