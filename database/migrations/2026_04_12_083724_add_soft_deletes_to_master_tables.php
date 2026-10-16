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
        if (!Schema::hasColumn('menus', 'deleted_at')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (!Schema::hasColumn('staff', 'deleted_at')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (!Schema::hasColumn('tables', 'deleted_at')) {
            Schema::table('tables', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('menus', 'deleted_at')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasColumn('staff', 'deleted_at')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasColumn('tables', 'deleted_at')) {
            Schema::table('tables', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
