<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;

    protected $table = 'expenses'; // Nama tabel di database

    // Daftar kolom yang boleh diisi (White-list)
    protected $fillable = [
        // <--- WAJIB ADA (Agar data tidak bocor ke user lain)
        'cabang_id',  // Relasi ke cabang/outlet
        'name',       // Nama pengeluaran
        'amount',     // Jumlah uang
        'category',   // Kategori (Bahan Baku, Operasional, dll)
        'fund_source', // Sumber Dana
        'date',       // Tanggal
        'note',       // Catatan tambahan
        'receipt_image', // Gambar struk
        'qty',        // Jumlah item
        'unit_price', // Harga per item
        'supplier',   // Nama toko/supplier
        'is_reimburse', // Penanda hutang reimburse karyawan
        'is_recurring'  // Penanda pengeluaran rutin
    ];

    // Mengubah tipe data saat dikirim ke Android (JSON)
    protected $casts = [
        'amount' => 'double', // Biar jadi angka (15000), bukan string ("15000")
        'qty' => 'double',
        'unit_price' => 'double',
        'is_reimburse' => 'boolean', // Cast multipart string to boolean
        'is_recurring' => 'boolean', // Cast multipart string to boolean
        'date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relasi ke Toko/User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // 🏢 Relasi ke Cabang/Outlet
    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'cabang_id');
    }
}
