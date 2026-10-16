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
        Schema::table('transaction_items', function (Blueprint $table) {
            if (!Schema::hasColumn('transaction_items', 'refund_qty')) {
                $table->decimal('refund_qty', 10, 2)->default(0.00)->after('qty');
            }
        });

        // 🛡️ [BACKFILL SSOT] Isi refund_qty = qty hanya untuk item historis berstatus Refund penuh
        // yang belum memiliki nilai refund_qty (nilai 0 / null) agar riwayat tetap konsisten.
        if (Schema::hasColumn('transaction_items', 'status') && Schema::hasColumn('transaction_items', 'refund_qty')) {
            \Illuminate\Support\Facades\DB::table('transaction_items')
                ->whereIn('status', ['Refund', 'refund'])
                ->where(function ($query) {
                    $query->where('refund_qty', 0)
                          ->orWhereNull('refund_qty');
                })
                ->update(['refund_qty' => \Illuminate\Support\Facades\DB::raw('qty')]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            if (Schema::hasColumn('transaction_items', 'refund_qty')) {
                $table->dropColumn('refund_qty');
            }
        });
    }
};
