<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->id();
            
            // Link ke tabel transaksi utama
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');

            // Data Menu
            $table->string('menu_name'); 
            $table->double('qty'); // <--- [UBAH INI] Gunakan double untuk menampung desimal
            $table->decimal('price', 15, 2); 
            $table->string('note')->nullable(); 
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};