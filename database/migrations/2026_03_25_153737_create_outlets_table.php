<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id'); // 🏢 Relasi ke Pemilik (SaaS)
            $table->string('name'); // Nama Cabang (Cth: Cabang Sudirman)
            $table->text('address')->nullable(); // Alamat Cabang
            $table->string('phone')->nullable(); // No HP Cabang
            $table->timestamps();

            // Relasi agar jika akun Owner dihapus, data cabangnya ikut terhapus
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('outlets');
    }
};
