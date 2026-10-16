<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah kolom 'unit' di tabel menus
        Schema::table('menus', function (Blueprint $table) {
            // Default 'Porsi' agar menu lama tidak error
            $table->string('unit')->default('Porsi')->after('stock'); 
        });

        // 2. Ubah kolom 'qty' di tabel transaction_items (atau order_items, sesuaikan nama tabelmu)
        // Ubah dari Integer ke Decimal(8, 2) agar bisa simpan angka 0.5, 1.25, dll
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->decimal('qty', 10, 3)->change(); 
            // (10, 3) artinya total 10 digit, 3 angka di belakang koma (cth: 1234.567)
        });
        
        // 3. Ubah kolom 'qty' di tabel kitchen_order_items (Jika ada)
        if (Schema::hasTable('kitchen_order_items')) {
             Schema::table('kitchen_order_items', function (Blueprint $table) {
                $table->decimal('qty', 10, 3)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('unit');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->integer('qty')->change(); // Balikin ke integer jika rollback
        });
        
         if (Schema::hasTable('kitchen_order_items')) {
             Schema::table('kitchen_order_items', function (Blueprint $table) {
                $table->integer('qty')->change();
            });
        }
    }
};