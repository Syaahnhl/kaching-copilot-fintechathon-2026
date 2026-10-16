<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ensure commission_rate exists first if not present (e.g. clean test database)
        Schema::table('order_types', function (Blueprint $table) {
            if (!Schema::hasColumn('order_types', 'commission_rate')) {
                $table->decimal('commission_rate', 5, 2)->default(0.00)->after('sort_order');
            }
            if (!Schema::hasColumn('order_types', 'commission_type')) {
                $table->string('commission_type', 20)->default('PERCENTAGE')->after('commission_rate');
            }
            if (!Schema::hasColumn('order_types', 'commission_value')) {
                $table->decimal('commission_value', 15, 2)->default(0.00)->after('commission_type');
            }
        });

        // Seed/Backfill existing order_types: commission_value = commission_rate, commission_type = 'PERCENTAGE'
        if (Schema::hasColumn('order_types', 'commission_rate')) {
            DB::table('order_types')->whereNull('commission_type')->orWhere('commission_type', '')->update([
                'commission_type' => 'PERCENTAGE',
                'commission_value' => DB::raw('COALESCE(commission_rate, 0.00)'),
            ]);
        }

        // 2. Add commission snapshot columns to transactions table
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'commission_type')) {
                $table->string('commission_type', 20)->nullable()->after('order_type');
            }
            if (!Schema::hasColumn('transactions', 'commission_rate')) {
                $table->decimal('commission_rate', 5, 2)->default(0.00)->after('commission_type');
            }
            if (!Schema::hasColumn('transactions', 'commission_amount')) {
                $table->decimal('commission_amount', 15, 2)->default(0.00)->after('commission_rate');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_types', function (Blueprint $table) {
            if (Schema::hasColumn('order_types', 'commission_value')) {
                $table->dropColumn('commission_value');
            }
            if (Schema::hasColumn('order_types', 'commission_type')) {
                $table->dropColumn('commission_type');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'commission_amount')) {
                $table->dropColumn('commission_amount');
            }
            if (Schema::hasColumn('transactions', 'commission_rate')) {
                $table->dropColumn('commission_rate');
            }
            if (Schema::hasColumn('transactions', 'commission_type')) {
                $table->dropColumn('commission_type');
            }
        });
    }
};
