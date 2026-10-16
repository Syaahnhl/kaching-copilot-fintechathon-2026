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
        if (Schema::hasTable('staff') && !Schema::hasColumn('staff', 'must_change_pin')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->boolean('must_change_pin')->default(false)->after('pin');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('staff') && Schema::hasColumn('staff', 'must_change_pin')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->dropColumn('must_change_pin');
            });
        }
    }
};
