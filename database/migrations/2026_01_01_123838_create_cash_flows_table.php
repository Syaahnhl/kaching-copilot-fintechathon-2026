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
    Schema::create('cash_flows', function (Blueprint $table) {
        $table->id();
        $table->string('type');             // "IN" (Masuk) atau "OUT" (Keluar)
        $table->decimal('amount', 15, 2);   // Jumlah Uang (Rp)
        $table->string('description');      // Keterangan (Cth: Modal Receh)
        $table->string('operator')->default('Kasir'); // Siapa yang input
        $table->date('date');               // Tanggal Transaksi (YYYY-MM-DD)
        $table->timestamps();               // Waktu input (created_at)
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_flows');
    }
};
