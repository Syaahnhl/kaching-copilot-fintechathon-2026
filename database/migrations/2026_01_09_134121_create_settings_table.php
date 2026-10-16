<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id'); 
            $table->string('store_name')->nullable();
            $table->text('store_address')->nullable();
            $table->string('store_phone')->nullable();
            $table->string('receipt_footer')->nullable();
            $table->string('receipt_website')->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0); 
            $table->decimal('service_charge', 5, 2)->default(0); 
            $table->string('logo_url')->nullable();

            // [FIX] Default diubah menjadi fast_casual
            $table->string('service_mode')->default('fast_casual'); 
            
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};