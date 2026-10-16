<?php

namespace App\Models;

// [PENTING] Tambahkan baris ini agar fitur Token API aktif
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    // [PENTING] Masukkan HasApiTokens ke dalam sini
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'otp',
        'google_id'
        // 🛡️ [UPDATE] Kolom role, status, dan subscription DIHAPUS dari sini 
        // agar kebal dari serangan Mass Assignment (Hacker maksa jadi admin).
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'otp',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    // [BARU] Relasi: Satu akun toko punya banyak profil staf
    public function staffs()
    {
        return $this->hasMany(\App\Models\Staff::class);
    }

    public function profiles()
    {
        return $this->hasMany(Staff::class)->where('is_active', true);
    }

    // Relasi: Satu User (Mitra) memiliki banyak Transaksi
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Accessor untuk subscription_tier dengan fallback ke subscription_plan
     * Menjaga kompatibilitas antara Copilot / Chatbot ($user->subscription_tier)
     * dan skema database fisik VPS (users.subscription_plan).
     */
    public function getSubscriptionTierAttribute(): string
    {
        return (string) (
            $this->attributes['subscription_tier']
            ?? $this->attributes['subscription_plan']
            ?? 'BASIC'
        );
    }

    protected static function booted()
    {
        static::updated(function ($user) {
            if ($user->wasChanged('subscription_plan') && strtolower($user->subscription_plan) === 'ultimate') {
                $user->ensureDefaultKitchenStaff();
            }
        });
    }

    public function ensureDefaultKitchenStaff()
    {
        $outlets = \App\Models\Outlet::where('user_id', $this->id)->get();
        foreach ($outlets as $outlet) {
            $exists = \App\Models\Staff::where('user_id', $this->id)
                ->where('outlet_id', $outlet->id)
                ->where('role', 'DAPUR')
                ->exists();
                
            if (!$exists) {
                \App\Models\Staff::create([
                    'user_id' => $this->id,
                    'outlet_id' => $outlet->id,
                    'staff_name' => 'Dapur',
                    'role' => 'DAPUR',
                    'pin' => '888888',
                    'hourly_rate' => 0,
                    'employment_type' => 'full_time',
                    'base_salary' => 0,
                    'daily_allowance' => 0,
                    'is_active' => true,
                ]);
            }
        }
    }
}
