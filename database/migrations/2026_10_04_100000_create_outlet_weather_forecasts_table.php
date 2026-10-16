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
        Schema::create('outlet_weather_forecasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->string('source', 50)->default('open_meteo'); // 'open_meteo', 'bmkg', etc.
            
            $table->timestamp('forecast_published_at')->nullable(); // Waktu publikasi model cuaca provider
            $table->timestamp('retrieved_at')->useCurrent(); // Waktu data ditarik oleh sistem Kaching
            $table->timestamp('forecast_for_timestamp'); // Waktu valid observasi atau prakiraan
            $table->boolean('is_forecast')->default(true); // false = historical/actual observation, true = future forecast
            
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            
            $table->decimal('temperature_c', 5, 2)->nullable();
            $table->decimal('relative_humidity_pct', 5, 2)->nullable();
            $table->decimal('precipitation_mm', 6, 2)->nullable();
            $table->decimal('precipitation_probability_pct', 5, 2)->nullable();
            $table->decimal('rain_mm', 6, 2)->nullable();
            $table->integer('weather_code')->nullable(); // WMO Weather Interpretation Code
            $table->string('weather_condition', 50)->nullable(); // 'CLEAR', 'RAIN', 'OVERCAST', 'THUNDERSTORM', etc.
            
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('outlet_id')->references('id')->on('outlets')->onDelete('cascade');

            // Unique constraint for idempotent vintage ingestion (supports multi-vintage coexistence)
            $table->unique(
                ['outlet_id', 'source', 'forecast_for_timestamp', 'retrieved_at', 'is_forecast'],
                'uniq_outlet_weather_vintage'
            );

            // Query & filtering indexes (optimized for point-in-time vintage lookups)
            $table->index(['outlet_id', 'forecast_for_timestamp'], 'idx_weather_outlet_time');
            $table->index(['outlet_id', 'is_forecast', 'forecast_for_timestamp', 'retrieved_at'], 'idx_weather_pit_lookup');
            $table->index(['retrieved_at'], 'idx_weather_retrieved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outlet_weather_forecasts');
    }
};
