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
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name');    // Nama Pemesan
            $table->string('phone_number')->nullable(); // No HP
            $table->date('date');               // Tanggal (Format: YYYY-MM-DD)
            $table->string('time');             // Jam (Format: HH:mm)
            $table->integer('pax');             // Jumlah Orang
            $table->text('notes')->nullable();  // Catatan (Area, Ultah, dll)
            $table->string('status')->default('Pending'); // Status: Pending, Confirmed, Done
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
