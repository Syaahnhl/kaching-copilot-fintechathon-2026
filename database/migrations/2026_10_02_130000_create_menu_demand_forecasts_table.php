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
        Schema::create('menu_demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('menu_id');
            $table->date('target_date');
            $table->date('cutoff_date');
            $table->string('uom', 50)->default('Porsi');

            $table->decimal('predicted_quantity', 12, 3)->default(0);
            $table->decimal('lower_bound_p10', 12, 3)->nullable();
            $table->decimal('upper_bound_p90', 12, 3)->nullable();

            $table->string('model_version', 50)->default('catboost_v1.0.0');
            $table->string('feature_version', 50)->default('f37_native_v1');
            $table->string('serving_origin', 50)->default('CATBOOST_BATCH');
            $table->string('history_status', 50)->default('SUFFICIENT');
            
            $table->json('metadata')->nullable();
            $table->timestamp('generated_at')->nullable();
            
            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('outlet_id')->references('id')->on('outlets')->onDelete('cascade');
            $table->foreign('menu_id')->references('id')->on('menus')->onDelete('cascade');

            // Unique operational grain
            $table->unique(['user_id', 'outlet_id', 'menu_id', 'target_date', 'uom'], 'uniq_menu_demand_forecast_grain');

            // Query & Maintenance indexes
            $table->index(['outlet_id', 'target_date'], 'idx_forecast_outlet_target_date');
            $table->index(['menu_id', 'target_date'], 'idx_forecast_menu_target_date');
            $table->index(['cutoff_date'], 'idx_forecast_cutoff_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_demand_forecasts');
    }
};
