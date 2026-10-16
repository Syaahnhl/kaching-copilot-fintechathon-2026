<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('settings', function (Blueprint $table) {
            // Menggunakan tipe 'text' untuk menyimpan array JSON agar aman di semua versi database
            if (Schema::hasColumn('settings', 'bank_account_name')) {
                $table->text('bank_accounts')->nullable()->after('bank_account_name');
            } else {
                $table->text('bank_accounts')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            //
        });
    }
};
