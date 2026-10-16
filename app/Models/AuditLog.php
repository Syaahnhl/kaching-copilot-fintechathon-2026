<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🛡️ [PROMETHEUS FIX 2026-06-18] Audit log model (FINDING-07)
 *
 * Tracking semua aksi sensitif user untuk forensik & compliance.
 * Tabel: audit_logs
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'action',
        'target_type',
        'target_id',
        'before_values',
        'after_values',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'before_values' => 'array',
        'after_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Action constants untuk konsistensi.
     */
    public const ACTION_TRANSACTION_CANCEL = 'transaction.cancel';
    public const ACTION_TRANSACTION_COMPLETE = 'transaction.complete';
    public const ACTION_TRANSACTION_UNDO = 'transaction.undo';
    public const ACTION_REFUND_ITEM = 'refund.item';
    public const ACTION_MENU_CREATE = 'menu.create';
    public const ACTION_MENU_UPDATE = 'menu.update';
    public const ACTION_MENU_DELETE = 'menu.delete';
    public const ACTION_MENU_PRICE_CHANGE = 'menu.price_change';
    public const ACTION_EXPENSE_CREATE = 'expense.create';
    public const ACTION_EXPENSE_UPDATE = 'expense.update';
    public const ACTION_EXPENSE_DELETE = 'expense.delete';
    public const ACTION_STAFF_CREATE = 'staff.create';
    public const ACTION_STAFF_UPDATE = 'staff.update';
    public const ACTION_STAFF_DELETE = 'staff.delete';
    public const ACTION_LOGIN_SUCCESS = 'auth.login_success';
    public const ACTION_LOGIN_FAILED = 'auth.login_failed';
    public const ACTION_PASSWORD_CHANGE = 'auth.password_change';

    /**
     * Relasi ke User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault(['name' => 'System/Anonymous']);
    }

    /**
     * Scope: query by action.
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope: query by target.
     */
    public function scopeTarget($query, string $type, int $id)
    {
        return $query->where('target_type', $type)->where('target_id', $id);
    }

    /**
     * Scope: query by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: query by date range.
     */
    public function scopeBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
}