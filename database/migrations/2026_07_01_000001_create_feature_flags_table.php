<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->index();
            $table->boolean('enabled')->default(true);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Default flags
        $flags = [
            ['key' => 'discount_per_item', 'enabled' => true, 'description' => 'Per-item discount di cart'],
            ['key' => 'table_transfer', 'enabled' => true, 'description' => 'Pindah meja'],
            ['key' => 'staff_quick_switch', 'enabled' => true, 'description' => 'Quick switch staff'],
            ['key' => 'kds_progress_tracking', 'enabled' => true, 'description' => 'KDS qty progress tracking'],
            ['key' => 'kds_addon_badge', 'enabled' => true, 'description' => 'Badge BARU untuk add-on items'],
            ['key' => 'activity_log', 'enabled' => true, 'description' => 'Activity log tracking'],
            ['key' => 'cash_drawer_auto_open', 'enabled' => true, 'description' => 'Auto-open cash drawer'],
            ['key' => 'split_bill', 'enabled' => true, 'description' => 'Split bill feature'],
            ['key' => 'reservation', 'enabled' => true, 'description' => 'Reservation management'],
            ['key' => 'print_queue', 'enabled' => false, 'description' => 'Print queue (experimental)'],
            ['key' => 'multi_printer', 'enabled' => false, 'description' => 'Multi-printer support (experimental)'],
        ];

        foreach ($flags as $flag) {
            DB::table('feature_flags')->insert(array_merge($flag, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
