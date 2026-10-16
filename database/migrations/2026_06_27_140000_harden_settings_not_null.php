<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add business_type column if it doesn't exist
        if (!Schema::hasColumn('settings', 'business_type')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->string('business_type', 255)->nullable()->after('service_mode');
            });
        }

        // Step 1: Fill existing NULL values with safe defaults
        DB::statement("UPDATE settings SET store_name = '' WHERE store_name IS NULL");
        DB::statement("UPDATE settings SET store_address = '' WHERE store_address IS NULL");
        DB::statement("UPDATE settings SET store_phone = '' WHERE store_phone IS NULL");
        DB::statement("UPDATE settings SET receipt_footer = '' WHERE receipt_footer IS NULL");
        DB::statement("UPDATE settings SET receipt_website = '' WHERE receipt_website IS NULL");
        DB::statement("UPDATE settings SET business_type = '' WHERE business_type IS NULL");
        DB::statement("UPDATE settings SET bank_accounts = '[]' WHERE bank_accounts IS NULL");

        // Step 2: Add NOT NULL constraints
        Schema::table('settings', function (Blueprint $table) {
            $table->string('store_name', 255)->nullable(false)->default('')->change();
            $table->text('store_address')->nullable(false)->default('')->change();
            $table->string('store_phone', 255)->nullable(false)->default('')->change();
            $table->string('receipt_footer', 255)->nullable(false)->default('')->change();
            $table->string('receipt_website', 255)->nullable(false)->default('')->change();
            $table->string('business_type', 255)->nullable(false)->default('')->change();
            $table->text('bank_accounts')->nullable(false)->default('[]')->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('store_name', 255)->nullable()->default(null)->change();
            $table->text('store_address')->nullable()->default(null)->change();
            $table->string('store_phone', 255)->nullable()->default(null)->change();
            $table->string('receipt_footer', 255)->nullable()->default(null)->change();
            $table->string('receipt_website', 255)->nullable()->default(null)->change();
            $table->string('business_type', 255)->nullable()->default(null)->change();
            $table->text('bank_accounts')->nullable()->default(null)->change();
        });

        if (Schema::hasColumn('settings', 'business_type')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('business_type');
            });
        }
    }
};
