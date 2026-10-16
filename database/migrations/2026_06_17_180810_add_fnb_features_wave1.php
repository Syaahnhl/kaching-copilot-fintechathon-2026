<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah field PPN/Service Charge/Tip/Promo/Customer ke transactions
        // (calculation sudah ada di TransactionController, tinggal simpan ke DB)
        Schema::table('transactions', function (Blueprint $table) {
            // PPN/Tax
            $table->decimal('ppn_percentage', 5, 2)->default(0)->after('discount_amount');
            $table->decimal('ppn_amount', 15, 2)->default(0)->after('ppn_percentage');

            // Service Charge
            $table->decimal('service_charge_percentage', 5, 2)->default(0)->after('ppn_amount');
            $table->decimal('service_charge_amount', 15, 2)->default(0)->after('service_charge_percentage');

            // Tip
            $table->decimal('tip_amount', 15, 2)->default(0)->after('service_charge_amount');

            // Subtotal sebelum pajak (untuk transparency di struk)
            $table->decimal('subtotal', 15, 2)->default(0)->after('tip_amount');

            // Promo
            $table->unsignedBigInteger('promo_id')->nullable()->after('subtotal');
            $table->string('promo_code_applied', 50)->nullable()->after('promo_id');
            $table->decimal('promo_discount', 15, 2)->default(0)->after('promo_code_applied');

            // Customer
            $table->unsignedBigInteger('customer_id')->nullable()->after('promo_discount');

            // Index untuk reporting
            $table->index('promo_id');
            $table->index('customer_id');
        });

        // Tabel Customer (member database)
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('outlet_id')->nullable(); // outlet-specific customer
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->enum('gender', ['L', 'P'])->nullable();
            $table->date('birthdate')->nullable();
            $table->text('address')->nullable();
            $table->decimal('total_spent', 15, 2)->default(0);
            $table->integer('visit_count')->default(0);
            $table->integer('loyalty_points')->default(0);
            $table->timestamp('last_visit_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'phone']);
            $table->index('outlet_id');
        });

        // Tabel Modifier (master: Pedas, Level, Extra Cheese, dll)
        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->string('name'); // "Level Pedas", "Topping", dll
            $table->enum('type', ['single', 'multiple'])->default('single'); // single choice or multi
            $table->boolean('is_required')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        // Tabel Modifier Options (opsi di dalam modifier: Level 1, Level 2, dll)
        Schema::create('modifier_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('modifier_id');
            $table->string('name'); // "Level 1", "Level 2", "Extra Cheese"
            $table->decimal('price_adjustment', 15, 2)->default(0); // tambah harga
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Tabel pivot menu <-> modifier
        Schema::create('menu_modifier', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('menu_id');
            $table->unsignedBigInteger('modifier_id');
            $table->timestamps();
        });

        // Order Modifier (snapshot modifier yang dipilih customer)
        Schema::create('order_modifiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_item_id');
            $table->unsignedBigInteger('modifier_option_id');
            $table->string('modifier_name'); // snapshot
            $table->string('option_name'); // snapshot
            $table->decimal('price_adjustment', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'ppn_percentage', 'ppn_amount',
                'service_charge_percentage', 'service_charge_amount',
                'tip_amount', 'subtotal',
                'promo_id', 'promo_code_applied', 'promo_discount',
                'customer_id',
            ]);
        });

        Schema::dropIfExists('order_modifiers');
        Schema::dropIfExists('menu_modifier');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('customers');
    }
};
