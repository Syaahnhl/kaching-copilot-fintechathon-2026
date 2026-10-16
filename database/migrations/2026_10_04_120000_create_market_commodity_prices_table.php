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
        Schema::create('market_commodity_prices', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50)->default('BAPANAS'); // 'BAPANAS', 'PIHPS', 'SP2KP'
            $table->string('commodity_code', 100); // e.g. 'CABAI_RAWIT_MERAH', 'BERAS_PREMIUM'
            $table->string('commodity_name', 255);
            $table->string('category', 100)->nullable(); // 'BAHAN_POKOK', 'BUMBU', 'PROTEIN', etc.
            
            $table->string('province', 100)->nullable();
            $table->string('regency_city', 100)->default('NASIONAL'); // 'KOTA_JAKARTA_PUSAT', 'NASIONAL', etc.
            $table->string('price_level', 50)->default('KONSUMEN'); // 'KONSUMEN', 'PRODUSEN', 'GROSIR'
            
            $table->decimal('price', 15, 2);
            $table->string('raw_uom', 50)->default('kg');
            $table->string('standard_uom', 50)->default('kg');
            $table->decimal('standard_price_per_uom', 15, 2);
            
            $table->date('reference_date');
            $table->timestamp('retrieved_at')->useCurrent();
            
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            // Unique constraint for idempotent ingestion (composite identity)
            $table->unique(
                ['source', 'commodity_code', 'regency_city', 'price_level', 'reference_date'],
                'uniq_market_commodity_price_grain'
            );

            // Query & filtering indexes
            $table->index(['commodity_code', 'reference_date'], 'idx_mkt_comm_date');
            $table->index(['regency_city', 'commodity_code', 'reference_date'], 'idx_mkt_city_comm_date');
            $table->index(['reference_date'], 'idx_mkt_ref_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_commodity_prices');
    }
};
