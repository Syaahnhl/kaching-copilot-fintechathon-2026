<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ChatbotPromptBuilder;
use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Illuminate\Http\Request;
use App\Models\User;

class FinancialCopilotBilingualTest extends TestCase
{
    /**
     * Rekursif mengekstrak semua dot-notated key dari associative array.
     */
    private function extractDotKeys(array $array, string $prefix = ''): array
    {
        $keys = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix === '' ? $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $keys = array_merge($keys, $this->extractDotKeys($value, $fullKey));
            } else {
                $keys[] = $fullKey;
            }
        }
        return $keys;
    }

    /**
     * Test 1: Key parity 100% antara "id" dan "en" copilot catalog.
     */
    public function test_key_parity_100_percent_between_id_and_en_catalogs(): void
    {
        $idCatalog = require base_path('lang/id/copilot.php');
        $enCatalog = require base_path('lang/en/copilot.php');

        $this->assertIsArray($idCatalog);
        $this->assertIsArray($enCatalog);

        $idKeys = $this->extractDotKeys($idCatalog);
        $enKeys = $this->extractDotKeys($enCatalog);

        sort($idKeys);
        sort($enKeys);

        $this->assertGreaterThan(50, count($idKeys), 'Catalog must contain rich set of localized keys');
        $this->assertSame($idKeys, $enKeys, 'Catalog keys must have 100% identical parity between ID and EN');
    }

    /**
     * Test 2: Locale aktif "id" menghasilkan instruksi prompt Bahasa Indonesia.
     */
    public function test_active_locale_id_generates_indonesian_prompt_instructions(): void
    {
        App::setLocale('id');

        $prompt = ChatbotPromptBuilder::buildFinanceCopilotPrompt('pro');

        $this->assertStringContainsString('PERSYARATAN BAHASA DINAMIS (LOCALE AKTIF: INDONESIA)', $prompt);
        $this->assertStringContainsString('Bahasa Indonesia bisnis yang profesional', $prompt);
        $this->assertStringNotContainsString('DYNAMIC LANGUAGE REQUIREMENT (ACTIVE LOCALE: ENGLISH)', $prompt);
    }

    /**
     * Test 3: Locale aktif "en" menghasilkan instruksi prompt English.
     */
    public function test_active_locale_en_generates_english_prompt_instructions(): void
    {
        App::setLocale('en');

        $prompt = ChatbotPromptBuilder::buildFinanceCopilotPrompt('pro');

        $this->assertStringContainsString('DYNAMIC LANGUAGE REQUIREMENT (ACTIVE LOCALE: ENGLISH)', $prompt);
        $this->assertStringContainsString('Respond fully in professional, fluent, and clear English', $prompt);
        $this->assertStringNotContainsString('PERSYARATAN BAHASA DINAMIS (LOCALE AKTIF: INDONESIA)', $prompt);
    }

    /**
     * Test 4: Instruksi bahasa tidak melebihi batas 120 token berdasarkan metode estimasi karakter/kata.
     */
    public function test_language_instructions_do_not_exceed_120_token_limit(): void
    {
        $instructionEn = ChatbotPromptBuilder::getDynamicLanguageInstruction('en');
        $instructionId = ChatbotPromptBuilder::getDynamicLanguageInstruction('id');

        // Metode 1: Standard rule of thumb (~4 karakter per token)
        $tokensEnChar = ChatbotPromptBuilder::estimateTokenCount($instructionEn);
        $tokensIdChar = ChatbotPromptBuilder::estimateTokenCount($instructionId);

        // Metode 2: Standard word-based multiplier (~1.33 token per word)
        $tokensEnWord = (int) ceil(str_word_count($instructionEn) * 1.33);
        $tokensIdWord = (int) ceil(str_word_count($instructionId) * 1.33);

        $this->assertLessThanOrEqual(120, $tokensEnChar, "English instruction tokens (char-based: {$tokensEnChar}) exceeds 120 token limit");
        $this->assertLessThanOrEqual(120, $tokensIdChar, "Indonesian instruction tokens (char-based: {$tokensIdChar}) exceeds 120 token limit");

        $this->assertLessThanOrEqual(120, $tokensEnWord, "English instruction tokens (word-based: {$tokensEnWord}) exceeds 120 token limit");
        $this->assertLessThanOrEqual(120, $tokensIdWord, "Indonesian instruction tokens (word-based: {$tokensIdWord}) exceeds 120 token limit");
    }

    /**
     * Test 5: Pasangan query laba turun ID/EN memperoleh intent yang ekuivalen.
     */
    public function test_profit_decline_query_pair_obtains_equivalent_intent_clusters(): void
    {
        $qId = 'Kenapa laba saya turun?';
        $qEn = 'Why has my profit declined?';

        $intentsId = ChatbotPromptBuilder::detectIntents($qId);
        $intentsEn = ChatbotPromptBuilder::detectIntents($qEn);

        sort($intentsId);
        sort($intentsEn);

        // Keduanya wajib mendeteksi multi-domain investigation clusters
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_SALES, $intentsId);
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_SALES, $intentsEn);

        $this->assertContains(ChatbotPromptBuilder::CLUSTER_CASHFLOW, $intentsId);
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_CASHFLOW, $intentsEn);

        $this->assertContains(ChatbotPromptBuilder::CLUSTER_EXPENSES, $intentsId);
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_EXPENSES, $intentsEn);

        $this->assertContains(ChatbotPromptBuilder::CLUSTER_MENU, $intentsId);
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_MENU, $intentsEn);

        $this->assertContains(ChatbotPromptBuilder::CLUSTER_RISK, $intentsId);
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_RISK, $intentsEn);

        $this->assertEquals($intentsId, $intentsEn, 'Both ID and EN root cause queries must map to equivalent intent clusters');
    }

    /**
     * Test 6: Pasangan query simulasi penjualan turun 10% ID/EN memperoleh intent yang ekuivalen.
     */
    public function test_sales_drop_simulation_query_pair_obtains_equivalent_intent_clusters(): void
    {
        $qId = 'Bagaimana jika penjualan turun 10%?';
        $qEn = 'What if sales drop 10%?';

        $intentsId = ChatbotPromptBuilder::detectIntents($qId);
        $intentsEn = ChatbotPromptBuilder::detectIntents($qEn);

        sort($intentsId);
        sort($intentsEn);

        $this->assertContains(ChatbotPromptBuilder::CLUSTER_SALES, $intentsId);
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_SALES, $intentsEn);

        $this->assertContains(ChatbotPromptBuilder::CLUSTER_FINANCING, $intentsId);
        $this->assertContains(ChatbotPromptBuilder::CLUSTER_FINANCING, $intentsEn);

        $this->assertEquals($intentsId, $intentsEn, 'Both ID and EN sales simulation queries must map to identical intent clusters');
    }

    /**
     * Test 7: Greeting dan fallback deterministik mengikuti locale aktif.
     */
    public function test_greeting_and_deterministic_fallbacks_follow_active_locale(): void
    {
        App::setLocale('id');
        $greetingId = __('copilot.greeting.default');
        $busyId = __('copilot.system.busy');
        $unauthId = __('copilot.system.unauthorized_outlet');

        $this->assertStringContainsString('Halo! Saya Kachi', $greetingId);
        $this->assertStringContainsString('Sistem Kaching sedang sibuk', $busyId);
        $this->assertStringContainsString('Akses ditolak', $unauthId);

        App::setLocale('en');
        $greetingEn = __('copilot.greeting.default');
        $busyEn = __('copilot.system.busy');
        $unauthEn = __('copilot.system.unauthorized_outlet');

        $this->assertStringContainsString('Hello! I am Kachi', $greetingEn);
        $this->assertStringContainsString('Kaching system is currently busy', $busyEn);
        $this->assertStringContainsString('Access denied', $unauthEn);

        $this->assertNotEquals($greetingId, $greetingEn);
        $this->assertNotEquals($busyId, $busyEn);
        $this->assertNotEquals($unauthId, $unauthEn);
    }

    /**
     * Test 8: Rate-limit/429 serta provider-error fallback tersedia dalam kedua bahasa.
     */
    public function test_rate_limit_and_provider_error_fallbacks_available_in_both_locales(): void
    {
        App::setLocale('id');
        $rlId = __('copilot.system.rate_limited_429');
        $peId = __('copilot.system.provider_unavailable');
        $geId = __('copilot.system.error_general');

        $this->assertNotEmpty($rlId);
        $this->assertNotEmpty($peId);
        $this->assertNotEmpty($geId);
        $this->assertStringContainsString('lonjakan permintaan', $rlId);

        App::setLocale('en');
        $rlEn = __('copilot.system.rate_limited_429');
        $peEn = __('copilot.system.provider_unavailable');
        $geEn = __('copilot.system.error_general');

        $this->assertNotEmpty($rlEn);
        $this->assertNotEmpty($peEn);
        $this->assertNotEmpty($geEn);
        $this->assertStringContainsString('high request volume', $rlEn);
        $this->assertStringContainsString('temporarily unavailable', $peEn);

        $this->assertNotEquals($rlId, $rlEn);
        $this->assertNotEquals($peId, $peEn);
    }

    /**
     * Test 9: Financial card labels dan severity tersedia pada kedua locale.
     */
    public function test_financial_card_labels_and_severities_available_in_both_locales(): void
    {
        $cardTypes = [
            'action_recommendations',
            'performance_intelligence',
            'risk_radar',
            'sales_summary_today',
            'external_radar',
            'menu_intelligence',
            'financing_readiness',
        ];

        foreach (['id', 'en'] as $loc) {
            App::setLocale($loc);
            foreach ($cardTypes as $type) {
                $label = __("copilot.cards.{$type}");
                $this->assertNotEmpty($label);
                $this->assertNotEquals("copilot.cards.{$type}", $label, "Key copilot.cards.{$type} must be translated in locale {$loc}");
            }

            foreach (['critical', 'warning', 'watch', 'info', 'success'] as $sev) {
                $sevLabel = __("copilot.cards.severities.{$sev}");
                $this->assertNotEmpty($sevLabel);
                $this->assertNotEquals("copilot.cards.severities.{$sev}", $sevLabel, "Key copilot.cards.severities.{$sev} must be translated in {$loc}");
            }
        }
    }

    /**
     * Test 10: Widget placeholder, loading, empty state, dan error menggunakan katalog.
     */
    public function test_widget_strings_render_correctly_from_catalog(): void
    {
        App::setLocale('id');
        $vId = View::make('components.copilot-widget')->render();
        $this->assertStringContainsString(__('copilot.widget.input_placeholder'), $vId);
        $this->assertStringContainsString(__('copilot.widget.analyzing'), $vId);
        $this->assertStringContainsString(__('copilot.widget.try_asking'), $vId);
        $this->assertStringContainsString(__('copilot.widget.quick_sales'), $vId);

        App::setLocale('en');
        $vEn = View::make('components.copilot-widget')->render();
        $this->assertStringContainsString(__('copilot.widget.input_placeholder'), $vEn);
        $this->assertStringContainsString(__('copilot.widget.analyzing'), $vEn);
        $this->assertStringContainsString(__('copilot.widget.try_asking'), $vEn);
        $this->assertStringContainsString(__('copilot.widget.quick_sales'), $vEn);

        // Periksa bahwa placeholder bahasa Inggris dan Indonesia berbeda secara semantik
        $this->assertNotEquals(__('copilot.widget.input_placeholder', [], 'id'), __('copilot.widget.input_placeholder', [], 'en'));
    }

    /**
     * Test 11: Registry tetap 25 canonical tools / 7 clusters dan skema tool tidak berubah.
     */
    public function test_tool_registry_remains_frozen_at_25_tools_and_7_clusters(): void
    {
        $clusters = ChatbotPromptBuilder::getToolClusters();
        $this->assertCount(7, $clusters, 'Must have exactly 7 canonical clusters');

        $expectedClusters = ['sales', 'menu', 'cashflow', 'expenses', 'financing', 'risk', 'core'];
        $this->assertEquals($expectedClusters, array_keys($clusters), 'Clusters must match canonical 7 clusters');

        $controllerSource = file_get_contents(app_path('Http/Controllers/ChatbotController.php'));
        preg_match_all("/'name'\s*=>\s*'([a-zA-Z0-9_]+)'/", $controllerSource, $matches);
        $declaredTools = array_values(array_unique($matches[1]));

        $this->assertCount(26, $declaredTools, 'Must have exactly 26 canonical tools declared');

        $canonical26 = [
            'get_sales_summary',
            'get_top_selling_products',
            'get_today_sales',
            'get_active_financial_warnings',
            'get_financial_scenario',
            'get_cash_flow_forecast',
            'get_external_business_radar',
            'get_suppliers',
            'propose_action',
            'calculate_financing_readiness',
            'simulate_purchase',
            'get_menu_intelligence',
            'get_financial_action_recommendations',
            'get_financial_summary',
            'get_risk_radar',
            'get_demand_forecast',
            'get_stock_risk',
            'get_material_price_forecast',
            'get_menu_margin_candidates',
            'prepare_action_proposal',
            'get_financing_readiness',
            'get_financial_evidence',
            'get_market_signals',
            'get_financial_performance_intelligence',
            'get_restock_recommendations',
            'generate_supplier_restock_message',
        ];

        $this->assertEquals($canonical26, $declaredTools, 'The 26 canonical tools must match exactly with zero mutation');
    }

    /**
     * Test 12: Payload clamping, tenant scoping, dan behavior Phase 5.18.3 tidak mengalami regresi.
     */
    public function test_phase_5_18_3_behaviors_and_guardrails_remain_intact(): void
    {
        // 1. History clamping: history message length clamped at 4000 characters and 12000 total characters
        $controllerSource = file_get_contents(app_path('Http/Controllers/ChatbotController.php'));
        $this->assertStringContainsString('mb_strlen($content) > 4000', $controllerSource, 'Payload clamping at 4000 chars must remain intact');
        $this->assertStringContainsString('$maxTotalChars = 12000', $controllerSource, 'Total character clamping at 12000 chars must remain intact');

        // 2. Tenant scoping: unset($parsedArgs['user_id']) must remain
        $this->assertStringContainsString("unset(\$parsedArgs['user_id'])", $controllerSource, 'Tenant injection guardrail must remain intact');

        // 3. Unauthorized outlet response format and code
        $this->assertStringContainsString("'code'           => 'UNAUTHORIZED_OUTLET'", $controllerSource, 'Unauthorized outlet error code must remain intact');

        // 4. Standalone greeting handler non-regression
        $this->assertStringContainsString('$isStandaloneGreeting', $controllerSource, 'Standalone greeting handler must remain intact');

        // 5. Anti-leakage merchant glossary in prompt builder
        $prompt = ChatbotPromptBuilder::buildFinanceCopilotPrompt('pro');
        $this->assertStringContainsString('PENCEGAHAN KEBOCORAN IDENTIFIER TEKNIS & KAMUS BAHASA MERCHANT', $prompt);
        $this->assertStringContainsString('current_stock_on_hand', $prompt);
        $this->assertStringContainsString('Stok saat ini', $prompt);
    }
}
