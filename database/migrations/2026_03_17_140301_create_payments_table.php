<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('package_name'); // basic, pro, ultimate
            $table->integer('amount'); // 249000
            $table->string('payment_method')->nullable(); // qris, bank_transfer
            $table->string('status')->default('pending'); // success, pending, failed
            $table->string('snap_token')->nullable(); // Dari Midtrans
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
