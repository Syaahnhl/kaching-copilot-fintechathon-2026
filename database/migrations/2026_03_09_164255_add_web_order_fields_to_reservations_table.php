<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Tambahkan kolom untuk menyimpan gabungan tanggal & jam dari web
            if (!Schema::hasColumn('reservations', 'reservation_time')) {
                $table->dateTime('reservation_time')->nullable()->after('time');
            }

            // Tambahkan kolom JSON untuk menyimpan daftar pesanan menu awal
            if (!Schema::hasColumn('reservations', 'items_preview')) {
                $table->json('items_preview')->nullable()->after('status');
            }

            // Ubah kolom pax, date, dan time agar boleh kosong (nullable) 
            // Karena dari web kita kirimnya reservation_time, bukan date/time terpisah
            $table->integer('pax')->nullable()->change();
            $table->date('date')->nullable()->change();
            $table->string('time')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['reservation_time', 'items_preview']);
        });
    }
};
