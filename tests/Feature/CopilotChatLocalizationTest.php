<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;

class CopilotChatLocalizationTest extends TestCase
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
     * Test all keys referenced in backoffice/copilot_chat.blade.php exist in both catalogs
     */
    public function test_all_keys_in_copilot_chat_blade_exist_in_catalogs(): void
    {
        $bladePath = resource_path('views/backoffice/copilot_chat.blade.php');
        $this->assertFileExists($bladePath);

        $bladeContent = file_get_contents($bladePath);

        // Find all __('copilot.xyz') and @js(__('copilot.xyz')) patterns
        preg_match_all("/__\(['\"](copilot\.[^'\"]+)['\"]\)/", $bladeContent, $matches);
        $referencedKeys = array_unique($matches[1]);

        $this->assertNotEmpty($referencedKeys);

        $idCatalog = include resource_path('../lang/id/copilot.php');
        $enCatalog = include resource_path('../lang/en/copilot.php');

        $hasKey = function ($catalog, $dotKey) {
            // Strip 'copilot.' prefix since we load copilot.php directly
            $segments = explode('.', preg_replace('/^copilot\./', '', $dotKey));
            $curr = $catalog;
            foreach ($segments as $seg) {
                if (!is_array($curr) || !array_key_exists($seg, $curr)) {
                    return false;
                }
                $curr = $curr[$seg];
            }
            return true;
        };

        foreach ($referencedKeys as $key) {
            $this->assertTrue($hasKey($idCatalog, $key), "Translation key [{$key}] missing in lang/id/copilot.php");
            $this->assertTrue($hasKey($enCatalog, $key), "Translation key [{$key}] missing in lang/en/copilot.php");
        }
    }

    /**
     * Test backoffice.copilot_chat renders Indonesian strings when locale is 'id'
     */
    public function test_copilot_chat_renders_correctly_in_indonesian(): void
    {
        App::setLocale('id');

        $rendered = View::make('backoffice.copilot_chat')->render();

        // 1. Header & Controls
        $this->assertStringContainsString('Kaching Copilot', $rendered);
        $this->assertStringContainsString('AI Financial &amp; Operational Copilot', $rendered);
        $this->assertStringContainsString('Reset Percakapan', $rendered);
        $this->assertStringContainsString('Kaching Intelligence', $rendered);

        // 2. Empty State & Quick Prompts
        $this->assertStringContainsString('Tanyakan tentang bisnis Anda.', $rendered);
        $this->assertStringContainsString('Penjualan Hari Ini', $rendered);
        $this->assertStringContainsString('Berapa omzet saya hari ini?', $rendered);
        $this->assertStringContainsString('Performa Menu', $rendered);
        $this->assertStringContainsString('Bagaimana kondisi menu saya?', $rendered);
        $this->assertStringContainsString('Risiko Operasional', $rendered);
        $this->assertStringContainsString('Apa risiko operasional yang perlu saya lihat?', $rendered);
        $this->assertStringContainsString('Cash Flow', $rendered);
        $this->assertStringContainsString('Bagaimana kondisi cash flow?', $rendered);

        // 3. Financial Cards & Badges
        $this->assertStringContainsString('STAR (Bintang)', $rendered);
        $this->assertStringContainsString('CASH COW (Stabil)', $rendered);
        $this->assertStringContainsString('PUZZLE (Peluang)', $rendered);
        $this->assertStringContainsString('DOG (Evaluasi)', $rendered);
        $this->assertStringContainsString('Top Menu Unggulan:', $rendered);
        $this->assertStringContainsString('Peringatan', $rendered);
        $this->assertStringContainsString('Baseline Laba Bersih', $rendered);
        $this->assertStringContainsString('Skenario Laba Bersih', $rendered);
        $this->assertStringContainsString('Dampak:', $rendered);
        $this->assertStringContainsString('Kas Masuk', $rendered);
        $this->assertStringContainsString('Kas Keluar', $rendered);
        $this->assertStringContainsString('Net', $rendered);
        $this->assertStringContainsString('Proyeksi Harian:', $rendered);
        $this->assertStringContainsString('Total Penjualan', $rendered);
        $this->assertStringContainsString('Bahan Terpapar', $rendered);
        $this->assertStringContainsString('Berita', $rendered);
        $this->assertStringContainsString('Skor Kesiapan Pembukuan', $rendered);
        $this->assertStringContainsString('Periode Evaluasi:', $rendered);
        $this->assertStringContainsString('5 Pilar Evaluasi Finansial', $rendered);
        $this->assertStringContainsString('Bukti Finansial (Evidence Coverage)', $rendered);
        $this->assertStringContainsString('Terverifikasi Database POS', $rendered);
        $this->assertStringContainsString('Catatan Keterbatasan Data', $rendered);

        // 4. Action Recommendations & Menu Margin Review
        $this->assertStringContainsString('Rekomendasi Tindakan Finansial', $rendered);
        $this->assertStringContainsString('Rekomendasi', $rendered);
        $this->assertStringContainsString('Bukti Terverifikasi:', $rendered);
        $this->assertStringContainsString('Ekspektasi Dampak:', $rendered);
        $this->assertStringContainsString('Batasan:', $rendered);
        $this->assertStringContainsString('Kandidat menu dengan margin rendah untuk ditinjau Owner', $rendered);
        $this->assertStringContainsString('Tinjauan Owner', $rendered);
        $this->assertStringContainsString('Pilih Tepat Satu Menu:', $rendered);
        $this->assertStringContainsString('Tidak ada data kandidat menu ber-HPP yang dapat dievaluasi saat ini.', $rendered);
        $this->assertStringContainsString('Menu Terpilih:', $rendered);
        $this->assertStringContainsString('Jual:', $rendered);
        $this->assertStringContainsString('HPP:', $rendered);
        $this->assertStringContainsString('Margin:', $rendered);
        $this->assertStringContainsString('Harga Baru Pilihan Owner (Rp)', $rendered);
        $this->assertStringContainsString('Simulasi Dampak Margin Unit:', $rendered);
        $this->assertStringContainsString('Deterministik', $rendered);
        $this->assertStringContainsString('Selisih:', $rendered);
        $this->assertStringContainsString('Perubahan:', $rendered);
        $this->assertStringContainsString('Margin Baru:', $rendered);
        $this->assertStringContainsString('Delta:', $rendered);
        $this->assertStringContainsString('Menunggu persetujuan Owner', $rendered);
        $this->assertStringContainsString('Proposal Aksi Sistem', $rendered);
        $this->assertStringContainsString('Status Eksekusi:', $rendered);

        // 5. Loading, Error, Composer & Legend
        $this->assertStringContainsString('Menganalisis...', $rendered);
        $this->assertStringContainsString('Gagal:', $rendered);
        $this->assertStringContainsString('Tanyakan omzet, menu, atau simulasi...', $rendered);
        $this->assertStringContainsString('Kirim pesan', $rendered);
        $this->assertStringContainsString('kirim', $rendered);
        $this->assertStringContainsString('baris baru', $rendered);
        $this->assertStringContainsString('Terlalu banyak permintaan. Mohon tunggu sebentar.', $rendered);
    }

    /**
     * Test backoffice.copilot_chat renders English strings when locale is 'en'
     */
    public function test_copilot_chat_renders_correctly_in_english(): void
    {
        App::setLocale('en');

        $rendered = View::make('backoffice.copilot_chat')->render();

        // 1. Header & Controls
        $this->assertStringContainsString('Kaching Copilot', $rendered);
        $this->assertStringContainsString('AI Financial &amp; Operational Copilot', $rendered);
        $this->assertStringContainsString('Reset Conversation', $rendered);
        $this->assertStringContainsString('Kaching Intelligence', $rendered);

        // 2. Empty State & Quick Prompts
        $this->assertStringContainsString('Ask about your business.', $rendered);
        $this->assertStringContainsString("Today&#039;s Sales", $rendered);
        $this->assertStringContainsString('What is my revenue today?', $rendered);
        $this->assertStringContainsString('Menu Performance', $rendered);
        $this->assertStringContainsString('How is my menu performing?', $rendered);
        $this->assertStringContainsString('Operational Risk', $rendered);
        $this->assertStringContainsString('What operational risks should I check?', $rendered);
        $this->assertStringContainsString('Cash Flow', $rendered);
        $this->assertStringContainsString('How is my cash flow looking?', $rendered);

        // 3. Financial Cards & Badges
        $this->assertStringContainsString('STAR (Best Seller)', $rendered);
        $this->assertStringContainsString('CASH COW (Stable)', $rendered);
        $this->assertStringContainsString('PUZZLE (Opportunity)', $rendered);
        $this->assertStringContainsString('DOG (Evaluate)', $rendered);
        $this->assertStringContainsString('Top Featured Menus:', $rendered);
        $this->assertStringContainsString('Warning', $rendered);
        $this->assertStringContainsString('Baseline Net Profit', $rendered);
        $this->assertStringContainsString('Scenario Net Profit', $rendered);
        $this->assertStringContainsString('Impact:', $rendered);
        $this->assertStringContainsString('Cash Inflow', $rendered);
        $this->assertStringContainsString('Cash Outflow', $rendered);
        $this->assertStringContainsString('Net', $rendered);
        $this->assertStringContainsString('Daily Forecast:', $rendered);
        $this->assertStringContainsString('Total Sales', $rendered);
        $this->assertStringContainsString('Exposed Raw Materials', $rendered);
        $this->assertStringContainsString('News', $rendered);
        $this->assertStringContainsString('Bookkeeping Readiness Score', $rendered);
        $this->assertStringContainsString('Evaluation Period:', $rendered);
        $this->assertStringContainsString('5 Financial Evaluation Pillars', $rendered);
        $this->assertStringContainsString('Financial Evidence Coverage', $rendered);
        $this->assertStringContainsString('Verified by POS Database', $rendered);
        $this->assertStringContainsString('Data Limitations Notice', $rendered);

        // 4. Action Recommendations & Menu Margin Review
        $this->assertStringContainsString('Financial Action Recommendations', $rendered);
        $this->assertStringContainsString('Recommendations', $rendered);
        $this->assertStringContainsString('Verified Evidence:', $rendered);
        $this->assertStringContainsString('Expected Impact:', $rendered);
        $this->assertStringContainsString('Limitations:', $rendered);
        $this->assertStringContainsString('Low margin menu candidates for Owner review', $rendered);
        $this->assertStringContainsString('Owner Review', $rendered);
        $this->assertStringContainsString('Select Exactly One Menu:', $rendered);
        $this->assertStringContainsString('No COGS-configured menu candidate data available for evaluation at this time.', $rendered);
        $this->assertStringContainsString('Selected Menu:', $rendered);
        $this->assertStringContainsString('Sell:', $rendered);
        $this->assertStringContainsString('COGS:', $rendered);
        $this->assertStringContainsString('Margin:', $rendered);
        $this->assertStringContainsString('Owner Selected New Price (Rp)', $rendered);
        $this->assertStringContainsString('Unit Margin Impact Simulation:', $rendered);
        $this->assertStringContainsString('Deterministic', $rendered);
        $this->assertStringContainsString('Difference:', $rendered);
        $this->assertStringContainsString('Change:', $rendered);
        $this->assertStringContainsString('New Margin:', $rendered);
        $this->assertStringContainsString('Delta:', $rendered);
        $this->assertStringContainsString('Pending Owner approval', $rendered);
        $this->assertStringContainsString('System Action Proposal', $rendered);
        $this->assertStringContainsString('Execution Status:', $rendered);

        // 5. Loading, Error, Composer & Legend
        $this->assertStringContainsString('Analyzing...', $rendered);
        $this->assertStringContainsString('Failed:', $rendered);
        $this->assertStringContainsString('Ask about revenue, menu, or simulations...', $rendered);
        $this->assertStringContainsString('Send message', $rendered);
        $this->assertStringContainsString('send', $rendered);
        $this->assertStringContainsString('new line', $rendered);
        $this->assertStringContainsString('Too many requests. Please wait a moment.', $rendered);
    }

    /**
     * Test DOM contracts, Alpine bindings, and endpoints remain intact
     */
    public function test_copilot_chat_preserves_dom_contract_and_endpoints(): void
    {
        $rendered = View::make('backoffice.copilot_chat')->render();

        // Alpine functions & data attributes
        $this->assertStringContainsString('x-data="copilotChatPage()"', $rendered);
        $this->assertStringContainsString('x-init="initPage()"', $rendered);
        $this->assertStringContainsString('x-ref="chatContainer"', $rendered);
        $this->assertStringContainsString('x-ref="composerInput"', $rendered);
        $this->assertStringContainsString('resetChat()', $rendered);
        $this->assertStringContainsString('sendMessage()', $rendered);
        $this->assertStringContainsString('sendQuickPrompt(', $rendered);
        $this->assertStringContainsString('autoResize(', $rendered);
        $this->assertStringContainsString('handlePaste(', $rendered);
        $this->assertStringContainsString('toggleMenuMarginPanel(', $rendered);
        $this->assertStringContainsString('selectMenuCandidate(', $rendered);
        $this->assertStringContainsString('runMenuSimulation(', $rendered);
        $this->assertStringContainsString('submitMenuProposal(', $rendered);

        // Endpoints and API routes
        $this->assertStringContainsString('https://kaching.id/copilot/chat', $rendered);
        $this->assertStringContainsString('https://kaching.id/copilot/menu-margin/candidates', $rendered);
        $this->assertStringContainsString('https://kaching.id/copilot/menu-margin/propose', $rendered);
        $this->assertStringContainsString('https://kaching.id/copilot/intelligence', $rendered);
        $this->assertStringContainsString('message: text', $rendered);
        $this->assertStringContainsString('history: this.history', $rendered);
    }
}
