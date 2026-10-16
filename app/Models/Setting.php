<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'settings'; // (Opsional) Memastikan nama tabelnya benar

    protected $fillable = [
        'user_id',
        'outlet_id',
        'store_name',
        'business_type',
        'store_address',
        'store_phone',
        'receipt_footer',
        'receipt_website',
        'tax_rate',
        'service_charge',
        'logo_url',
        'service_mode',
        'qris_image',
        'bank_name',
        'bank_account',
        'bank_account_name',
        'bank_accounts',    // [BARU] Wajib ditambahkan agar Multi Bank bisa disimpan
        'packaging_fee',    // [BEP Phase 2] eksplisit-kan agar mass-assignment aman
        'daily_operational_cost', // [BEP Phase 2] biaya operasional harian untuk Titik Impas
        'wa_schedule_note', // [BARU] Template catatan pesan WA jadwal shift
        'is_tax_inclusive',
        'is_tax_compound',
        // [PRINTER & RECEIPT SETTINGS PERSISTENCE]
        'paper_size',
        'show_logo',
        'show_tax',
        'show_service_charge',
        'struk_dobel',
        'rounding_mode',
        'pinned_shortcuts',
    ];

    protected $hidden = [
        'midtrans_server_key',
    ];

    // [TAMBAHAN] Casting Tipe Data
    // Supaya di Android tidak perlu convert string ke number lagi
    protected $casts = [
        'tax_rate' => 'double',       // Contoh output: 10.5 (bukan "10.5")
        'service_charge' => 'double', // Contoh output: 5.0 (bukan "5.0")
        'is_tax_inclusive' => 'boolean',
        'is_tax_compound' => 'boolean',
        'paper_size' => 'integer',
        'show_logo' => 'boolean',
        'show_tax' => 'boolean',
        'show_service_charge' => 'boolean',
        'struk_dobel' => 'boolean',
    ];
}
