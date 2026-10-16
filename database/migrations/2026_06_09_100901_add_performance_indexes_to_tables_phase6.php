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
        // 1. stock_movements: reference_id
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index('reference_id', 'sm_reference_id_idx');
        });

        // 2. menus: (user_id, name)
        Schema::table('menus', function (Blueprint $table) {
            $table->index(['user_id', 'name'], 'menus_user_name_idx');
        });

        // 3. transactions: (user_id, status, created_at_device)
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'status', 'created_at_device'], 'trx_user_status_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('sm_reference_id_idx');
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->dropIndex('menus_user_name_idx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('trx_user_status_date_idx');
        });
    }
};
