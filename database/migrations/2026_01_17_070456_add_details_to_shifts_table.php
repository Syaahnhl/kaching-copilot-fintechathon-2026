<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Menambahkan kolom yang dibutuhkan Android
            $table->string('app_uuid')->nullable()->after('id'); // ID Unik dari HP
            
            // Kolom Keuangan Tambahan
            $table->decimal('total_expense', 15, 2)->default(0)->after('total_cash_sales');
            $table->decimal('cash_drop', 15, 2)->default(0)->after('total_expense');
            
            // Kolom untuk simpan JSON Rincian (QRIS, Transfer, dll)
            $table->json('payment_details')->nullable()->after('difference');
            
            // Ubah start_time jadi nullable (karena upload report hanya kirim waktu tutup)
            $table->dateTime('start_time')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['app_uuid', 'total_expense', 'cash_drop', 'payment_details']);
        });
    }
};