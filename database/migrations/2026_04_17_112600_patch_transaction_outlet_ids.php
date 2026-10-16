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
        // 1. Ambil semua user_id yang punya transaksi dengan outlet_id = 0
        $userIds = DB::table('transactions')
            ->where('outlet_id', 0)
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            // 2. Cari ID outlet pertama (terkecil) milik user tersebut
            $firstOutlet = DB::table('outlets')
                ->where('user_id', $userId)
                ->orderBy('id', 'asc')
                ->first();

            if ($firstOutlet) {
                // 3. Update semua transaksi user tersebut yang outlet_id-nya masih 0
                DB::table('transactions')
                    ->where('user_id', $userId)
                    ->where('outlet_id', 0)
                    ->update(['outlet_id' => $firstOutlet->id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Tidak perlu action balik karena data sudah diperbaiki secara permanen
    }
};
