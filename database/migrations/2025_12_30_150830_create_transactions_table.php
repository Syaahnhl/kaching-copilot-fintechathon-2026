<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            // ID Server (Auto increment 1, 2, 3...)
            $table->id();

            // KUNCI UTAMA: UUID dari Android (Wajib ada biar data tidak dobel saat sinkronisasi)
            $table->uuid('app_uuid')->unique();

            // Nomor Faktur Resmi (misal: INV/2023/001) - Boleh kosong dulu
            $table->string('official_invoice_number')->nullable()->unique();

            // Data Keuangan
            $table->decimal('total_amount', 15, 2); // Contoh: 50000.00
            $table->string('payment_method'); // Tunai, QRIS, dll
            
            // Info Tambahan
            $table->string('cashier_name')->nullable(); // Nama Kasir
            
            // Waktu transaksi terjadi di HP (PENTING untuk laporan)
            $table->timestamp('created_at_device')->nullable();

            // Waktu data masuk ke server (Otomatis Laravel)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};