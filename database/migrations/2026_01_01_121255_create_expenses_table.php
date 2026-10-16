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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // Nama pengeluaran (misal: Beli Plastik)
            $table->decimal('amount', 15, 2); // Jumlah uang (Rp 15.000)
            $table->string('category');       // Kategori (Operasional, Bahan Baku)
            $table->date('date');             // Tanggal pengeluaran
            $table->text('note')->nullable(); // Catatan tambahan
            $table->timestamps();             // Created_at & Updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
