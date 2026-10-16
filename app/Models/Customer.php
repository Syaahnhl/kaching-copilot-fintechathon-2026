<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'outlet_id',
        'name',
        'phone',
        'email',
        'gender',
        'birthdate',
        'address',
        'total_spent',
        'visit_count',
        'loyalty_points',
        'last_visit_at',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'last_visit_at' => 'datetime',
        'total_spent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
