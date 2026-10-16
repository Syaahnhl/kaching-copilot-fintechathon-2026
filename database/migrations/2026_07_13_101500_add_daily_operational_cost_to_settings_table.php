<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * [BEP Phase 2] Biaya operasional tetap harian per merchant.
     * Dipakai untuk hitung Titik Impas (Break-Even Point) di laporan.
     * Default 0 = fitur BEP belum diaktifkan merchant.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (!Schema::hasColumn('settings', 'daily_operational_cost')) {
                $table->integer('daily_operational_cost')->default(0)->after('packaging_fee');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'daily_operational_cost')) {
                $table->dropColumn('daily_operational_cost');
            }
        });
    }
};
