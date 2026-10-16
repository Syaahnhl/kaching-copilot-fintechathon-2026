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
        Schema::table('settings', function (Blueprint $blue) {
            $blue->boolean('is_tax_inclusive')->default(false)->after('tax_rate');
            $blue->boolean('is_tax_compound')->default(false)->after('is_tax_inclusive');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $blue) {
            $blue->dropColumn(['is_tax_inclusive', 'is_tax_compound']);
        });
    }
};
