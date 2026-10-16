<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_import_idempotencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->foreignId('draft_id')->constrained('whatsapp_import_drafts')->cascadeOnDelete();
            $table->string('request_fingerprint', 64);
            $table->string('status', 32)->default('processing');
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->string('last_error_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'outlet_id', 'idempotency_key'], 'wa_import_idem_scope_unique');
            $table->index('draft_id', 'wa_import_idem_draft_idx');
            $table->index('reservation_id', 'wa_import_idem_reservation_idx');
            $table->index(['status', 'created_at'], 'wa_import_idem_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_import_idempotencies');
    }
};
