<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ChatbotPromptBuilder;
use Tests\TestCase;

class ChatbotPromptBuilderTest extends TestCase
{
    /**
     * 1. Finance Copilot prompt memuat aturan anti-leakage dan kamus istilah bisnis ramah merchant.
     */
    public function test_finance_copilot_prompt_contains_anti_leakage_and_merchant_glossary(): void
    {
        $user = new User();
        $user->role = 'owner';
        $user->subscription_plan = 'PRO';

        $prompt = ChatbotPromptBuilder::buildFinanceCopilotPrompt('pro', $user);

        // Verify Anti-leakage section exists
        $this->assertStringContainsString('PENCEGAHAN KEBOCORAN IDENTIFIER TEKNIS & KAMUS BAHASA MERCHANT', $prompt);
        $this->assertStringContainsString('DILARANG KERAS menampilkan key JSON snake_case', $prompt);
        
        // Verify key glossary terms are explicitly translated
        $this->assertStringContainsString('current_stock_on_hand', $prompt);
        $this->assertStringContainsString('Stok saat ini', $prompt);
        $this->assertStringContainsString('suggested_quantity', $prompt);
        $this->assertStringContainsString('Jumlah yang disarankan', $prompt);
        $this->assertStringContainsString('reorder_point', $prompt);
        $this->assertStringContainsString('Batas minimum stok', $prompt);
        $this->assertStringContainsString('lead_time_days', $prompt);
        $this->assertStringContainsString('Estimasi waktu pengiriman', $prompt);
        $this->assertStringContainsString('STOCKOUT', $prompt);
        $this->assertStringContainsString('Stok habis', $prompt);
        $this->assertStringContainsString('STOCKOUT_RISK', $prompt);
        $this->assertStringContainsString('Berisiko habis', $prompt);
        $this->assertStringContainsString('POTENTIAL_DEFICIT', $prompt);
        $this->assertStringContainsString('Berpotensi kekurangan arus kas', $prompt);
        $this->assertStringContainsString('UNAVAILABLE', $prompt);
        $this->assertStringContainsString('Belum dapat dievaluasi', $prompt);
        $this->assertStringContainsString('SUPPLIER_CATALOG', $prompt);
        $this->assertStringContainsString('ITEM_COST_FALLBACK', $prompt);

        // Verify strict boundary rules
        $this->assertStringContainsString('DILARANG menyatakan rencana pembelian atau restock aman jika evaluasi arus kas berstatus belum dapat dievaluasi', $prompt);
        $this->assertStringContainsString('DILARANG mengarang harga, stok, jumlah pesanan, nama pemasok', $prompt);
    }

    /**
     * 2. filterToolsForContext menyertakan tool restock untuk finance copilot tier pro.
     */
    public function test_filter_tools_includes_restock_tool_for_finance_copilot_pro(): void
    {
        $user = new User();
        $user->role = 'owner';
        $user->subscription_plan = 'PRO';

        $tools = [
            ['type' => 'function', 'function' => ['name' => 'get_restock_recommendations']],
            ['type' => 'function', 'function' => ['name' => 'get_suppliers']],
            ['type' => 'function', 'function' => ['name' => 'get_sales_summary']],
        ];

        $filtered = ChatbotPromptBuilder::filterToolsForContext(
            $tools,
            ChatbotPromptBuilder::CONTEXT_FINANCE_COPILOT,
            'pro',
            $user
        );

        $this->assertCount(3, $filtered);
        $names = array_column(array_column($filtered, 'function'), 'name');
        $this->assertContains('get_restock_recommendations', $names);
    }
}
