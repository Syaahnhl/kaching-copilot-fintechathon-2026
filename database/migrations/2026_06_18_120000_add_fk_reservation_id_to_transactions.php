<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B2 fix 2026-06-18: Add FK constraint + change column type
     * transactions.reservation_id (INT) → BIGINT UNSIGNED to match reservations.id
     * Pre-conditions: 57 orphan reservation_id set to NULL (done in tinker before migrate)
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // 1. Change column type to match reservations.id (BIGINT UNSIGNED)
            DB::statement('ALTER TABLE transactions MODIFY COLUMN reservation_id BIGINT UNSIGNED NULL');

            // 2. Add FK constraint with nullOnDelete (if reservation deleted, transaction.reservation_id = NULL)
            DB::statement('
                ALTER TABLE transactions
                ADD CONSTRAINT transactions_reservation_id_foreign
                FOREIGN KEY (reservation_id)
                REFERENCES reservations(id)
                ON DELETE SET NULL
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE transactions DROP FOREIGN KEY transactions_reservation_id_foreign');
            DB::statement('ALTER TABLE transactions MODIFY COLUMN reservation_id INT(11) NULL');
        }
    }
};
