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
        Schema::table('menus', function (Blueprint $table) {
            $table->integer('price_gofood')->default(0)->after('price');
            $table->integer('price_grabfood')->default(0)->after('price_gofood');
            $table->integer('price_shopeefood')->default(0)->after('price_grabfood');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn(['price_gofood', 'price_grabfood', 'price_shopeefood']);
        });
    }
};
