<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Helper mutlak untuk mengecek eksistensi Index secara Raw SQL
     * Mencegah crash Duplicate key name 1061.
     */
    private function indexExists($tableName, $indexName)
    {
        if (DB::getDriverName() === 'sqlite') {
            $results = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND name=?", [$indexName]);
            return count($results) > 0;
        }
        // Menggunakan SHOW INDEX lebih universal di MySQL/MariaDB
        $indexes = DB::select("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tablesAndColumns = [
            'transactions' => ['outlet_id', 'status', 'created_at_device', 'created_at', 'reservation_id'],
            'transaction_items' => ['status'],
            'tables' => ['is_occupied'],
            'reservations' => ['status', 'date'],
            'shifts' => ['status', 'created_at'],
            'payments' => ['status', 'transaction_id'],
            'web_orders' => ['status'],
            'staff' => ['outlet_id']
        ];

        foreach ($tablesAndColumns as $tableName => $columns) {
            if (Schema::hasTable($tableName)) {

                // Cari kolom mana yang SEKARANG butuh index agar kita tidak memanggil Schema::table sia-sia
                $columnsToIndex = [];

                foreach ($columns as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $indexName = strtolower($tableName . '_' . $column . '_index');

                        // Cek RAW DB: Kalau belum terdaftar, dorong ke antrean eksekusi
                        if (!$this->indexExists($tableName, $indexName)) {
                            $columnsToIndex[$column] = $indexName;
                        }
                    }
                }

                // Barulah jalankan closure Blueprint HANYA jika ada kolom tersisa yang valid
                if (count($columnsToIndex) > 0) {
                    Schema::table($tableName, function (Blueprint $table) use ($columnsToIndex) {
                        foreach ($columnsToIndex as $col => $idxName) {
                            $table->index($col, $idxName);
                        }
                    });
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tablesAndColumns = [
            'transactions' => ['outlet_id', 'status', 'created_at_device', 'created_at', 'reservation_id'],
            'transaction_items' => ['status'],
            'tables' => ['is_occupied'],
            'reservations' => ['status', 'date'],
            'shifts' => ['status', 'created_at'],
            'payments' => ['status', 'transaction_id'],
            'web_orders' => ['status'],
            'staff' => ['outlet_id']
        ];

        foreach ($tablesAndColumns as $tableName => $columns) {
            if (Schema::hasTable($tableName)) {

                $columnsToDrop = [];

                foreach ($columns as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $indexName = strtolower($tableName . '_' . $column . '_index');

                        // Cek RAW DB: Drop hanya jika benar-benar eksis
                        if ($this->indexExists($tableName, $indexName)) {
                            $columnsToDrop[$column] = $indexName;
                        }
                    }
                }

                if (count($columnsToDrop) > 0) {
                    Schema::table($tableName, function (Blueprint $table) use ($columnsToDrop) {
                        foreach ($columnsToDrop as $col => $idxName) {
                            $table->dropIndex($idxName);
                        }
                    });
                }
            }
        }
    }
};
