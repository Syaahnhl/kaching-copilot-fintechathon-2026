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
            // Cek dulu biar gak error kalau kolomnya udah ada (opsional tapi aman)
            if (!Schema::hasColumn('transactions', 'status')) {
                $table->string('status')->default('pending')->after('payment_method');
            }
            if (!Schema::hasColumn('transactions', 'table_number')) {
                $table->string('table_number')->nullable()->after('cashier_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['status', 'table_number']);
        });
    }
};