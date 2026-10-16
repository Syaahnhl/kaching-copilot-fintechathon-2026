<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('category');
            
            // Tipe Decimal biar aman, nanti di Android dikonversi ke Int
            $table->decimal('price', 15, 2);       // Harga Jual
            $table->decimal('cost_price', 15, 2)->default(0); // Harga Modal (HPP)
            
            $table->integer('stock')->default(0);
            
            // Mapping: image_url (Server) -> imagePath (Android)
            $table->string('image_url')->nullable(); 
            
            // Mapping: has_variant (Server) -> hasVariant (Android)
            $table->boolean('has_variant')->default(false); 

            $table->boolean('is_kds')->default(true);
            
            $table->text('description')->nullable(); // Opsional
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};