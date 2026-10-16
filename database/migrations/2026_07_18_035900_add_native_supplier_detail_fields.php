<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (!Schema::hasColumn('suppliers', 'category')) {
                $table->string('category')->nullable()->after('address');
            }
            if (!Schema::hasColumn('suppliers', 'moq')) {
                $table->string('moq')->nullable()->after('category');
            }
            if (!Schema::hasColumn('suppliers', 'payment_terms')) {
                $table->string('payment_terms')->nullable()->after('moq');
            }
            if (!Schema::hasColumn('suppliers', 'lead_time')) {
                $table->string('lead_time')->nullable()->after('payment_terms');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            foreach (['lead_time', 'payment_terms', 'moq', 'category'] as $column) {
                if (Schema::hasColumn('suppliers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
