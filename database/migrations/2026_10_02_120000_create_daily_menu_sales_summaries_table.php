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
        Schema::create('daily_menu_sales_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('menu_id');
            $table->date('business_date');
            $table->string('uom', 50)->default('Porsi');
            
            $table->decimal('total_qty', 12, 3)->default(0);
            $table->decimal('total_revenue', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->decimal('avg_price', 12, 2)->default(0);
            $table->decimal('avg_cost', 12, 2)->default(0);
            
            $table->timestamp('first_transaction_time')->nullable();
            $table->timestamp('last_transaction_time')->nullable();
            $table->unsignedInteger('unresolved_items_count')->default(0);
            $table->string('cost_provenance', 50)->default('FALLBACK_MENU_MASTER');
            
            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('outlet_id')->references('id')->on('outlets')->onDelete('cascade');
            $table->foreign('menu_id')->references('id')->on('menus')->onDelete('cascade');

            // Unique grain constraint
            $table->unique(['user_id', 'outlet_id', 'menu_id', 'business_date', 'uom'], 'uniq_daily_sales_summary_grain');

            // Query indexes
            $table->index(['outlet_id', 'business_date'], 'idx_summary_outlet_date');
            $table->index(['menu_id', 'business_date'], 'idx_summary_menu_date');
            $table->index(['user_id', 'business_date'], 'idx_summary_user_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_menu_sales_summaries');
    }
};
