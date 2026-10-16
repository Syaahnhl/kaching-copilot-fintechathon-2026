<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController as ApiAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\Api\CopilotActionApprovalController;
use App\Http\Controllers\Api\DemandForecastApiController;

/*
|--------------------------------------------------------------------------
| API Routes - Kaching Finance Copilot Evaluation Reference
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:strict');
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);

Route::get('/testing/set-plan', function (\Illuminate\Http\Request $request) {
    $plan = strtolower($request->query('plan', 'pro'));
    $email = 'testing@gmail.com';
    $user = \App\Models\User::where('email', $email)->first();
    if (!$user) {
        return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
    }
    $user->subscription_plan = $plan === 'expired' ? 'pro' : $plan;
    $user->subscription_expired_at = $plan === 'expired' ? now()->subDays(5) : now()->addYears(5);
    $user->account_status = 'active';
    $user->save();
    return response()->json(['status' => 'success', 'data' => ['plan' => $user->subscription_plan]]);
});

Route::middleware(['auth:sanctum', \App\Http\Middleware\CheckStoreActive::class])->group(function () {
    Route::post('/auth/refresh', [ApiAuthController::class, 'refreshToken'])->middleware('throttle:auth-refresh');
    Route::get('/user', function (Request $request) {
        return response()->json([
            'status' => 'success',
            'data' => $request->user()->load(['outlets', 'activeOutlet']),
        ]);
    });
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('throttle:60,1');
    Route::post('/verify-pin', [AuthController::class, 'verifyPin']);

    // SHIFTS (REKONSILIASI KAS UNTUK COPILOT)
    Route::prefix('shift')->group(function () {
        Route::post('/open', [ShiftController::class, 'openShift']);
        Route::post('/close', [ShiftController::class, 'closeShift']);
        Route::get('/check', [ShiftController::class, 'checkShift']);
        Route::post('/upload-report', [ShiftController::class, 'uploadReport']);
        Route::get('/history', [ShiftController::class, 'getHistory']);
    });

    // CASH FLOWS & EXPENSES
    Route::get('/cash-flows', [CashFlowController::class, 'index']);
    Route::get('/expenses', [ExpenseController::class, 'apiIndex'])->middleware('throttle:60,1');
    Route::post('/expenses', [ExpenseController::class, 'apiStore'])->middleware('throttle:30,1');

    // MENUS
    Route::get('/menus', [MenuController::class, 'apiIndex'])->middleware('throttle:60,1');

    // --- CHATBOT & COPILOT ACTION FIREWALL (TOPIC E) ---
    Route::post('/chat-help', [ChatbotController::class, 'sendMessage'])->middleware('throttle:10,1');
    Route::post('/copilot/actions/approve', [CopilotActionApprovalController::class, 'approve'])->middleware('throttle:10,1');
    Route::post('/copilot/actions/reject', [CopilotActionApprovalController::class, 'reject'])->middleware('throttle:30,1');

    // --- DEMAND FORECASTING (READ-ONLY CONSUMPTION LAYER) ---
    Route::get('/v1/demand-forecast/outlets/{outletId}', [DemandForecastApiController::class, 'getOutletForecast'])->middleware('throttle:60,1');
    Route::get('/demand-forecast/outlets/{outletId}', [DemandForecastApiController::class, 'getOutletForecast'])->middleware('throttle:60,1');
});
