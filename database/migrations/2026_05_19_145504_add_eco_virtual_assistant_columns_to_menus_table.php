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
            $table->integer('gramasi')->nullable()->default(0)->comment('Estimasi berat menu dalam gram');
            $table->integer('kalori')->nullable()->default(0)->comment('Estimasi kalori menu');
            $table->json('allergen_tags')->nullable()->comment('Tag alergi/diet seperti vegan, kacang, seafood');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn(['gramasi', 'kalori', 'allergen_tags']);
        });
    }
};
