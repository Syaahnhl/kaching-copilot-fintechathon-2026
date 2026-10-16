<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            // Data Kasir & Waktu
            $table->string('cashier_name'); 
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();

            // Tipe Kasir (tunai / non_cash / mixed)
            $table->string('cashier_type')->default('tunai');

            // Uang Modal & Setoran
            $table->decimal('start_cash', 15, 2)->default(0); // Modal Awal
            $table->decimal('end_cash', 15, 2)->nullable();   // Uang di Laci (Fisik)

            // Rekap Sistem (Dihitung Otomatis)
            $table->decimal('total_cash_sales', 15, 2)->default(0); // Penjualan Tunai
            $table->decimal('total_cash_refunded', 15, 2)->default(0); 
            $table->decimal('expected_cash', 15, 2)->default(0);    // Harusnya ada segini
            $table->decimal('difference', 15, 2)->default(0);       // Selisih (Plus/Minus)

            $table->string('status')->default('open'); // 'open' atau 'closed'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
