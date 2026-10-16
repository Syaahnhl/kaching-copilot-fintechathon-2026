<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('staff', function (Blueprint $table) {
            // Tambahkan kolom gaji per jam, default 0 biar aman
            $table->decimal('hourly_rate', 15, 2)->default(0)->after('pin');
        });
    }

    public function down()
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('hourly_rate');
        });
    }
};
