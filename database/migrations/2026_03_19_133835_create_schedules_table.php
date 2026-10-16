<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // ID Pemilik Toko
            $table->foreignId('staff_id')->constrained('staff')->onDelete('cascade'); // ID Karyawan
            $table->date('work_date'); // Tanggal Kerja (Misal: 2026-03-20)
            $table->string('shift_type'); // Tipe Shift (Pagi, Siang, Malam, Full)
            $table->time('start_time')->nullable(); // Jam Masuk (opsional)
            $table->time('end_time')->nullable(); // Jam Pulang (opsional)
            $table->text('notes')->nullable(); // Catatan tambahan
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('schedules');
    }
};
