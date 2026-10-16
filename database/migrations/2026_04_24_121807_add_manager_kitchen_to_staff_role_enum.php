<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Mengubah kolom role dari ENUM 3 nilai menjadi 5 nilai
     * agar mendukung MANAGER dan KITCHEN.
     *
     * SAFETY: Bersihkan data kotor dulu sebelum ALTER TABLE,
     * agar MySQL Strict Mode tidak memblokir proses.
     */
    public function up(): void
    {
        // Langkah 1: Sanitasi data lama yang role-nya tidak valid
        // (termasuk string kosong atau nilai yang tidak ada di ENUM lama)
        DB::table('staff')
            ->whereNotIn('role', ['OWNER', 'KASIR', 'WAITER'])
            ->update(['role' => 'KASIR']);

        // Langkah 2: Perluas ENUM dengan raw SQL (Hanya jika bukan sqlite)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `staff` MODIFY COLUMN `role` ENUM('OWNER', 'MANAGER', 'KASIR', 'WAITER', 'KITCHEN') NOT NULL DEFAULT 'KASIR'");
        }
    }

    /**
     * Reverse the migrations.
     *
     * PERHATIAN: Rollback ini akan GAGAL jika ada data dengan role
     * MANAGER atau KITCHEN. Sanitasi dulu sebelum rollback.
     */
    public function down(): void
    {
        // Sanitasi data MANAGER/KITCHEN agar tidak crash saat rollback ke ENUM lama
        DB::table('staff')
            ->whereNotIn('role', ['OWNER', 'KASIR', 'WAITER'])
            ->update(['role' => 'KASIR']);

        // Kembalikan ke ENUM awal (3 nilai) (Hanya jika bukan sqlite)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `staff` MODIFY COLUMN `role` ENUM('OWNER', 'KASIR', 'WAITER') NOT NULL DEFAULT 'KASIR'");
        }
    }
};
