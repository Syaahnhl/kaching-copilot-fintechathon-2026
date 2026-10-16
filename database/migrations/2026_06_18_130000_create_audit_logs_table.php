<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     🛡️ [PROMETHEUS FIX 2026-06-18] Audit log table untuk forensik & compliance
     * FINDING-07: Track semua aksi sensitif (cancel, refund, change price, delete menu).
     *
     * Tabel ini menyimpan jejak digital untuk:
     * - Investigasi fraud internal (siapa ngapain, kapan, dari mana)
     * - Compliance UU PDP / GDPR (right to access, audit trail)
     * - Forensic analysis (rollback capability via before/after diff)
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100); // e.g. "transaction.cancel", "refund.item"
            $table->string('target_type', 100)->nullable(); // e.g. "Transaction", "Menu"
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('before_values')->nullable(); // Snapshot sebelum perubahan
            $table->json('after_values')->nullable();  // Snapshot sesudah perubahan
            $table->json('metadata')->nullable(); // Extra context (reason, dll)
            $table->string('ip_address', 45)->nullable(); // IPv4/IPv6
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            // Indexes untuk query performance
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};