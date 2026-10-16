<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function indexExists($tableName, $indexName)
    {
        if (DB::getDriverName() === 'sqlite') {
            $results = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND name=?", [$indexName]);
            return count($results) > 0;
        }
        $indexes = DB::select("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Expenses -> user_id, date
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (!$this->indexExists('expenses', 'expenses_user_id_index')) {
                    $table->index('user_id');
                }
                if (!$this->indexExists('expenses', 'expenses_date_index')) {
                    $table->index('date');
                }
            });
        }

        // Attendances -> staff_id, created_at
        if (Schema::hasTable('attendances')) {
            Schema::table('attendances', function (Blueprint $table) {
                if (!$this->indexExists('attendances', 'attendances_staff_id_index')) {
                    $table->index('staff_id');
                }
                if (!$this->indexExists('attendances', 'attendances_created_at_index')) {
                    $table->index('created_at');
                }
            });
        }

        // Outlets -> user_id
        if (Schema::hasTable('outlets')) {
            Schema::table('outlets', function (Blueprint $table) {
                if (!$this->indexExists('outlets', 'outlets_user_id_index')) {
                    $table->index('user_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if ($this->indexExists('expenses', 'expenses_user_id_index')) {
                    $table->dropIndex(['user_id']);
                }
                if ($this->indexExists('expenses', 'expenses_date_index')) {
                    $table->dropIndex(['date']);
                }
            });
        }

        if (Schema::hasTable('attendances')) {
            Schema::table('attendances', function (Blueprint $table) {
                if ($this->indexExists('attendances', 'attendances_staff_id_index')) {
                    $table->dropIndex(['staff_id']);
                }
                if ($this->indexExists('attendances', 'attendances_created_at_index')) {
                    $table->dropIndex(['created_at']);
                }
            });
        }

        if (Schema::hasTable('outlets')) {
            Schema::table('outlets', function (Blueprint $table) {
                if ($this->indexExists('outlets', 'outlets_user_id_index')) {
                    $table->dropIndex(['user_id']);
                }
            });
        }
    }
};
