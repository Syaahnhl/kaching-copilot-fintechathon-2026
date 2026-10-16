<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add additive columns to menus and stock_movements for Phase 4 Canonical Stock Ledger.
     */
    public function up(): void
    {
        // 1. Tambahkan kolom version ke tabel menus untuk Stock Opname / absolute update safety
        if (Schema::hasTable('menus') && !Schema::hasColumn('menus', 'version')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->unsignedBigInteger('version')->default(1)->after('stock');
            });
        }

        // 2. Tambahkan additive columns ke tabel stock_movements untuk menu-level mutation ledger & idempotency
        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (Schema::hasColumn('stock_movements', 'item_id')) {
                    $table->unsignedBigInteger('item_id')->nullable()->change();
                }
                if (Schema::hasColumn('stock_movements', 'type')) {
                    $table->string('type', 50)->change();
                }
                if (!Schema::hasColumn('stock_movements', 'menu_id')) {
                    $table->unsignedBigInteger('menu_id')->nullable()->after('id')->index();
                }
                if (!Schema::hasColumn('stock_movements', 'outlet_id')) {
                    $table->unsignedBigInteger('outlet_id')->nullable()->after('menu_id')->index();
                }
                if (!Schema::hasColumn('stock_movements', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('outlet_id')->index();
                }
                if (!Schema::hasColumn('stock_movements', 'staff_id')) {
                    $table->unsignedBigInteger('staff_id')->nullable()->after('user_id');
                }
                if (!Schema::hasColumn('stock_movements', 'device_id')) {
                    $table->string('device_id', 100)->nullable()->after('staff_id');
                }
                if (!Schema::hasColumn('stock_movements', 'tx_uuid')) {
                    $table->string('tx_uuid', 64)->nullable()->after('device_id')->unique();
                }
                if (!Schema::hasColumn('stock_movements', 'quantity_delta')) {
                    $table->decimal('quantity_delta', 15, 2)->nullable()->after('qty');
                }
                if (!Schema::hasColumn('stock_movements', 'balance_before')) {
                    $table->decimal('balance_before', 15, 2)->nullable()->after('quantity_delta');
                }
                if (!Schema::hasColumn('stock_movements', 'balance_after')) {
                    $table->decimal('balance_after', 15, 2)->nullable()->after('balance_before');
                }
                if (!Schema::hasColumn('stock_movements', 'reference_type')) {
                    $table->string('reference_type', 50)->nullable()->after('reference_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('menus') && Schema::hasColumn('menus', 'version')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->dropColumn('version');
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $columnsToDrop = [];
                foreach (['menu_id', 'outlet_id', 'user_id', 'staff_id', 'device_id', 'tx_uuid', 'quantity_delta', 'balance_before', 'balance_after', 'reference_type'] as $col) {
                    if (Schema::hasColumn('stock_movements', $col)) {
                        $columnsToDrop[] = $col;
                    }
                }
                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
};
