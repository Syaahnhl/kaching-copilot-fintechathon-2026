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
        $tables = ['tables', 'menus', 'menu_ingredients', 'items', 'expenses', 'cash_flows', 'reservations'];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (!Schema::hasColumn($tableName, 'outlet_id')) {
                        // Tentukan posisi kolom setelah apa secara dinamis
                        $afterCol = 'id';
                        if (Schema::hasColumn($tableName, 'user_id')) {
                            $afterCol = 'user_id';
                        } elseif (Schema::hasColumn($tableName, 'menu_id')) {
                            $afterCol = 'menu_id';
                        }

                        $table->unsignedBigInteger('outlet_id')->default(0)->after($afterCol)->index();
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['tables', 'menus', 'menu_ingredients', 'items', 'expenses', 'cash_flows', 'reservations'];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (Schema::hasColumn($tableName, 'outlet_id')) {
                        $table->dropColumn('outlet_id');
                    }
                });
            }
        }
    }
};
