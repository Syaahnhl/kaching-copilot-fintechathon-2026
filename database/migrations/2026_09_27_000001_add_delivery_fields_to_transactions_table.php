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
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'customer_phone')) {
                $table->string('customer_phone', 25)->nullable()->after('customer_name');
            }
            if (!Schema::hasColumn('transactions', 'delivery_address')) {
                $table->text('delivery_address')->nullable()->after('table_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'delivery_address')) {
                $table->dropColumn('delivery_address');
            }
            if (Schema::hasColumn('transactions', 'customer_phone')) {
                $table->dropColumn('customer_phone');
            }
        });
    }
};
