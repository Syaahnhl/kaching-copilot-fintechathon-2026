<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DAFTAR SEMUA TABEL YANG MAU DITEMPEL user_id
        $tables = [
            'menus', 
            'transactions', 
            'transaction_items', // Penting agar detail item juga terikat user
            'shifts', 
            'expenses', 
            'cash_flows', 
            'reservations', 
            'tables'
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (!Schema::hasColumn($tableName, 'user_id')) {
                        // Tambah kolom user_id setelah id
                        $table->unsignedBigInteger('user_id')->after('id')->nullable();
                        $table->index('user_id'); // Index biar query cepat
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // Hapus kolom user_id kalau di-rollback
        $tables = [
            'menus', 'transactions', 'transaction_items', 'shifts', 
            'expenses', 'cash_flows', 'reservations', 'tables'
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (Schema::hasColumn($tableName, 'user_id')) {
                        $table->dropColumn('user_id');
                    }
                });
            }
        }
    }
};