<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashFlow extends Model
{
    use HasFactory;

    protected $table = 'cash_flows'; // Memastikan nama tabel benar

    // Daftar kolom yang boleh diisi oleh Controller
    protected $fillable = [
        // <--- WAJIB ADA (Kunci SaaS/Multi-User)
        'type',         // 'IN' atau 'OUT'
        'amount',       // Jumlah uang
        'description',  // Keterangan
        'operator',     // Nama Kasir/Operator
        'date'          // Tanggal transaksi
    ];

    // Mengubah format data saat dikirim ke Android (JSON)
    protected $casts = [
        'amount' => 'double', // Supaya jadi angka (10000), bukan string ("10000")
        'date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}