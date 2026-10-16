<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PATCH MIGRATION — Subscription & Telemetry Columns
 *
 * Menambahkan 3 kolom yang dibutuhkan fitur Midtrans Subscription
 * dan Live Telemetry KachingBoard secara aman (idempotent).
 *
 * Kolom yang ditambahkan (jika belum ada):
 *  1. subscription_plan       — string, nullable, default 'trial'
 *  2. subscription_expired_at — timestamp, nullable
 *  3. last_seen_at            — timestamp, nullable (untuk live user tracking)
 *
 * CATATAN: Tidak menggunakan ->after() agar tidak crash jika kolom
 * referensi belum ada di database production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            // 1. Kolom subscription_plan (nama paket: trial, basic, pro, ultimate)
            if (!Schema::hasColumn('users', 'subscription_plan')) {
                $table->string('subscription_plan')->nullable()->default('trial');
            }

            // 2. Kolom subscription_expired_at (tanggal kedaluwarsa langganan)
            if (!Schema::hasColumn('users', 'subscription_expired_at')) {
                $table->timestamp('subscription_expired_at')->nullable();
            }

            // 3. Kolom last_seen_at (diupdate Middleware setiap request API Android)
            if (!Schema::hasColumn('users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = ['subscription_plan', 'subscription_expired_at', 'last_seen_at'];

            foreach ($columns as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
