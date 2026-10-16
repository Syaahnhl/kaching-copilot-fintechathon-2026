<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add nullable outlet_id column
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedBigInteger('outlet_id')->nullable()->after('user_id');
        });

        // 2. Perform safe, idempotent data migration
        $settings = DB::table('settings')->get();
        foreach ($settings as $setting) {
            $userId = $setting->user_id;

            // Get outlets for this user
            $outlets = DB::table('outlets')->where('user_id', $userId)->get();

            if ($outlets->count() === 1) {
                // Group A: Deterministic single outlet
                DB::table('settings')
                    ->where('id', $setting->id)
                    ->update(['outlet_id' => $outlets->first()->id]);
            } elseif ($outlets->count() > 1) {
                // Group B: Multi-outlet (Currently 0 on prod, but handled for architecture validation)
                // Attempt Group B1 (Deterministic multi-outlet via store name/address match)
                $matchedOutlet = null;
                foreach ($outlets as $outlet) {
                    if (!empty($outlet->name) && strtolower($setting->store_name) === strtolower($outlet->name)) {
                        $matchedOutlet = $outlet;
                        break;
                    }
                }

                if ($matchedOutlet) {
                    DB::table('settings')
                        ->where('id', $setting->id)
                        ->update(['outlet_id' => $matchedOutlet->id]);
                } else {
                    // Group B2: Ambiguous (Keep outlet_id NULL, require manual review)
                    // Log / flag this for review
                }
            } else {
                // Group C: Orphaned settings (0 outlets)
                // Keep outlet_id NULL, preserve the original values
            }
        }

        // 3. Generate default settings for Group D (Outlets without settings)
        $outletsWithoutSettings = DB::table('outlets')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('settings')
                    ->whereColumn('settings.user_id', 'outlets.user_id');
            })->get();

        foreach ($outletsWithoutSettings as $outlet) {
            DB::table('settings')->insert([
                'user_id' => $outlet->user_id,
                'outlet_id' => $outlet->id,
                'store_name' => $outlet->name,
                'business_type' => 'F&B', // system default
                'store_address' => $outlet->address ?? '',
                'store_phone' => $outlet->phone ?? '',
                'receipt_footer' => 'Terima kasih atas kunjungan Anda',
                'receipt_website' => '',
                'qris_image' => null,
                'bank_name' => null,
                'bank_account' => null,
                'bank_account_name' => null,
                'bank_accounts' => '[]',
                'tax_rate' => 0.00,
                'service_charge' => 0.00,
                'packaging_fee' => 0,
                'daily_operational_cost' => 0,
                'rounding_mode' => 'NONE',
                'paper_size' => 80,
                'show_logo' => 0,
                'show_tax' => 1,
                'show_service_charge' => 1,
                'struk_dobel' => 0,
                'logo_url' => null,
                'service_mode' => 'fast_casual',
                'attendance_retention_days' => 7,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 4. Add foreign key and unique constraint
        Schema::table('settings', function (Blueprint $table) {
            $table->foreign('outlet_id')
                ->references('id')
                ->on('outlets')
                ->onDelete('set null')
                ->onUpdate('cascade');

            $table->unique(['user_id', 'outlet_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropForeign(['outlet_id']);
            $table->dropUnique(['user_id', 'outlet_id']);
            $table->dropColumn('outlet_id');
        });
    }
};
