<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;

class CopilotWidgetLocalizationTest extends TestCase
{
    /**
     * Test catalog symmetry between lang/id/copilot.php and lang/en/copilot.php
     */
    public function test_copilot_localization_catalogs_are_symmetric(): void
    {
        $idPath = resource_path('../lang/id/copilot.php');
        $enPath = resource_path('../lang/en/copilot.php');

        $this->assertFileExists($idPath);
        $this->assertFileExists($enPath);

        $idCatalog = include $idPath;
        $enCatalog = include $enPath;

        $this->assertIsArray($idCatalog);
        $this->assertIsArray($enCatalog);

        $flatten = function ($array, $prefix = '') use (&$flatten) {
            $result = [];
            foreach ($array as $key => $value) {
                $fullKey = $prefix === '' ? $key : "{$prefix}.{$key}";
                if (is_array($value)) {
                    $result = array_merge($result, $flatten($value, $fullKey));
                } else {
                    $result[$fullKey] = $value;
                }
            }
            return $result;
        };

        $idFlat = $flatten($idCatalog);
        $enFlat = $flatten($enCatalog);

        $missingInEn = array_diff_key($idFlat, $enFlat);
        $missingInId = array_diff_key($enFlat, $idFlat);

        $this->assertEmpty($missingInEn, 'Keys in ID missing in EN: ' . implode(', ', array_keys($missingInEn)));
        $this->assertEmpty($missingInId, 'Keys in EN missing in ID: ' . implode(', ', array_keys($missingInId)));
        $this->assertSame(count($idFlat), count($enFlat));
    }

    /**
     * Test Blade renders Indonesian strings when locale is 'id'
     */
    public function test_copilot_widget_renders_correctly_in_indonesian(): void
    {
        App::setLocale('id');

        $rendered = View::make('components.copilot-widget')->render();

        // 1. Header & Controls
        $this->assertStringContainsString('Kachi Copilot', $rendered);
        $this->assertStringContainsString('Reset Percakapan', $rendered);
        $this->assertStringContainsString('Tutup', $rendered);

        // 2. Greeting & Suggested Questions
        $this->assertStringContainsString('Halo! Saya Kachi, asisten analisis bisnis Anda.', $rendered);
        $this->assertStringContainsString('Berapa omzet saya hari ini?', $rendered);
        $this->assertStringContainsString('Bagaimana kondisi menu saya?', $rendered);
        $this->assertStringContainsString('Simulasi dampak jika biaya operasional naik 10%', $rendered);
        $this->assertStringContainsString('Lihat pertanyaan lain', $rendered);

        // 3. Input & Status
        $this->assertStringContainsString('Tanyakan omzet, menu, atau simulasi...', $rendered);
        $this->assertStringContainsString('Kirim pesan', $rendered);
        $this->assertStringContainsString('Menganalisis...', $rendered);

        // 4. Financial Cards & Badges
        $this->assertStringContainsString('STAR (Bintang)', $rendered);
        $this->assertStringContainsString('CASH COW (Stabil)', $rendered);
        $this->assertStringContainsString('PUZZLE (Peluang)', $rendered);
        $this->assertStringContainsString('DOG (Evaluasi)', $rendered);
        $this->assertStringContainsString('Top Menu Unggulan:', $rendered);
        $this->assertStringContainsString('Peringatan', $rendered);
        $this->assertStringContainsString('Estimasi Omzet', $rendered);
        $this->assertStringContainsString('Estimasi Laba Bersih', $rendered);
        $this->assertStringContainsString('Selisih Laba:', $rendered);
        $this->assertStringContainsString('5 Pilar Evaluasi Finansial', $rendered);
        $this->assertStringContainsString('Skor Kesiapan Pembukuan', $rendered);
        $this->assertStringContainsString('Proposal Aksi Sistem', $rendered);

        // 5. Alpine JS escaped strings
        $this->assertStringContainsString('Memproses...', $rendered);
    }

    /**
     * Test Blade renders English strings when locale is 'en'
     */
    public function test_copilot_widget_renders_correctly_in_english(): void
    {
        App::setLocale('en');

        $rendered = View::make('components.copilot-widget')->render();

        // 1. Header & Controls
        $this->assertStringContainsString('Kachi Copilot', $rendered);
        $this->assertStringContainsString('Reset Conversation', $rendered);
        $this->assertStringContainsString('Close', $rendered);

        // 2. Greeting & Suggested Questions
        $this->assertStringContainsString('Hello! I am Kachi, your business intelligence assistant.', $rendered);
        $this->assertStringContainsString('What is my revenue today?', $rendered);
        $this->assertStringContainsString('How is my menu performing?', $rendered);
        $this->assertStringContainsString('Simulate impact if operational costs increase by 10%', $rendered);
        $this->assertStringContainsString('See other questions', $rendered);

        // 3. Input & Status
        $this->assertStringContainsString('Ask about revenue, menu, or simulations...', $rendered);
        $this->assertStringContainsString('Send message', $rendered);
        $this->assertStringContainsString('Analyzing...', $rendered);

        // 4. Financial Cards & Badges
        $this->assertStringContainsString('STAR (Best Seller)', $rendered);
        $this->assertStringContainsString('CASH COW (Stable)', $rendered);
        $this->assertStringContainsString('PUZZLE (Opportunity)', $rendered);
        $this->assertStringContainsString('DOG (Evaluate)', $rendered);
        $this->assertStringContainsString('Top Featured Menus:', $rendered);
        $this->assertStringContainsString('Warning', $rendered);
        $this->assertStringContainsString('Estimated Revenue', $rendered);
        $this->assertStringContainsString('Estimated Net Profit', $rendered);
        $this->assertStringContainsString('Profit Difference:', $rendered);
        $this->assertStringContainsString('5 Financial Evaluation Pillars', $rendered);
        $this->assertStringContainsString('Bookkeeping Readiness Score', $rendered);
        $this->assertStringContainsString('System Action Proposal', $rendered);

        // 5. Alpine JS escaped strings
        $this->assertStringContainsString('Processing...', $rendered);
    }

    /**
     * Test DOM IDs, Alpine state contract, and endpoints remain intact
     */
    public function test_widget_preserves_dom_contract_and_endpoints(): void
    {
        $rendered = View::make('components.copilot-widget')->render();

        // Alpine functions & data attributes
        $this->assertStringContainsString('x-data="kachiCopilotWidget()"', $rendered);
        $this->assertStringContainsString('x-init="initWidget()"', $rendered);
        $this->assertStringContainsString('x-show="isOpen"', $rendered);
        $this->assertStringContainsString('@keydown.escape.window="isOpen ? closeWidget() : null"', $rendered);
        $this->assertStringContainsString('@click="openWidget()"', $rendered);
        $this->assertStringContainsString('@click="closeWidget()"', $rendered);
        $this->assertStringContainsString('@click="resetChat()"', $rendered);
        $this->assertStringContainsString('sendMessage()', $rendered);
        $this->assertStringContainsString('sendQuickPrompt(', $rendered);

        // Element references
        $this->assertStringContainsString('x-ref="copilotPanel"', $rendered);
        $this->assertStringContainsString('x-ref="messageBox"', $rendered);
        $this->assertStringContainsString('x-ref="copilotInput"', $rendered);
        $this->assertStringContainsString('x-ref="copilotComposer"', $rendered);

        // Endpoints and API calls
        $this->assertStringContainsString('fetch("https://kaching.id/copilot/chat"', $rendered);
        $this->assertStringContainsString('fetch("https://kaching.id/copilot/menu-margin/candidates"', $rendered);
        $this->assertStringContainsString('fetch("https://kaching.id/copilot/menu-margin/propose"', $rendered);
        $this->assertStringContainsString('message: text', $rendered);
        $this->assertStringContainsString('history: this.history', $rendered);
    }
}
