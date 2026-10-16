<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_import_drafts', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1)->after('status');
            $table->boolean('duplicate_acknowledged')->default(false)->after('message_hash');
            $table->string('duplicate_reason', 255)->nullable()->after('duplicate_acknowledged');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_import_drafts', function (Blueprint $table): void {
            $table->dropColumn(['lock_version', 'duplicate_acknowledged', 'duplicate_reason']);
        });
    }
};
