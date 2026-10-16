<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_import_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->longText('raw_text');
            $table->string('source_phone')->nullable();
            $table->json('parsed_json');
            $table->string('status', 32)->default('needs_review');
            $table->string('message_hash', 64);
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key')->nullable();
            $table->string('parser_version', 32);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamps();

            $table->index(['outlet_id', 'message_hash']);
            $table->index('reservation_id');
            $table->index(['status', 'outlet_id']);
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_import_drafts');
    }
};
