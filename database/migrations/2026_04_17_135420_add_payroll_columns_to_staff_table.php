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
        Schema::table('staff', function (Blueprint $table) {
            $table->string('employment_type')->default('full_time')->after('hourly_rate');
            $table->decimal('base_salary', 15, 2)->nullable()->default(0)->after('employment_type');
            $table->decimal('daily_allowance', 15, 2)->nullable()->default(0)->after('base_salary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['employment_type', 'base_salary', 'daily_allowance']);
        });
    }
};
