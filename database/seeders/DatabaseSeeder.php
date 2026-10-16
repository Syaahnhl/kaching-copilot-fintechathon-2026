<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Panggil Seeder Meja (Agar Meja dibuat otomatis)
        $this->call([
            TableSeeder::class,
        ]);

        // 2. Masukkan data menu contoh (Kode lama Anda)
        // Tips: Jika data menu makin banyak, sebaiknya pindahkan ke MenuSeeder.php terpisah
        DB::table('menus')->insert([
            [
                'name' => 'Nasi Goreng Spesial',
                'category' => 'Makanan',
                'price' => 25000,
                'cost_price' => 15000,
                'stock' => 50,
                'image_url' => null,
                'has_variant' => true,
                'description' => 'Nasi goreng mantap',
                'is_available' => true,
                'gramasi' => 300,
                'kalori' => 450,
                'allergen_tags' => json_encode(['seafood', 'kacang']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Es Teh Manis',
                'category' => 'Minuman',
                'price' => 5000,
                'cost_price' => 2000,
                'stock' => 100,
                'image_url' => null,
                'has_variant' => true,
                'description' => 'Segar',
                'is_available' => true,
                'gramasi' => 250,
                'kalori' => 120,
                'allergen_tags' => json_encode(['vegan']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // 3. Buat User Dummy untuk testing KDS (Kitchen)
        $user = \App\Models\User::firstOrCreate(
            ['email' => 'kitchen@kaching.id'],
            [
                'name' => 'Owner Kitchen Test',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'subscription_plan' => 'ULTIMATE',
                'role' => 'mitra'
            ]
        );

        $outlet = \App\Models\Outlet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => 'Cabang KDS Test',
                'address' => 'Jl. Dapur Utama No. 1',
                'phone' => '081234567890'
            ]
        );

        // Tambahkan role KITCHEN
        \App\Models\Staff::firstOrCreate(
            ['user_id' => $user->id, 'role' => 'KITCHEN'],
            [
                'outlet_id' => $outlet->id,
                'staff_name' => 'Chef Juna',
                'pin' => '333333',
                'is_active' => true,
                'hourly_rate' => 0,
                'employment_type' => 'full_time',
                'base_salary' => 0,
                'daily_allowance' => 0
            ]
        );
    }
}
