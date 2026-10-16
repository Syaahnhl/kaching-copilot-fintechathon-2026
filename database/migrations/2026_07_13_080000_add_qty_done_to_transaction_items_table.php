<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [SCHEMA DRIFT FIX 2026-07-13] Kolom `qty_done` dipakai di TransactionController@store
 * (insert item KDS) dan sudah ada di DB production, TAPI tidak pernah punya file migration
 * — kemungkinan ditambah manual via SQL. Akibatnya schema test (SQLite dari migration)
 * tidak punya kolom ini -> insert transaction_items gagal 500.
 *
 * Guard hasColumn: aman untuk production (kolom sudah ada -> di-skip) dan
 * memperbaiki schema test agar sinkron dengan kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            if (!Schema::hasColumn('transaction_items', 'qty_done')) {
                // qty item yang sudah selesai dimasak (progress KDS). Default 0.
                $table->integer('qty_done')->default(0)->after('kds_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            if (Schema::hasColumn('transaction_items', 'qty_done')) {
                $table->dropColumn('qty_done');
            }
        });
    }
};
