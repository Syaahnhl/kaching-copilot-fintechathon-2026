<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S1/S2 fix (2026-07-13): tenant isolation.
 * suppliers & stock_opnames tidak punya kolom tenant → IDOR lintas merchant.
 * Tambah user_id (nullable + index). Tabel 0 rows saat migrasi = aman, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suppliers') && ! Schema::hasColumn('suppliers', 'user_id')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id')->index();
            });
        }

        if (Schema::hasTable('stock_opnames') && ! Schema::hasColumn('stock_opnames', 'user_id')) {
            Schema::table('stock_opnames', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'user_id')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }

        if (Schema::hasTable('stock_opnames') && Schema::hasColumn('stock_opnames', 'user_id')) {
            Schema::table('stock_opnames', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }
    }
};
