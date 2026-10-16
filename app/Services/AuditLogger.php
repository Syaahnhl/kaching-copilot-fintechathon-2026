<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 🛡️ [PROMETHEUS FIX 2026-06-18] Audit Logger service (FINDING-07)
 *
 * Helper untuk mencatat aksi sensitif ke tabel audit_logs.
 * Panggil dari controller SETELAH aksi berhasil (bukan sebelum).
 *
 * Contoh penggunaan:
 * ```php
 * AuditLogger::log(
 *     action: AuditLog::ACTION_TRANSACTION_CANCEL,
 *     target: $transaction,
 *     before: null,
 *     after: ['status' => 'Batal', 'reason' => $reason],
 *     metadata: ['amount' => $transaction->total_amount]
 * );
 * ```
 */
class AuditLogger
{
    /**
     * Record audit log entry.
     *
     * @param string $action Salah satu dari AuditLog::ACTION_*
     * @param Model|null $target Model yang di-action (optional)
     * @param array|null $before Snapshot sebelum perubahan
     * @param array|null $after Snapshot sesudah perubahan
     * @param array|null $metadata Extra context (reason, amount, etc)
     * @param Request|null $request HTTP request (untuk IP & user-agent)
     * @return AuditLog
     */
    public static function log(
        string $action,
        $target = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
        ?Request $request = null
    ): AuditLog {
        $request = $request ?? (request() instanceof Request ? request() : null);

        $userId = Auth::id();
        if (!$userId && $target && is_object($target) && property_exists($target, 'user_id')) {
            $userId = $target->user_id;
        }

        return AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'target_type' => $target ? (is_object($target) ? get_class($target) : null) : null,
            'target_id' => $target ? (is_object($target) && method_exists($target, 'getKey') ? (int) $target->getKey() : (is_object($target) && property_exists($target, 'id') ? (int) $target->id : null)) : null,
            'before_values' => $before,
            'after_values' => $after,
            'metadata' => $metadata,
            'ip_address' => $request ? $request->ip() : null,
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    /**
     * Log login attempt (success atau failed).
     */
    public static function logLogin(int $userId, bool $success, ?string $reason = null): AuditLog
    {
        return self::log(
            action: $success ? AuditLog::ACTION_LOGIN_SUCCESS : AuditLog::ACTION_LOGIN_FAILED,
            metadata: ['user_id' => $userId, 'reason' => $reason]
        );
    }

    /**
     * Compute diff between two model states.
     * Berguna untuk change tracking.
     */
    public static function diff($model, array $originalAttributes): array
    {
        $changes = [];
        foreach ($originalAttributes as $key => $oldValue) {
            $newValue = $model->{$key} ?? null;
            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'before' => $oldValue,
                    'after' => $newValue,
                ];
            }
        }
        return $changes;
    }
}