<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use App\Http\Controllers\ChatbotController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotFinancingReadinessIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Outlet $outlet;
    protected ChatbotController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'owner',
            'subscription_tier' => 'pro',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->user->id,
            'name' => 'Outlet Pusat',
            'address' => 'Jl. Sudirman 1',
        ]);

        $this->controller = app(ChatbotController::class);
    }

    public function test_execute_calculate_financing_readiness_authenticated(): void
    {
        $request = new Request();
        $request->headers->set('Outlet-ID', (string) $this->outlet->id);

        $result = $this->controller->executeCalculateFinancingReadiness($this->user, $request);

        $this->assertSame('SUCCESS', $result['status']);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('band', $result);
        $this->assertArrayHasKey('pillars', $result);
        $this->assertArrayHasKey('disclaimer', $result);
    }

    public function test_execute_simulate_purchase_within_buffer(): void
    {
        $request = new Request();
        $request->headers->set('Outlet-ID', (string) $this->outlet->id);

        $args = [
            'amount' => 500000,
            'date' => '2026-09-30',
            'minimum_buffer' => 1000000,
        ];

        $result = $this->controller->executeSimulatePurchase($this->user, $request, $args);

        $this->assertContains($result['status'], ['WITHIN_BUFFER', 'EXACT_BUFFER', 'BELOW_BUFFER', 'DEFICIT']);
        $this->assertSame(500000.0, $result['purchase_amount']);
        $this->assertSame(1000000.0, $result['minimum_operating_buffer']);
    }

    public function test_ai_endpoint_uses_configuration_abstraction(): void
    {
        Config::set('services.groq.base_url', 'https://custom-ai-gateway.local');
        Config::set('services.ai.base_url', 'https://custom-ai-gateway.local');

        $request = Request::create('/api/chatbot/message', 'POST', [
            'message' => 'Hitung kesiapan pembiayaan usaha saya',
        ]);
        $request->setUserResolver(fn () => $this->user);
        $request->headers->set('Outlet-ID', (string) $this->outlet->id);

        $capturedUrl = null;

        Http::fake([
            'https://custom-ai-gateway.local/*' => function (\Illuminate\Http\Client\Request $req) use (&$capturedUrl) {
                $capturedUrl = $req->url();
                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Kesiapan pembiayaan Anda berada di tingkat moderat.',
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->controller->sendMessage($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($capturedUrl);
        $this->assertStringStartsWith('https://custom-ai-gateway.local', $capturedUrl);
    }
}
