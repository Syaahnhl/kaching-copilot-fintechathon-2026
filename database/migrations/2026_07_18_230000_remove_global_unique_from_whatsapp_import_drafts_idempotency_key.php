<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_import_drafts', function (Blueprint $table): void {
            $table->dropUnique('whatsapp_import_drafts_idempotency_key_unique');
            $table->index('idempotency_key', 'whatsapp_import_drafts_idempotency_key_index');
        });
    }

    public function down(): void
    {
        $duplicates = DB::table('whatsapp_import_drafts')
            ->select('idempotency_key', DB::raw('COUNT(*) as aggregate'))
            ->whereNotNull('idempotency_key')
            ->groupBy('idempotency_key')
            ->having('aggregate', '>', 1)
            ->limit(1)
            ->exists();

        if ($duplicates) {
            throw new \RuntimeException('Cannot restore global unique idempotency_key on whatsapp_import_drafts because duplicate keys exist. Manual test/dev data cleanup is required before rolling back this migration.');
        }

        Schema::table('whatsapp_import_drafts', function (Blueprint $table): void {
            $table->dropIndex('whatsapp_import_drafts_idempotency_key_index');
            $table->unique('idempotency_key', 'whatsapp_import_drafts_idempotency_key_unique');
        });
    }
};
