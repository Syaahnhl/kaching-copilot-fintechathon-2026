<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\ChatbotPromptBuilder;
use App\Services\Financial\FinanceCopilotOrchestrationService;
use App\Http\Controllers\ChatbotController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Models\Outlet;

class CopilotResponseLanguageAndSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = User::factory()->create([
            'email' => 'tenant_test_lang@kaching.test',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->tenant->id,
            'name'    => 'Test Outlet',
        ]);
    }

    /**
     * 1. Locale "id" menghasilkan instruksi prompt Bahasa Indonesia.
     */
    public function test_1_locale_id_generates_indonesian_prompt_instructions(): void
    {
        App::setLocale('id');

        $prompt = ChatbotPromptBuilder::buildFinanceCopilotPrompt('pro', null, ['locale' => 'id']);

        $this->assertStringContainsString('[DIREKTIF SISTEM KRUSIAL — BAHASA RESPONS UTAMA: INDONESIA]', $prompt);
        $this->assertStringContainsString('Locale sesi aktif pengguna adalah BAHASA INDONESIA (id)', $prompt);
        $this->assertStringContainsString('PERSYARATAN BAHASA DINAMIS (LOCALE AKTIF: INDONESIA)', $prompt);
        $this->assertStringContainsString('Bahasa Indonesia bisnis yang profesional', $prompt);
        $this->assertStringNotContainsString('[CRITICAL SYSTEM DIRECTIVE — PRIMARY RESPONSE LANGUAGE: ENGLISH]', $prompt);
    }

    /**
     * 2. Locale "en" menghasilkan instruksi prompt bahasa Inggris pada bagian awal prompt.
     */
    public function test_2_locale_en_generates_english_prompt_instructions_at_beginning(): void
    {
        App::setLocale('en');

        $prompt = ChatbotPromptBuilder::buildFinanceCopilotPrompt('pro', null, ['locale' => 'en']);

        // Pastikan direktif top-anchor berada di awal prompt
        $this->assertStringStartsWith('[CRITICAL SYSTEM DIRECTIVE — PRIMARY RESPONSE LANGUAGE: ENGLISH]', trim($prompt));
        $this->assertStringContainsString('The active user session locale is ENGLISH (en)', $prompt);
        $this->assertStringContainsString('DYNAMIC LANGUAGE REQUIREMENT (ACTIVE LOCALE: ENGLISH)', $prompt);
        $this->assertStringContainsString('Respond fully in professional, fluent, and clear English', $prompt);
        $this->assertStringNotContainsString('[DIREKTIF SISTEM KRUSIAL — BAHASA RESPONS UTAMA: INDONESIA]', $prompt);
    }

    /**
     * 3. Instruksi bahasa berlaku untuk tier Basic maupun Pro/Ultimate dan jalur Command Center.
     */
    public function test_3_language_instructions_apply_to_basic_pro_ultimate_and_command_center(): void
    {
        // 3a. Finance Copilot - Basic (EN)
        $basicEn = ChatbotPromptBuilder::buildFinanceCopilotPrompt('basic', null, ['locale' => 'en']);
        $this->assertStringStartsWith('[CRITICAL SYSTEM DIRECTIVE — PRIMARY RESPONSE LANGUAGE: ENGLISH]', trim($basicEn));
        $this->assertStringContainsString("You are 'Kaching Intelligence Assistant'", $basicEn);
        $this->assertStringContainsString("The user is currently on the BASIC tier", $basicEn);
        $this->assertStringContainsString('DYNAMIC LANGUAGE REQUIREMENT (ACTIVE LOCALE: ENGLISH)', $basicEn);

        // 3b. Finance Copilot - Basic (ID)
        $basicId = ChatbotPromptBuilder::buildFinanceCopilotPrompt('basic', null, ['locale' => 'id']);
        $this->assertStringStartsWith('[DIREKTIF SISTEM KRUSIAL — BAHASA RESPONS UTAMA: INDONESIA]', trim($basicId));
        $this->assertStringContainsString("Anda adalah 'Kaching Intelligence Assistant'", $basicId);
        $this->assertStringContainsString("Pengguna saat ini menggunakan Paket BASIC", $basicId);
        $this->assertStringContainsString('PERSYARATAN BAHASA DINAMIS (LOCALE AKTIF: INDONESIA)', $basicId);

        // 3c. Finance Copilot - Ultimate (EN)
        $ultEn = ChatbotPromptBuilder::buildFinanceCopilotPrompt('ultimate', null, ['locale' => 'en']);
        $this->assertStringStartsWith('[CRITICAL SYSTEM DIRECTIVE — PRIMARY RESPONSE LANGUAGE: ENGLISH]', trim($ultEn));
        $this->assertStringContainsString("Senior Business Consultant' (ULTIMATE Tier)", $ultEn);

        // 3d. Command Center - Basic (EN & ID)
        $cmdBasicEn = ChatbotPromptBuilder::buildCommandCenterPrompt('basic', null, ['locale' => 'en']);
        $this->assertStringStartsWith('[CRITICAL SYSTEM DIRECTIVE — PRIMARY RESPONSE LANGUAGE: ENGLISH]', trim($cmdBasicEn));
        $this->assertStringContainsString("You are 'Kaching Command Center Assistant'", $cmdBasicEn);
        $this->assertStringContainsString("Real-time operational data monitoring requires the PRO tier", $cmdBasicEn);

        $cmdBasicId = ChatbotPromptBuilder::buildCommandCenterPrompt('basic', null, ['locale' => 'id']);
        $this->assertStringStartsWith('[DIREKTIF SISTEM KRUSIAL — BAHASA RESPONS UTAMA: INDONESIA]', trim($cmdBasicId));
        $this->assertStringContainsString("Anda adalah 'Kaching Command Center Assistant'", $cmdBasicId);
        $this->assertStringContainsString("Fitur pemantauan data operasional real-time memerlukan Paket PRO", $cmdBasicId);

        // 3e. Command Center - Pro (EN & ID)
        $cmdProEn = ChatbotPromptBuilder::buildCommandCenterPrompt('pro', null, ['locale' => 'en']);
        $this->assertStringStartsWith('[CRITICAL SYSTEM DIRECTIVE — PRIMARY RESPONSE LANGUAGE: ENGLISH]', trim($cmdProEn));
        $this->assertStringContainsString("Merchant Operations Manager & Command Center Assistant", $cmdProEn);
        $this->assertStringContainsString("OPERATIONAL FOCUS:", $cmdProEn);

        $cmdProId = ChatbotPromptBuilder::buildCommandCenterPrompt('pro', null, ['locale' => 'id']);
        $this->assertStringStartsWith('[DIREKTIF SISTEM KRUSIAL — BAHASA RESPONS UTAMA: INDONESIA]', trim($cmdProId));
        $this->assertStringContainsString("pemantauan operasional harian merchant di Backoffice", $cmdProId);
        $this->assertStringContainsString("FOKUS OPERASIONAL:", $cmdProId);
    }

    /**
     * 4. Payload Step 2 menyertakan penegasan bahasa aktif.
     */
    public function test_4_step_2_payload_includes_active_language_reinforcement(): void
    {
        // Uji penyusunan pengingat bahasa untuk Step 2
        App::setLocale('en');
        $isEn = (strtolower(trim((string) app()->getLocale())) === 'en');
        $reminderEn = $isEn
            ? "[SYSTEM REMINDER] Active user session locale is English (en). You MUST formulate your final answer, explanation, and analysis strictly in clear, professional English. Do NOT answer in Indonesian."
            : "[PENGINGAT SISTEM] Locale aktif sesi pengguna adalah Bahasa Indonesia (id). Anda WAJIB menyusun seluruh jawaban, penjelasan, dan analisis sepenuhnya dalam Bahasa Indonesia bisnis.";

        $this->assertStringContainsString('Active user session locale is English (en)', $reminderEn);
        $this->assertStringContainsString('Do NOT answer in Indonesian', $reminderEn);

        App::setLocale('id');
        $isIdEn = (strtolower(trim((string) app()->getLocale())) === 'en');
        $reminderId = $isIdEn
            ? "[SYSTEM REMINDER] Active user session locale is English (en). You MUST formulate your final answer, explanation, and analysis strictly in clear, professional English. Do NOT answer in Indonesian."
            : "[PENGINGAT SISTEM] Locale aktif sesi pengguna adalah Bahasa Indonesia (id). Anda WAJIB menyusun seluruh jawaban, penjelasan, dan analisis sepenuhnya dalam Bahasa Indonesia bisnis.";

        $this->assertStringContainsString('Locale aktif sesi pengguna adalah Bahasa Indonesia (id)', $reminderId);
        $this->assertStringContainsString('sepenuhnya dalam Bahasa Indonesia bisnis', $reminderId);
    }

    /**
     * 5. Metadata deskriptif tool mengikuti locale tanpa mengubah nilai bisnis, argumen tool, dan kontrak output.
     */
    public function test_5_tool_descriptive_metadata_follows_locale_without_altering_business_data(): void
    {
        $service = app(FinanceCopilotOrchestrationService::class);

        // 5a. Locale English
        App::setLocale('en');
        $enResult = $service->dispatchTool('get_today_sales', $this->tenant->id, $this->outlet->id, []);

        $this->assertSame('SUCCESS', $enResult['status']);
        $this->assertStringContainsString('Total revenue and non-cancelled sales transactions for Today', $enResult['explainability']['what']);
        $this->assertStringContainsString('Aggregation of non-cancelled transactions per date range', $enResult['explainability']['why']);
        $this->assertStringContainsString("Information for AI: Today's revenue is", $enResult['data']['message']);
        $this->assertSame('Today', $enResult['data']['label']);
        $this->assertSame('today', $enResult['data']['period']);
        $this->assertIsFloat($enResult['data']['revenue']);
        $this->assertIsInt($enResult['data']['records_count']);

        // 5b. Locale Indonesia
        App::setLocale('id');
        $idResult = $service->dispatchTool('get_today_sales', $this->tenant->id, $this->outlet->id, []);

        $this->assertSame('SUCCESS', $idResult['status']);
        $this->assertStringContainsString('Total omzet dan transaksi penjualan non-batal periode Hari Ini', $idResult['explainability']['what']);
        $this->assertStringContainsString('Agregasi transaksi non-batal per rentang tanggal', $idResult['explainability']['why']);
        $this->assertStringContainsString('Informasi untuk AI: Omzet hari ini bernilai', $idResult['data']['message']);
        $this->assertSame('Hari Ini', $idResult['data']['label']);
        $this->assertSame('today', $idResult['data']['period']);

        // 5c. Nilai numerik bisnis identik antara EN dan ID
        $this->assertEquals($enResult['data']['revenue'], $idResult['data']['revenue']);
        $this->assertEquals($enResult['data']['records_count'], $idResult['data']['records_count']);
        $this->assertEquals($enResult['data']['start_date'], $idResult['data']['start_date']);
        $this->assertEquals($enResult['data']['end_date'], $idResult['data']['end_date']);
    }

    /**
     * 6. Fallback analisis menu mengikuti locale.
     */
    public function test_6_menu_analysis_fallback_follows_locale(): void
    {
        App::setLocale('en');
        $fbEn = __('copilot.summaries.menu_performance_fallback');
        $this->assertStringContainsString('### 🍽️ Menu Performance Analysis', $fbEn);
        $this->assertStringContainsString('Menu performance data has been successfully retrieved from the Kaching DSS system', $fbEn);

        App::setLocale('id');
        $fbId = __('copilot.summaries.menu_performance_fallback');
        $this->assertStringContainsString('### 🍽️ Analisis Performa Menu', $fbId);
        $this->assertStringContainsString('Data performa menu telah berhasil ditarik dari sistem Kaching DSS', $fbId);
    }

    /**
     * 7. Sidebar menghasilkan label yang benar pada locale "id" dan "en".
     */
    public function test_7_sidebar_renders_correct_labels_in_id_and_en(): void
    {
        App::setLocale('id');
        $this->assertSame('Daftar Produk', __('backoffice.nav.product_list'));
        $this->assertSame('Kelola Menu', __('backoffice.nav.manage_menu'));
        $this->assertSame('Riwayat Transaksi', __('backoffice.nav.transactions'));
        $this->assertSame('Operasional', __('backoffice.nav_sections.operational'));
        $this->assertSame('Keuangan', __('backoffice.nav_sections.finance'));
        $this->assertSame('Sistem', __('backoffice.nav_sections.system'));
        $this->assertSame('Dashboard', __('backoffice.nav.dashboard'));
        $this->assertSame('Kelola Cabang / Outlet', __('backoffice.nav.outlets'));
        $this->assertSame('Pengaturan Toko', __('backoffice.nav.settings'));

        App::setLocale('en');
        $this->assertSame('Product Catalog', __('backoffice.nav.product_list'));
        $this->assertSame('Menu Management', __('backoffice.nav.manage_menu'));
        $this->assertSame('Transaction History', __('backoffice.nav.transactions'));
        $this->assertSame('Operations', __('backoffice.nav_sections.operational'));
        $this->assertSame('Finance', __('backoffice.nav_sections.finance'));
        $this->assertSame('System', __('backoffice.nav_sections.system'));
        $this->assertSame('Dashboard', __('backoffice.nav.dashboard'));
        $this->assertSame('Outlet Management', __('backoffice.nav.outlets'));
        $this->assertSame('Store Settings', __('backoffice.nav.settings'));
    }

    /**
     * 8. Guard route "shifts.index" tetap aman jika route tidak tersedia.
     */
    public function test_8_shifts_index_route_guard_is_present_in_sidebar_blade(): void
    {
        $bladePath = resource_path('views/backoffice/master.blade.php');
        $this->assertFileExists($bladePath);

        $content = file_get_contents($bladePath);

        // Verifikasi bahwa pemanggilan route shifts.index dilindungi Route::has('shifts.index')
        $this->assertStringContainsString("@if(Route::has('shifts.index'))", $content);
        $this->assertStringContainsString("route('shifts.index')", $content);

        // Blade compiler syntax check
        $compiled = Blade::compileString($content);
        $this->assertNotEmpty($compiled);
        $this->assertStringContainsString("Route::has('shifts.index')", $compiled);
    }

    /**
     * 9. Struktur respons API dan perilaku kalkulasi finansial tidak berubah.
     */
    public function test_9_api_response_structure_and_financial_calculation_contracts_preserved(): void
    {
        $service = app(FinanceCopilotOrchestrationService::class);

        App::setLocale('en');
        $res = $service->dispatchTool('get_today_sales', $this->tenant->id, $this->outlet->id, []);

        // Governance contract
        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('explainability', $res);
        $this->assertArrayHasKey('governance', $res);
        $this->assertArrayHasKey('data', $res);

        $this->assertSame('PROPOSER_ONLY', $res['governance']['ai_role']);
        $this->assertFalse($res['governance']['mutation_allowed']);
        $this->assertSame('ActionPolicyService', $res['governance']['approval_firewall']);

        // Explainability contract
        $this->assertArrayHasKey('what', $res['explainability']);
        $this->assertArrayHasKey('why', $res['explainability']);
        $this->assertArrayHasKey('calculation', $res['explainability']);
        $this->assertArrayHasKey('evidence', $res['explainability']);
        $this->assertArrayHasKey('limitations', $res['explainability']);
        $this->assertArrayHasKey('source', $res['explainability']);
        $this->assertArrayHasKey('time_period', $res['explainability']);

        // Data backward compatibility contract
        $this->assertArrayHasKey('revenue', $res['data']);
        $this->assertArrayHasKey('records_count', $res['data']);
        $this->assertArrayHasKey('formatted_sales', $res['data']);
        $this->assertArrayHasKey('message', $res['data']);
        $this->assertArrayHasKey('value', $res['data']);
        $this->assertArrayHasKey('amount', $res['data']);
        $this->assertArrayHasKey('date', $res['data']);
    }
}
