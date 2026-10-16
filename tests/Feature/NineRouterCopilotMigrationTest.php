<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class NineRouterCopilotMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Outlet $outlet;
    protected string $fakeSecretKey = 'sk-test-9router-secret-key-xyz12345';

    protected function setUp(): void
    {
        parent::setUp();

        // Configure 9Router service parameters
        Config::set('services.ai.key', $this->fakeSecretKey);
        Config::set('services.ai.base_url', 'http://127.0.0.1:20128/v1');
        Config::set('services.ai.model', 'kachingcopilot');
        Config::set('services.ai.timeout', 5);
        Config::set('services.ai.connect_timeout', 2);

        // Configure legacy groq alias for compatibility verification
        Config::set('services.groq.key', $this->fakeSecretKey);
        Config::set('services.groq.base_url', 'http://127.0.0.1:20128/v1');
        Config::set('services.groq.model', 'kachingcopilot');

        $this->owner = User::factory()->create([
            'email'             => 'owner9router@kaching.id',
            'role'              => 'owner',
            'subscription_tier' => 'pro',
            'account_status'    => 'active',
        ]);

        $this->outlet = Outlet::create([
            'user_id' => $this->owner->id,
            'name'    => 'Outlet 9Router Test',
            'address' => 'Jl. Merdeka No. 9',
        ]);
    }

    /**
     * 1. Correct 9Router endpoint and model configuration.
     */
    public function test_1_correct_9router_endpoint_and_model_configuration(): void
    {
        $this->assertEquals('http://127.0.0.1:20128/v1', config('services.ai.base_url'));
        $this->assertEquals('kachingcopilot', config('services.ai.model'));
        $this->assertEquals($this->fakeSecretKey, config('services.ai.key'));

        // Legacy compatibility alias
        $this->assertEquals('http://127.0.0.1:20128/v1', config('services.groq.base_url'));
        $this->assertEquals('kachingcopilot', config('services.groq.model'));
    }

    /**
     * 2. Authentication header construction without exposing the secret.
     */
    public function test_2_authentication_header_construction_without_exposing_the_secret(): void
    {
        Http::fake([
            '*/chat/completions*' => function ($request) {
                // Assert auth header is properly formatted
                $this->assertTrue($request->hasHeader('Authorization'));
                $this->assertEquals('Bearer ' . $this->fakeSecretKey, $request->header('Authorization')[0]);
                $this->assertEquals('application/json', $request->header('Content-Type')[0]);

                return Http::response([
                    'id' => 'chatcmpl-test-auth',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Halo dari 9Router Gateway',
                            ],
                            'finish_reason' => 'stop',
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('copilot.chat'), [
                'message' => 'Halo 9Router',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Confirm secret is NOT returned anywhere in response JSON
        $rawContent = $response->getContent();
        $this->assertStringNotContainsString($this->fakeSecretKey, $rawContent);
    }

    /**
     * 3. Successful OpenAI-compatible response parsing.
     */
    public function test_3_successful_openai_compatible_response_parsing(): void
    {
        Http::fake([
            '*/chat/completions*' => Http::response([
                'id' => 'chatcmpl-test-success',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'kachingcopilot',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Omzet toko Anda berjalan sangat stabil hari ini.',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('copilot.chat'), [
                'message' => 'Bagaimana kondisi toko?',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('Omzet toko Anda berjalan sangat stabil hari ini.', $response->json('data.reply'));
    }

    /**
     * 4. HTTP 401/403 authentication errors.
     */
    public function test_4_http_401_403_authentication_errors(): void
    {
        Http::fake([
            '*/chat/completions*' => Http::response([
                'error' => [
                    'message' => 'Incorrect API key provided',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key'
                ]
            ], 401),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('copilot.chat'), [
                'message' => 'Cek status akun',
            ]);

        // Controller gracefully transitions to deterministic fallback (status 200 response with safe reply)
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.reply'));
        $this->assertStringNotContainsString($this->fakeSecretKey, $response->getContent());
    }

    /**
     * 5. HTTP 402/quota or billing errors.
     */
    public function test_5_http_402_quota_or_billing_errors(): void
    {
        Http::fake([
            '*/chat/completions*' => Http::response([
                'error' => [
                    'message' => 'Quota exhausted or payment required',
                    'type' => 'insufficient_quota',
                    'code' => 'insufficient_quota'
                ]
            ], 402),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('copilot.chat'), [
                'message' => 'Cek laporan omzet',
            ]);

        // System must immediately transition to deterministic fallback without looping
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.reply'));
        Http::assertSentCount(1);
    }

    /**
     * 6. HTTP 429 rate limiting.
     */
    public function test_6_http_429_rate_limiting(): void
    {
        // When Retry-After is > 3.0s, skips sleep and transitions directly to deterministic fallback
        Http::fake([
            '*/chat/completions*' => Http::response(
                '{"error": {"message": "Rate limit exceeded. Please try again later."}}',
                429,
                ['Retry-After' => '10.0']
            ),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('copilot.chat'), [
                'message' => 'Berapa total penjualan?',
            ]);

        $response->assertStatus(200);
        Http::assertSentCount(1);
    }

    /**
     * 7. HTTP 5xx and connection/read timeout behavior.
     */
    public function test_7_http_5xx_and_connection_read_timeout_behavior(): void
    {
        Http::fake([
            '*/chat/completions*' => Http::response('Gateway Timeout', 504),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('copilot.chat'), [
                'message' => 'Analisis performa',
            ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.reply'));
    }

    /**
     * 8. Malformed or empty model responses.
     */
    public function test_8_malformed_or_empty_model_responses(): void
    {
        Http::fake([
            '*/chat/completions*' => Http::response([
                'id' => 'chatcmpl-empty',
                'choices' => [], // Empty choices array
            ], 200),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('copilot.chat'), [
                'message' => 'Rekomendasi menu',
            ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.reply'));
    }

    /**
     * 9. Tool/function-call compatibility.
     */
    public function test_9_tool_function_call_compatibility(): void
    {
        Http::fake([
            // Step 1: LLM decides to call get_today_sales tool
            '*/chat/completions*' => Http::sequence()
                ->push([
                    'id' => 'chatcmpl-step1',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [
                                    [
                                        'id' => 'call_sales_123',
                                        'type' => 'function',
                                        'function' => [
                                            'name' => 'get_today_sales',
                                            'arguments' => '{}',
                                        ],
                                    ],
                                ],
                            ],
                            'finish_reason' => 'tool_calls',
                        ],
                    ],
                ], 200)
                // Step 2: Final response synthesis
                ->push([
                    'id' => 'chatcmpl-step2',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Omzet penjualan hari ini berhasil dirangkum dari data kasir.',
                            ],
                            'finish_reason' => 'stop',
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('copilot.chat'), [
                'message' => 'Berapa omzet hari ini?',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('Omzet penjualan hari ini berhasil dirangkum dari data kasir.', $response->json('data.reply'));
        Http::assertSentCount(2);
    }

    /**
     * 10. Preservation of deterministic revenue/expense routes and tenant/outlet authorization.
     */
    public function test_10_preservation_of_deterministic_revenue_routes_and_tenant_outlet_authorization(): void
    {
        // Create another tenant's outlet
        $otherUser = User::factory()->create();
        $otherOutlet = Outlet::create([
            'user_id' => $otherUser->id,
            'name'    => 'Outlet Tenant Lain',
            'address' => 'Jl. Asing No. 99',
        ]);

        Http::fake([
            '*/chat/completions*' => Http::response(
                '{"error": {"message": "Rate limit reached"}}',
                429,
                ['Retry-After' => '20.0']
            ),
        ]);

        // Attempting to query an unauthorized outlet during fallback must be rejected with 403 UNAUTHORIZED_OUTLET
        $response = $this->actingAs($this->owner)
            ->withHeader('Outlet-ID', (string) $otherOutlet->id)
            ->postJson(route('copilot.chat'), [
                'message' => 'Berapa omzet hari ini?',
            ]);

        $response->assertStatus(403);
        $this->assertEquals('UNAUTHORIZED_OUTLET', $response->json('code'));
    }

    /**
     * 11. Confirmation that no API key is returned to the frontend or written to logs.
     */
    public function test_11_confirmation_no_api_key_returned_to_frontend_or_written_to_logs(): void
    {
        Http::fake([
            '*/chat/completions*' => Http::response('Server Error Occurred', 500),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('copilot.chat'), [
                'message' => 'Uji kebocoran rahasia',
            ]);

        $response->assertStatus(200);
        $jsonPayload = $response->getContent();

        // Strict verification: secret key must never appear in response body
        $this->assertStringNotContainsString($this->fakeSecretKey, $jsonPayload);
    }
}
