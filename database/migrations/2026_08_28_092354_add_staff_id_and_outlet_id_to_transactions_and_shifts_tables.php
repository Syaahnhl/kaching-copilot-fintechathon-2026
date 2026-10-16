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
        // 1. Modifikasi tabel transactions (tambahkan staff_id)
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'staff_id')) {
                $table->unsignedBigInteger('staff_id')->nullable()->after('user_id');
                
                // Tambahkan index & foreign key constraint yang aman
                $table->foreign('staff_id')
                    ->references('id')
                    ->on('staff')
                    ->onDelete('set null');
            }
        });

        // 2. Modifikasi tabel shifts (tambahkan staff_id dan outlet_id)
        Schema::table('shifts', function (Blueprint $table) {
            if (!Schema::hasColumn('shifts', 'staff_id')) {
                $table->unsignedBigInteger('staff_id')->nullable()->after('user_id');
                
                $table->foreign('staff_id')
                    ->references('id')
                    ->on('staff')
                    ->onDelete('set null');
            }

            if (!Schema::hasColumn('shifts', 'outlet_id')) {
                $table->unsignedBigInteger('outlet_id')->nullable()->after('staff_id');
                
                $table->foreign('outlet_id')
                    ->references('id')
                    ->on('outlets')
                    ->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign keys & kolom dari tabel shifts
        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'staff_id')) {
                $table->dropForeign(['staff_id']);
                $table->dropColumn('staff_id');
            }
            if (Schema::hasColumn('shifts', 'outlet_id')) {
                $table->dropForeign(['outlet_id']);
                $table->dropColumn('outlet_id');
            }
        });

        // Drop foreign key & kolom dari tabel transactions
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'staff_id')) {
                $table->dropForeign(['staff_id']);
                $table->dropColumn('staff_id');
            }
        });
    }
};
