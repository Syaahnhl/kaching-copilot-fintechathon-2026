<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TableSeeder extends Seeder
{
    public function run()
    {
        // 1. Matikan cek Foreign Key & Kosongkan Table (Agar ID Reset ke 1)
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('tables')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $now = now();

        // 2. Buat Meja 1-15 (Indoor Utama)
        for ($i = 1; $i <= 15; $i++) {
            DB::table('tables')->insert([
                'number' => (string)$i,
                'area' => 'Indoor Utama',
                'is_occupied' => false,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // 3. Buat Meja 16-30 (Outdoor)
        for ($i = 16; $i <= 30; $i++) {
            DB::table('tables')->insert([
                'number' => (string)$i,
                'area' => 'Outdoor',
                'is_occupied' => false,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }
    }
}