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
    Schema::create('tables', function (Blueprint $table) {
        $table->id();
        $table->string('number'); // Nomor Meja (misal: "1", "2", "Vip 1")
        $table->string('area')->default('Indoor'); // Area (Indoor, Outdoor, Lt 2)
        $table->boolean('is_occupied')->default(false); // Status: 0 = Kosong, 1 = Isi
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
