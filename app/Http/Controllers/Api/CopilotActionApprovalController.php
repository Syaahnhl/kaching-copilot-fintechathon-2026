<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\Staff;
use App\Models\Transaction;
use App\Services\AuditLogger;
use App\Services\Policy\ActionPolicyService;
use App\Services\StockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * 🛡️ [B.8] CopilotActionApprovalController — Two-Phase Human Authorization & Action Execution Endpoint
 *
 * Prinsip:
 * 1. AI Copilot TIDAK PERNAH memanggil controller ini secara langsung.
 * 2. Hanya dipanggil oleh client UI resmi saat merchant/manajer memasukkan Manager PIN untuk menyetujui proposal.
 * 3. Eksekusi mutasi bersifat strictly whitelisted, deterministic, dan tercatat di AuditLogger.
 * 4. Proposal cache di-invalidate segera setelah eksekusi agar tidak dapat dieksekusi ganda (exactly-once).
 */
class CopilotActionApprovalController extends Controller
{
    /**
     * Setujui dan eksekusi proposal aksi bisnis dengan verifikasi Manager PIN.
     *
     * POST /api/copilot/actions/approve
     */
    public function approve(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'proposal_id' => 'required|string',
            'staff_id'    => 'required|integer',
            'pin'         => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'VALIDATION_FAILED',
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'UNAUTHORIZED',
                'message' => 'Sesi pengguna tidak valid.',
            ], 401);
        }

        $userId = (int) $user->id;
        $proposalId = (string) $request->input('proposal_id');

        // P1: Atomic Lock Acquisition
        // Gunakan Cache::lock dengan TTL 15 detik untuk mencegah konkurensi double execution
        $lockKey = "copilot:approval-lock:{$proposalId}";
        $lock = Cache::lock($lockKey, 15);

        if (!$lock->get()) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'PROPOSAL_LOCK_CONFLICT',
                'message' => 'Proposal sedang diproses oleh permintaan lain. Silakan tunggu.',
            ], 409);
        }

        try {
            /** @var ActionPolicyService $policyService */
            $policyService = app(ActionPolicyService::class);
            $proposal = $policyService->getProposal($proposalId);

            // 1. Validasi Keberadaan Proposal
            if (!$proposal) {
                return response()->json([
                    'status'  => 'error',
                    'code'    => 'PROPOSAL_NOT_FOUND',
                    'message' => 'Proposal tidak ditemukan atau telah kedaluwarsa.',
                ], 404);
            }

        // 2. Tenant Isolation: Proposal harus milik merchant yang login
        if ((int) $proposal['user_id'] !== $userId) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'CROSS_USER_FORBIDDEN',
                'message' => 'Proposal ini bukan milik merchant yang sedang aktif.',
            ], 403);
        }

        // 3. Outlet Isolation: Pastikan outlet milik merchant
        if (!empty($proposal['outlet_id'])) {
            $outletExists = Outlet::where('user_id', $userId)
                ->where('id', $proposal['outlet_id'])
                ->exists();

            if (!$outletExists) {
                return response()->json([
                    'status'  => 'error',
                    'code'    => 'CROSS_OUTLET_FORBIDDEN',
                    'message' => 'Outlet pada proposal tidak valid atau bukan milik merchant.',
                ], 403);
            }
        }

        // 4. Validasi Kedaluwarsa Proposal
        if (Carbon::parse($proposal['expires_at'])->isPast()) {
            $policyService->invalidateProposal($proposalId);
            return response()->json([
                'status'  => 'error',
                'code'    => 'PROPOSAL_EXPIRED',
                'message' => 'Proposal aksi telah kedaluwarsa.',
            ], 410);
        }

        // 5. Validasi Keputusan Policy (Hanya REQUIRES_APPROVAL atau ALLOW yang boleh diproses)
        if (in_array($proposal['decision'], [ActionPolicyService::DECISION_DENY, ActionPolicyService::DECISION_UNSUPPORTED], true)) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'POLICY_DENIED',
                'message' => 'Proposal aksi ditolak oleh policy firewall dan tidak dapat dieksekusi.',
            ], 403);
        }

        // 6. Validasi Staf Approver & Hak Akses
        $staffId = (int) $request->input('staff_id');
        $staff = Staff::where('id', $staffId)
            ->where('user_id', $userId)
            ->first();

        if (!$staff || !$staff->is_active) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'STAFF_NOT_FOUND',
                'message' => 'Staf approver tidak ditemukan atau tidak aktif.',
            ], 403);
        }

        // Validasi Scoping Outlet Staf
        if ($staff->outlet_id !== null && !empty($proposal['outlet_id']) && (int) $staff->outlet_id !== (int) $proposal['outlet_id']) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'CROSS_OUTLET_STAFF',
                'message' => 'Staf tidak memiliki wewenang pada outlet proposal ini.',
            ], 403);
        }

        // Validasi Hierarki Role
        $staffRole = strtoupper((string) $staff->role);
        $requiredRole = strtoupper((string) ($proposal['required_role'] ?? 'OWNER'));

        $roleHierarchy = [
            'WAITER'  => 1,
            'KITCHEN' => 1,
            'KASIR'   => 2,
            'MANAGER' => 3,
            'OWNER'   => 4,
        ];

        $staffLevel = $roleHierarchy[$staffRole] ?? 0;
        $requiredLevel = $roleHierarchy[$requiredRole] ?? 99;

        if ($staffLevel < $requiredLevel) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'FORBIDDEN_ROLE',
                'message' => "Peran staf ({$staffRole}) tidak mencukupi untuk menyetujui aksi ini (membutuhkan minimal {$requiredRole}).",
            ], 403);
        }

        // 7. Verifikasi PIN & Rate Limiting Brute Force Protection
        $throttleKey = 'pin-attempt:' . $staffId . ':' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'status'  => 'error',
                'code'    => 'PIN_RATE_LIMITED',
                'message' => "Terlalu banyak percobaan PIN salah. Coba lagi dalam {$seconds} detik.",
            ], 429);
        }

        $pin = (string) $request->input('pin', '');
        if (!$staff->pinMatches($pin)) {
            RateLimiter::hit($throttleKey, 300);
            return response()->json([
                'status'  => 'error',
                'code'    => 'INVALID_PIN',
                'message' => 'PIN otorisasi manajer salah.',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        // 8. Eksekusi Mutasi Bisnis (Whitelisted & Deterministic Mapping)
        try {
            $mutationResult = match ($proposal['action']) {
                'expense.create'     => $this->executeExpenseCreate($userId, $proposal['outlet_id'], $proposal['parameters']),
                'menu.price_change'  => $this->executeMenuPriceChange($userId, $proposal['outlet_id'], $proposal['parameters']),
                'stock.adjust'       => $this->executeStockAdjust($userId, $proposal['outlet_id'], $proposal['parameters'], $staff->id, $proposalId),
                'transaction.cancel' => $this->executeTransactionCancel($userId, $proposal['outlet_id'], $proposal['parameters']),
                default              => throw new \InvalidArgumentException("Aksi mutasi '{$proposal['action']}' tidak didukung."),
            };
        } catch (\Throwable $e) {
            Log::error('CopilotActionApproval execution error: ' . $e->getMessage(), [
                'proposal_id' => $proposal['proposal_id'],
                'action'      => $proposal['action'],
                'user_id'     => $userId,
            ]);

            return response()->json([
                'status'  => 'error',
                'code'    => 'MUTATION_FAILED',
                'message' => 'Gagal mengeksekusi mutasi bisnis: ' . $e->getMessage(),
            ], 500);
        }

        // 9. Invalidate Proposal Cache SEGERA setelah mutasi berhasil (Menjamin Exactly-Once Execution di dalam lock)
        $policyService->invalidateProposal($proposalId);

        // 10. Pencatatan Audit Log Forensik Resmi
        AuditLogger::log(
            action: $this->mapAuditAction($proposal['action']),
            target: $mutationResult['target'],
            before: $mutationResult['before'],
            after: $mutationResult['after'],
            metadata: [
                'proposal_id'        => $proposal['proposal_id'],
                'actor_user_id'      => $userId,
                'approver_staff_id'  => $staff->id,
                'approver_role'      => $staffRole,
                'outlet_id'          => $proposal['outlet_id'],
                'action'             => $proposal['action'],
                'approval_timestamp' => now()->toIso8601String(),
                'source'             => 'copilot',
            ],
            request: $request
        );

        // 11. Kembalikan Execution Receipt
        return response()->json([
            'status'  => 'success',
            'code'    => 'ACTION_EXECUTED',
            'message' => 'Aksi berhasil disetujui dan dieksekusi secara sah.',
            'data'    => [
                'proposal_id' => $proposal['proposal_id'],
                'action'      => $proposal['action'],
                'status'      => 'EXECUTED',
                'executed_at' => now()->toIso8601String(),
                'approver'    => [
                    'staff_id'   => $staff->id,
                    'staff_name' => $staff->staff_name,
                    'role'       => $staffRole,
                ],
                'receipt'     => $mutationResult['receipt'],
            ],
        ], 200);
        } finally {
            $lock->release();
        }
    }

    /**
     * Tolak proposal aksi bisnis dan hapus dari cache.
     *
     * POST /api/copilot/actions/reject
     */
    public function reject(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'proposal_id' => 'required|string',
            'reason'      => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'VALIDATION_FAILED',
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'UNAUTHORIZED',
                'message' => 'Sesi pengguna tidak valid.',
            ], 401);
        }

        $userId = (int) $user->id;

        /** @var ActionPolicyService $policyService */
        $policyService = app(ActionPolicyService::class);
        $proposalId = (string) $request->input('proposal_id');
        $proposal = $policyService->getProposal($proposalId);

        if (!$proposal) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'PROPOSAL_NOT_FOUND',
                'message' => 'Proposal tidak ditemukan atau telah kedaluwarsa.',
            ], 404);
        }

        if ((int) $proposal['user_id'] !== $userId) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'CROSS_USER_FORBIDDEN',
                'message' => 'Proposal ini bukan milik merchant yang sedang aktif.',
            ], 403);
        }

        // Invalidate proposal dari cache tanpa mutasi database
        $policyService->invalidateProposal($proposalId);

        return response()->json([
            'status'  => 'success',
            'code'    => 'ACTION_REJECTED',
            'message' => 'Proposal aksi berhasil ditolak dan dibatalkan.',
            'data'    => [
                'proposal_id' => $proposal['proposal_id'],
                'action'      => $proposal['action'],
                'status'      => 'REJECTED',
                'rejected_at' => now()->toIso8601String(),
                'reason'      => $request->input('reason', 'Ditolak oleh pengguna.'),
            ],
        ], 200);
    }

    /**
     * Mutasi: Pencatatan beban pengeluaran (expense.create)
     */
    protected function executeExpenseCreate(int $userId, ?int $outletId, array $parameters): array
    {
        $date = $parameters['expense_date'] ?? $parameters['date'] ?? now()->toDateString();
        $note = $parameters['notes'] ?? $parameters['note'] ?? 'Dibuat via Copilot Proposal';

        $expense = Expense::forceCreate([
            'user_id'     => $userId,
            'outlet_id'   => $outletId,
            'name'        => $parameters['name'],
            'amount'      => (float) $parameters['amount'],
            'category'    => $parameters['category'] ?? 'Operasional',
            'date'        => $date,
            'note'        => $note,
            'fund_source' => $parameters['payment_method'] ?? 'Laci Kasir',
            'qty'         => 1.0,
            'unit_price'  => (float) $parameters['amount'],
        ]);

        return [
            'target'  => $expense,
            'before'  => null,
            'after'   => $expense->toArray(),
            'receipt' => [
                'expense_id' => $expense->id,
                'name'       => $expense->name,
                'amount'     => (float) $expense->amount,
                'category'   => $expense->category,
                'date'       => $expense->date,
                'note'       => $expense->note,
            ],
        ];
    }

    /**
     * Mutasi: Penyesuaian harga menu (menu.price_change)
     */
    protected function executeMenuPriceChange(int $userId, ?int $outletId, array $parameters): array
    {
        $menu = Menu::where('user_id', $userId)
            ->where('id', (int) $parameters['menu_id'])
            ->first();

        if (!$menu) {
            throw new \RuntimeException("Menu dengan ID {$parameters['menu_id']} tidak ditemukan.");
        }

        $oldPrice = (float) $menu->price;
        $newPrice = (float) $parameters['new_price'];
        $menu->price = $newPrice;
        $menu->save();

        return [
            'target'  => $menu,
            'before'  => ['price' => $oldPrice],
            'after'   => ['price' => $newPrice],
            'receipt' => [
                'menu_id'   => $menu->id,
                'menu_name' => $menu->name,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'reason'    => $parameters['reason'] ?? null,
            ],
        ];
    }

    /**
     * Mutasi: Koreksi inventori stok (stock.adjust) melalui canonical StockService.
     */
    protected function executeStockAdjust(int $userId, ?int $outletId, array $parameters, ?int $staffId = null, ?string $proposalId = null): array
    {
        $menuId = (int) $parameters['menu_id'];
        $menu = Menu::where('user_id', $userId)
            ->where('id', $menuId)
            ->first();

        if (!$menu) {
            throw new \RuntimeException("Menu dengan ID {$menuId} tidak ditemukan.");
        }

        $qty = (float) $parameters['quantity'];
        $type = (string) $parameters['type'];
        $notes = $parameters['notes'] ?? $parameters['reason'] ?? 'Penyesuaian stok via Copilot';
        $targetOutletId = ($menu->outlet_id && (int) $menu->outlet_id > 0) ? (int) $menu->outlet_id : (int) ($outletId ?? 0);
        $txUuid = (string) Str::uuid();

        /** @var StockService $stockService */
        $stockService = app(StockService::class);

        if ($type === 'opname' || $type === 'adjustment') {
            $result = $stockService->stockOpname(
                $menuId,
                $userId,
                $targetOutletId,
                $qty,
                null,
                $txUuid,
                $staffId,
                null,
                $notes
            );
        } else {
            $delta = match ($type) {
                'increment' => abs($qty),
                'decrement' => -abs($qty),
                default     => throw new \InvalidArgumentException("Tipe penyesuaian stok tidak valid: {$type}"),
            };

            $result = $stockService->mutateDelta(
                $menuId,
                $userId,
                $targetOutletId,
                $delta,
                $type,
                $txUuid,
                $staffId,
                null,
                'copilot_proposal',
                $proposalId,
                $notes
            );
        }

        if (isset($result['status']) && $result['status'] === 'error') {
            throw new \RuntimeException($result['message'] ?? 'Gagal memperbarui stok melalui StockService.');
        }

        $oldStock = (float) ($result['data']['balance_before'] ?? $menu->stock);
        $newStock = (float) ($result['data']['balance_after'] ?? $menu->stock);

        return [
            'target'  => $menu,
            'before'  => ['stock' => $oldStock],
            'after'   => ['stock' => $newStock, 'type' => $type],
            'receipt' => [
                'menu_id'        => $menu->id,
                'menu_name'      => $menu->name,
                'old_stock'      => $oldStock,
                'new_stock'      => $newStock,
                'type'           => $type,
                'reason'         => $notes,
                'tx_uuid'        => $txUuid,
                'stock_movement' => $result['code'] ?? 'APPLIED',
            ],
        ];
    }

    /**
     * Mutasi: Pembatalan transaksi (transaction.cancel)
     */
    protected function executeTransactionCancel(int $userId, ?int $outletId, array $parameters): array
    {
        $transaction = Transaction::where('user_id', $userId)
            ->where('id', (int) $parameters['transaction_id'])
            ->first();

        if (!$transaction) {
            throw new \RuntimeException("Transaksi ID {$parameters['transaction_id']} tidak ditemukan.");
        }

        if ($transaction->status === 'Batal') {
            throw new \RuntimeException("Transaksi ID {$parameters['transaction_id']} sudah berstatus Batal.");
        }

        $oldStatus = $transaction->status;
        $reason = (string) ($parameters['reason'] ?? 'Dibatalkan melalui persetujuan Copilot');

        $transaction->status = 'Batal';
        $transaction->cancel_reason = $reason;
        $transaction->save();

        return [
            'target'  => $transaction,
            'before'  => ['status' => $oldStatus],
            'after'   => ['status' => 'Batal', 'cancel_reason' => $reason],
            'receipt' => [
                'transaction_id' => $transaction->id,
                'old_status'     => $oldStatus,
                'new_status'     => 'Batal',
                'cancel_reason'  => $reason,
                'reason'         => $reason,
                'total_amount'   => (float) $transaction->total_amount,
            ],
        ];
    }

    /**
     * Map nama aksi proposal ke konstanta AuditLog.
     */
    protected function mapAuditAction(string $action): string
    {
        return match ($action) {
            'expense.create'     => AuditLog::ACTION_EXPENSE_CREATE,
            'menu.price_change'  => AuditLog::ACTION_MENU_PRICE_CHANGE,
            'stock.adjust'       => 'stock.adjust',
            'transaction.cancel' => AuditLog::ACTION_TRANSACTION_CANCEL,
            default              => 'copilot.action_approved',
        };
    }
}
