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
        Schema::create('item_supplier_price_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('user_id')->nullable(); // Tenant isolation / owner context

            $table->decimal('old_price', 15, 2)->nullable();
            $table->decimal('new_price', 15, 2);
            $table->string('currency', 3)->default('IDR');
            
            $table->string('change_reason', 100)->default('MANUAL_UPDATE'); // 'MANUAL_UPDATE', 'CATALOG_SYNC', 'PO_FULFILLMENT', 'INITIAL_IMPORT'
            $table->timestamp('effective_date')->useCurrent();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('item_id')->references('id')->on('items')->onDelete('cascade');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

            // Query & lookup indexes
            $table->index(['item_id', 'supplier_id', 'effective_date'], 'idx_is_price_hist_lookup');
            $table->index(['supplier_id', 'effective_date'], 'idx_supplier_price_hist_date');
            $table->index(['user_id', 'effective_date'], 'idx_tenant_price_hist_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_supplier_price_histories');
    }
};
