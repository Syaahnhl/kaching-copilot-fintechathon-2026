<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;

class Staff extends Model
{
    use HasFactory, SoftDeletes;

    // Karena Laravel membuatkan tabel dengan nama 'staff' (bukan 'staffs')
    protected $table = 'staff';

    protected $fillable = [
        'user_id',
        'outlet_id',
        'staff_name',
        'role',
        'pin',
        'hourly_rate',
        'is_active',
        'employment_type',
        'base_salary',
        'daily_allowance',
        'must_change_pin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'pin',
        'base_salary',
        'hourly_rate',
        'daily_allowance',
    ];

    protected $casts = [
        'is_active' => 'integer',
        'hourly_rate' => 'decimal:2',
        'base_salary' => 'decimal:2',
        'daily_allowance' => 'decimal:2',
        'must_change_pin' => 'boolean',
    ];

    /**
     * Append computed attributes to JSON serialization.
     * pin_length = actual length of stored PIN so Android renders correct dot count.
     */
    protected $appends = ['pin_length', 'must_change_pin'];

    public function getPinLengthAttribute(): int
    {
        $pin = self::normalizePin((string) $this->pin);
        $len = strlen($pin);
        // Minimum 4, maximum 6. Default 6 if empty/weird.
        return ($len >= 4 && $len <= 6) ? $len : 6;
    }

    public function getMustChangePinAttribute(): bool
    {
        return (bool) ($this->attributes['must_change_pin'] ?? false);
    }

    // Relasi balik ke tabel User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pastikan role selalu huruf kapital (Standarisasi KITCHEN, KASIR, dll)
     */
    public function getRoleAttribute($value)
    {
        return strtoupper($value);
    }

    public function setPinAttribute($value)
    {
        $strValue = (string) $value;
        if (self::looksHashed($strValue)) {
            $this->attributes['pin'] = $strValue;
            return;
        }

        $normalized = self::normalizePin($strValue);
        if ($normalized !== '') {
            // Store secure BCrypt hash
            $this->attributes['pin'] = Hash::make($normalized);
        } else {
            $this->attributes['pin'] = $value;
        }
    }
    public static function normalizePin(string $pin): string
    {
        return (string) preg_replace('/\D/', '', trim($pin));
    }

    public static function looksHashed(string $pin): bool
    {
        return str_starts_with($pin, '$2y$')
            || str_starts_with($pin, '$2a$')
            || str_starts_with($pin, '$argon2');
    }

    /**
     * Match submitted PIN against stored value (plain or bcrypt).
     * Also accepts 4-digit backoffice PIN vs 6-digit keypad (e.g. 1234 vs 001234).
     */
    public function pinMatches(string $input): bool
    {
        $stored = (string) $this->pin;
        $submitted = self::normalizePin($input);

        if ($stored === '' || $submitted === '') {
            return false;
        }

        if (self::looksHashed($stored)) {
            if (Hash::check($submitted, $stored)) {
                return true;
            }
            // POS app often zero-pads to 6 digits (e.g. 1234 -> 001234)
            if (strlen($submitted) <= 6) {
                $padded = str_pad($submitted, 6, '0', STR_PAD_LEFT);
                if ($padded !== $submitted && Hash::check($padded, $stored)) {
                    return true;
                }
                $unpadded = ltrim($submitted, '0');
                if ($unpadded !== $submitted && $unpadded !== '' && Hash::check($unpadded, $stored)) {
                    return true;
                }
            }
            return false;
        }

        $storedPlain = self::normalizePin($stored);
        if ($storedPlain === $submitted) {
            // [FIX H-03]: Upgrade legacy plaintext to Bcrypt hash immediately
            $this->pin = Hash::make($submitted);
            $this->saveQuietly();
            return true;
        }

        // POS app often zero-pads to 6 digits; backoffice may store 4 (legacy plain)
        $padWidth = 6;
        if (
            strlen($stored) <= $padWidth
            && strlen($submitted) <= $padWidth
            && str_pad($stored, $padWidth, '0', STR_PAD_LEFT) === str_pad($submitted, $padWidth, '0', STR_PAD_LEFT)
        ) {
            // [FIX H-03]: Upgrade legacy plaintext to Bcrypt hash immediately
            $this->pin = Hash::make($submitted);
            $this->saveQuietly();
            return true;
        }

        return false;
    }

    /**
     * Update PIN staf dan reset flag must_change_pin.
     */
    public function changePin(string $newPin): void
    {
        $this->pin = self::normalizePin($newPin);
        $this->must_change_pin = false;
        $this->save();
    }

    /**
     * Cek apakah PIN termasuk PIN default sistem yang dilarang.
     */
    public static function isDefaultPin(string $pin): bool
    {
        $normalized = self::normalizePin($pin);
        $blacklist = ['123456', '000000', '111111', '222222', '333333', '888888'];
        return in_array($normalized, $blacklist, true)
            || in_array(str_pad($normalized, 6, '0', STR_PAD_LEFT), $blacklist, true);
    }
}
