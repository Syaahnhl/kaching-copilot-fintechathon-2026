<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\RawMaterialIntelligenceController;
use App\Http\Controllers\KachingIntelligenceController;
use App\Http\Controllers\ScenarioSimulatorController;
use App\Http\Controllers\RiskRadarController;
use App\Http\Controllers\DemandForecastWebController;

/*
|--------------------------------------------------------------------------
| Web Routes - Kaching Finance Copilot Evaluation Reference
|--------------------------------------------------------------------------
*/

Route::get('/lang/{locale}', function ($locale) {
    if (in_array($locale, ['id', 'en'])) {
        session()->put('locale', $locale);
    }
    return back();
})->name('lang.switch');

Route::get('/', function () {
    return redirect()->route('copilot.copilot_chat');
})->name('home');

// --- TESTING BYPASS SUBSCRIBER TIER ---
Route::get('/testing/set-plan', function (\Illuminate\Http\Request $request) {
    $plan = strtolower($request->query('plan', 'pro'));
    $email = 'testing@gmail.com';
    
    if (!in_array($plan, ['free', 'trial', 'basic', 'pro', 'ultimate', 'expired'])) {
        return response()->json([
            'status' => 'error',
            'message' => 'Plan tidak valid. Pilihan: free, trial, basic, pro, ultimate, expired'
        ], 400);
    }
    
    $user = \App\Models\User::where('email', $email)->first();
    if (!$user) {
        return response()->json([
            'status' => 'error',
            'message' => 'User testing@gmail.com tidak ditemukan'
        ], 404);
    }
    
    $user->subscription_plan = $plan === 'expired' ? 'pro' : $plan;
    $user->subscription_expired_at = $plan === 'expired' ? now()->subDays(5) : now()->addYears(5);
    $user->account_status = 'active';
    $user->save();
    
    return response()->json([
        'status' => 'success',
        'message' => "Paket untuk {$email} diset ke " . strtoupper($plan),
        'data' => [
            'email' => $user->email,
            'subscription_plan' => $user->subscription_plan,
            'subscription_expired_at' => $user->subscription_expired_at,
            'account_status' => $user->account_status
        ]
    ]);
});

// --- WEB CHATBOT AI ---
Route::post('/chat', [ChatbotController::class, 'sendMessage'])->name('web.chat')->middleware('throttle:10,1');

// --- AUTH ---
Route::get('/login', function () {
    return view('auth.login');
})->name('login');
Route::post('/web-login', [AuthController::class, 'webLogin'])->name('web.login');
Route::get('/register', function () {
    return view('auth.login');
})->name('register');
Route::post('/web-register', [AuthController::class, 'webRegister'])->name('web.register');
Route::get('/verify-otp', [AuthController::class, 'showWebVerifyOtp'])->name('web.verify-otp.show');
Route::post('/verify-otp', [AuthController::class, 'webVerifyOtp'])->name('web.verify-otp');
Route::post('/verify-otp/resend', [AuthController::class, 'webResendOtp'])->name('web.resend-otp');

Route::get('/auth/google', [GoogleController::class, 'redirectToGoogle'])->name('google.login');
Route::get('/auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);

// ======================================================================
// 🛡️ ROUTE WAJIB LOGIN (BACKOFFICE COPILOT & FINANCE)
// ======================================================================
Route::middleware(['auth'])->group(function () {
    Route::post('/web-logout', [AuthController::class, 'webLogout'])->name('web.logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // OPERASIONAL & MENU (HPP & RECIPE SSOT FOR COPILOT)
    Route::resource('menus', MenuController::class)->except(['create', 'show']);
    Route::prefix('menus/{menu}/recipe')->name('menus.recipe.')->group(function () {
        Route::get('/', [RecipeController::class, 'index'])->name('index');
        Route::post('/', [RecipeController::class, 'store'])->name('store');
        Route::put('/{ingredient}', [RecipeController::class, 'update'])->name('update');
        Route::delete('/{ingredient}', [RecipeController::class, 'destroy'])->name('destroy');
    });

    // CASH FLOWS & EXPENSES
    Route::get('/cash-flows', [CashFlowController::class, 'webIndex'])->name('cash_flows.index');
    Route::resource('expenses', ExpenseController::class);

    // SHIFTS (REKONSILIASI KAS UNTUK COPILOT)
    Route::prefix('shifts')->name('shifts.')->group(function () {
        Route::get('/', [ShiftController::class, 'webIndex'])->name('index');
        Route::get('/{id}', [ShiftController::class, 'webShow'])->name('show');
    });

    // LAPORAN & ANALISIS (DSS)
    Route::prefix('reports')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/export', [ReportController::class, 'index'])->name('reports.export');
    });
    Route::get('/analysis/menu-performance', [AnalysisController::class, 'index'])->name('reports.menu-analysis');
    Route::get('/api/analysis/menu-data', [AnalysisController::class, 'getMenuAnalysis'])->name('api.menu-analysis');

    // --- TOPIC E: SME FINANCE COPILOT INTERACTIVE WEB ROUTES ---
    Route::get('/copilot/raw-material-intelligence', [RawMaterialIntelligenceController::class, 'index'])->name('copilot.raw-material-intelligence');
    Route::get('/copilot/intelligence', [KachingIntelligenceController::class, 'index'])->name('copilot.intelligence');
    Route::get('/copilot/intelligence/menu', [KachingIntelligenceController::class, 'menuDetail'])->name('copilot.intelligence.menu');
    Route::get('/copilot/financing-readiness/export', [KachingIntelligenceController::class, 'exportDossier'])->name('copilot.financing-readiness.export');

    Route::get('/intelligence/scenario', [ScenarioSimulatorController::class, 'index'])->name('copilot.intelligence.scenario');
    Route::post('/intelligence/scenario/simulate', [ScenarioSimulatorController::class, 'simulate'])->name('copilot.intelligence.scenario.simulate');
    Route::get('/copilot/intelligence/scenario', [ScenarioSimulatorController::class, 'index'])->name('copilot.intelligence.scenario.alias');
    Route::post('/copilot/intelligence/scenario/simulate', [ScenarioSimulatorController::class, 'simulate'])->name('copilot.intelligence.scenario.simulate.alias');

    Route::get('/intelligence/risk-radar', [RiskRadarController::class, 'index'])->name('copilot.intelligence.risk-radar');
    Route::post('/intelligence/risk-radar/evaluate', [RiskRadarController::class, 'evaluate'])->name('copilot.intelligence.risk-radar.evaluate');
    Route::get('/copilot/intelligence/risk-radar', [RiskRadarController::class, 'index'])->name('copilot.intelligence.risk-radar.alias');
    Route::post('/copilot/intelligence/risk-radar/evaluate', [RiskRadarController::class, 'evaluate'])->name('copilot.intelligence.risk-radar.evaluate.alias');

    Route::get('/intelligence/demand-forecast', [DemandForecastWebController::class, 'index'])->name('copilot.intelligence.demand-forecast');
    Route::get('/copilot/intelligence/demand-forecast', [DemandForecastWebController::class, 'index'])->name('copilot.intelligence.demand-forecast.alias');
    Route::get('/intelligence/demand-forecast/data', [DemandForecastWebController::class, 'data'])->name('copilot.intelligence.demand-forecast.data');
    Route::get('/copilot/intelligence/demand-forecast/data', [DemandForecastWebController::class, 'data'])->name('copilot.intelligence.demand-forecast.data.alias');

    Route::get('/copilot/copilot_chat', function () {
        return view('backoffice.copilot_chat');
    })->name('copilot.copilot_chat');

    Route::post('/copilot/chat', [ChatbotController::class, 'sendMessage'])
        ->name('copilot.chat')
        ->middleware('throttle:30,1');

    Route::get('/copilot/menu-margin/candidates', [ChatbotController::class, 'menuMarginCandidates'])
        ->name('copilot.menu-margin.candidates');
    Route::post('/copilot/menu-margin/simulate', [ChatbotController::class, 'menuMarginSimulate'])
        ->name('copilot.menu-margin.simulate');
    Route::post('/copilot/menu-margin/propose', [ChatbotController::class, 'menuMarginPropose'])
        ->name('copilot.menu-margin.propose');

    // INVENTORY & SUPPLIERS (GROUNDING DATA)
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', [InventoryController::class, 'index'])->name('index');
        Route::get('/data', [InventoryController::class, 'data'])->name('data');
        Route::post('/store', [InventoryController::class, 'store'])->name('store');
        Route::put('/{id}', [InventoryController::class, 'update'])->name('update');
        Route::delete('/{id}', [InventoryController::class, 'destroy'])->name('destroy');

        Route::get('/procurement', [ProcurementController::class, 'index'])->name('procurement');
        Route::get('/procurement/data', [ProcurementController::class, 'data'])->name('procurement.data');
        Route::get('/procurement/recommendations', [ProcurementController::class, 'getRestockRecommendations'])->name('procurement.recommendations');
    });

    Route::resource('suppliers', SupplierController::class);
    Route::get('/suppliers/{supplier}/items', [SupplierController::class, 'getItems'])->name('suppliers.items');
    Route::post('/suppliers/{supplier}/items', [SupplierController::class, 'attachItem'])->name('suppliers.items.attach');
    Route::delete('/suppliers/{supplier}/items/{item}', [SupplierController::class, 'detachItem'])->name('suppliers.items.detach');

    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
    Route::resource('outlets', OutletController::class);
    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
});
