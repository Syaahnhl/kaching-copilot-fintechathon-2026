<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Outlet;
use App\Services\ChatbotPromptBuilder;
use App\Services\FaqMatcher;
use App\Services\Financial\FinancialWarningService;
use App\Services\Financial\FinancialScenarioService;
use App\Services\Financial\CashFlowForecastService;
use App\Services\Financial\FinancialEvidenceService;
use App\Services\Financial\FinanceCopilotOrchestrationService;
use App\Services\Financial\FinancialActionRecommendationService;

class ChatbotController extends Controller
{
    protected FinanceCopilotOrchestrationService $orchestrator;

    public function __construct(?FinanceCopilotOrchestrationService $orchestrator = null)
    {
        $this->orchestrator = $orchestrator ?? app(FinanceCopilotOrchestrationService::class);
    }

    /**
     * Menerima pesan dari web atau Android dan meneruskannya ke Groq API (Llama 3.1).
     * Mendukung Tiering & Function Calling (Two-Step Iteration).
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'message'             => 'required|string|max:20000',
            'history'             => 'sometimes|array',
            'history.*.role'      => 'required|in:user,assistant',
            'history.*.content'   => 'sometimes|string|max:10000',
            'context'             => 'sometimes|array',
            'context.*.role'      => 'sometimes|in:user,assistant',
            'context.*.content'   => 'sometimes|string|max:10000',
        ]);

        $userMessage = $request->input('message');

        // 1. Identifikasi User & Tiering
        // Menggunakan request()->user() menangani otentikasi sanctum (Mobile) / web session.
        $user = $request->user();
        $tier = $user ? ($user->subscription_tier ?? 'basic') : 'basic'; // Fallback buat Guest
        $userId = $user ? $user->id : null;
        $isCopilotRoute = $request->is('copilot/*') || $request->is('copilot') || $request->routeIs('copilot.*');

        // Fail-closed untuk Copilot route: pengguna wajib terautentikasi (HTTP 401)
        if ($isCopilotRoute && !$user) {
            $reply = __('copilot.system.unauthenticated') ?? 'Sesi pengguna tidak valid atau belum terautentikasi.';
            return response()->json([
                'status'  => 'error',
                'code'    => 'UNAUTHENTICATED',
                'message' => $reply,
                'reply'   => $reply,
                'data'    => ['reply' => $reply, 'code' => 'UNAUTHENTICATED', 'message' => $reply]
            ], 401);
        }

        // 0. FAQ MATCH (hemat token: pertanyaan berulang dijawab statis, tanpa AI call)
        // Scope B: FAQ deskripsi fitur/omzet/laporan/stok DILARANG mengintersep user authenticated / Copilot yang butuh data aktual.
        $faqHit = null;
        if ($user === null && !$isCopilotRoute) {
            // Public / Guest on landing page: seluruh marketing FAQs aktif
            $faqHit = FaqMatcher::match($userMessage);
        } else if (!$isCopilotRoute) {
            // Authenticated user on general help: hanya informational / support FAQs (about, support, download, security, specs, onboarding)
            $faqHit = FaqMatcher::match($userMessage, ['about', 'support', 'download', 'security', 'specs', 'onboarding']);
        }

        if ($faqHit !== null) {
            Log::info('Chatbot FAQ hit', [
                'matched_keyword' => $faqHit['matched_keyword'] ?? null,
                'category'        => $faqHit['category'] ?? null,
                'user_id'         => optional($request->user())->id,
            ]);
            return response()->json([
                'status' => 'success',
                'reply'  => $faqHit['reply'],
                'data'   => [
                    'reply'      => $faqHit['reply'],
                    'source'     => 'faq',
                    'route'      => $faqHit['route'] ?? null,
                    'cta_label'  => $faqHit['cta_label'] ?? null,
                    'cta_href'   => $faqHit['cta_href'] ?? null,
                ],
            ]);
        }

        // Membaca API Key dari config (Centralized AI SSOT with legacy fallback)
        $apiKey = config('services.ai.key') ?? config('services.groq.key');

        if (empty($apiKey)) {
            Log::error('Chatbot Config Error: Kunci API LLM (NINEROUTER_API_KEY / GROQ_API_KEY) belum diset di .env');
            $msg = __('copilot.system.config_missing') ?? 'Konfigurasi AI belum disetel.';
            return response()->json([
                'status'  => 'error',
                'code'    => 'CONFIG_MISSING',
                'message' => $msg,
                'reply'   => $msg,
                'data'    => ['reply' => $msg, 'code' => 'CONFIG_MISSING', 'message' => $msg],
                'error'   => $msg
            ], 500);
        }

        $isPro = in_array(strtolower($tier), ['pro', 'ultimate', 'premium']);
        $tierLower = strtolower($tier);

        // 2. Persona Decoupling & System Prompt Isolation (TASK-AI-CHAT-03)
        $context = ChatbotPromptBuilder::resolveContext($request, $user);
        $systemPrompt = ChatbotPromptBuilder::buildPrompt($context, $tier, $user);
        $hasToolAccess = ChatbotPromptBuilder::isToolAllowedForContext($context, $tier, $user);

        // 3. Define JSON Schema Tools (Function Calling)
        $tools = [];
        if ($hasToolAccess) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_sales_summary',
                    'description' => 'Mengecek atau mengambil ringkasan omzet (total pendapatan) dan jumlah transaksi penjualan pada periode tertentu (today, yesterday, this_week, this_month, last_month, atau rentang tanggal custom). Gunakan tool ini untuk pertanyaan omzet hari ini, kemarin, minggu ini, bulan ini, atau tanggal tertentu.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'period' => [
                                'type' => 'string',
                                'enum' => ['today', 'yesterday', 'this_week', 'this_month', 'last_month', 'custom'],
                                'description' => 'Periode penjualan: today (hari ini), yesterday (kemarin), this_week (minggu ini), this_month (bulan ini), last_month (bulan lalu), atau custom.',
                            ],
                            'start_date' => [
                                'type' => 'string',
                                'description' => 'Tanggal awal periode penjualan (format YYYY-MM-DD). Contoh: 2026-10-03',
                            ],
                            'end_date' => [
                                'type' => 'string',
                                'description' => 'Tanggal akhir periode penjualan (format YYYY-MM-DD). Contoh: 2026-10-03',
                            ],
                            'outlet_id' => [
                                'type' => 'integer',
                                'description' => 'ID outlet spesifik jika ada (opsional).',
                            ],
                        ],
                        'required' => []
                    ]
                ]
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_top_selling_products',
                    'description' => 'Mengambil daftar produk/menu terlaris (top selling products) berdasarkan kuantitas penjualan, omzet, atau laba pada periode tertentu (today, yesterday, this_week, this_month, last_month, atau custom date range). Mengembalikan peringkat, nama produk, jumlah kuantitas terjual, omzet, dan profit.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'period' => [
                                'type' => 'string',
                                'enum' => ['today', 'yesterday', 'this_week', 'this_month', 'last_month', 'custom'],
                                'description' => 'Periode evaluasi penjualan (default: today). Gunakan yesterday untuk penjualan kemarin.',
                            ],
                            'start_date' => [
                                'type' => 'string',
                                'description' => 'Tanggal awal jika menggunakan rentang tanggal khusus (format YYYY-MM-DD).',
                            ],
                            'end_date' => [
                                'type' => 'string',
                                'description' => 'Tanggal akhir jika menggunakan rentang tanggal khusus (format YYYY-MM-DD).',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Jumlah produk teratas yang ingin diambil (default: 5, maksimal 20).',
                            ],
                            'sort_by' => [
                                'type' => 'string',
                                'enum' => ['quantity', 'revenue', 'profit'],
                                'description' => 'Kriteria pengurutan ranking: quantity (default, jumlah item terjual), revenue (total omzet produk), atau profit (keuntungan kotor).',
                            ],
                            'outlet_id' => [
                                'type' => 'integer',
                                'description' => 'ID outlet spesifik jika ada (opsional).',
                            ],
                        ],
                        'required' => []
                    ]
                ]
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_today_sales',
                    'description' => 'Mengecek atau mengambil jumlah omzet (total pendapatan) hari ini milik user yang sedang aktif (Legacy alias untuk get_sales_summary period today).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object)[], // Empty object required by JSON schema
                        'required' => []
                    ]
                ]
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_active_financial_warnings',
                    'description' => 'Mengecek atau mengambil data peringatan dini kesehatan finansial (financial warning engine) yang aktif untuk bisnis atau outlet user saat ini (penurunan omzet, lonjakan pengeluaran, kompresi margin, void/refund abnormal, atau selisih laci kas). Mengembalikan bukti metrik deterministik.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object)[], // Empty object required by JSON schema
                        'required' => []
                    ]
                ]
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_financial_scenario',
                    'description' => 'Menjalankan simulasi finansial \'what-if\' deterministik (kenaikan/penurunan harga, kenaikan/penurunan HPP, perubahan omzet, efisiensi beban operasional, atau kombinasi) berdasarkan baseline 30 hari bisnis user. Mengembalikan dampak simulasi terhadap omzet, HPP, beban, laba kotor, laba bersih, dan margin.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'scenario_type' => [
                                'type' => 'string',
                                'enum' => [
                                    'PRICE_CHANGE',
                                    'COST_CHANGE',
                                    'REVENUE_CHANGE',
                                    'EXPENSE_CHANGE',
                                    'COMBINED_SCENARIO',
                                ],
                                'description' => 'Tipe simulasi skenario finansial yang dijalankan.',
                            ],
                            'price_change_percent' => [
                                'type' => 'number',
                                'description' => 'Persentase perubahan harga jual (contoh: 10 untuk +10%, -5 untuk -5%).',
                            ],
                            'cost_change_percent' => [
                                'type' => 'number',
                                'description' => 'Persentase perubahan HPP / biaya bahan baku (contoh: 15 untuk +15%, -10 untuk -10%).',
                            ],
                            'revenue_change_percent' => [
                                'type' => 'number',
                                'description' => 'Persentase perubahan volume omzet penjualan (contoh: 10 untuk +10%, -20 untuk -20%).',
                            ],
                            'expense_change_percent' => [
                                'type' => 'number',
                                'description' => 'Persentase perubahan beban operasional (contoh: -10 untuk hemat 10%). Jangan gunakan bersamaan dengan expense_change_amount.',
                            ],
                            'expense_change_amount' => [
                                'type' => 'number',
                                'description' => 'Nominal perubahan beban operasional dalam Rupiah (contoh: -1000000 untuk penghematan 1 juta, 500000 untuk penambahan beban). Jangan gunakan bersamaan dengan expense_change_percent.',
                            ],
                        ],
                        'required' => ['scenario_type'],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_cash_flow_forecast',
                    'description' => 'Mengambil proyeksi arus kas operasional deterministik 7 hari ke depan berdasarkan data historis Kaching.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object)[],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_external_business_radar',
                    'description' => 'Mengecek sinyal radar bisnis eksternal (harga komoditas pangan, cuaca ekstrem BMKG, tarif operasional) yang relevan dengan bahan baku atau menu merchant, dilengkapi bukti atribusi sumber resmi.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'signal_category' => [
                                'type' => 'string',
                                'enum' => ['ALL', 'COMMODITY', 'WEATHER', 'OPERATIONAL'],
                                'description' => 'Kategori sinyal yang ingin diperiksa: ALL, COMMODITY, WEATHER, atau OPERATIONAL. Default ALL.',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_suppliers',
                    'description' => 'Mengambil daftar vendor/supplier bahan baku dan kontak pengadaan aktif milik tenant pengguna saat ini.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'outlet_id' => [
                                'type' => 'integer',
                                'description' => 'ID outlet spesifik (opsional).',
                            ],
                            'query' => [
                                'type' => 'string',
                                'description' => 'Kata kunci nama supplier atau kontak (opsional).',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Jumlah maksimal supplier yang dikembalikan (default 5, maksimal 10).',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'propose_action',
                    'description' => 'Mengajukan proposal aksi operasional atau perubahan data bisnis (expense.create, menu.price_change, stock.adjust, transaction.cancel, dll.) untuk dievaluasi oleh policy engine dan menunggu otorisasi persetujuan manusia. Tool ini TIDAK mengeksekusi mutasi langsung.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => [
                                'type' => 'string',
                                'description' => 'Nama aksi bisnis yang diusulkan. Contoh: expense.create, menu.price_change, stock.adjust, transaction.cancel',
                            ],
                            'parameters' => [
                                'type' => 'object',
                                'description' => 'Parameter spesifik aksi yang diusulkan (misal: name, amount, category, menu_id, new_price, reason)',
                            ],
                        ],
                        'required' => ['action', 'parameters'],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'calculate_financing_readiness',
                    'description' => 'Menghitung indeks kesiapan pembiayaan (Financing Readiness Index 0-100) berbasis 5 pilar data operasional: stabilitas omzet (25%), kesehatan margin (20%), disiplin kas laci (25%), integritas operasional void/refund (15%), dan kelengkapan data HPP (15%). Bersifat deterministik dan murni evaluasi data/operasional, BUKAN persetujuan kredit.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object)[],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'simulate_purchase',
                    'description' => 'Mensimulasikan kelayakan rencana belanja stok/pengeluaran modal: menghitung proyeksi sisa kas setelah belanja (kas saat ini + proyeksi arus kas masuk pada tanggal belanja - nilai belanja) dan membandingkannya dengan cadangan operasional aman (safety buffer). Bersifat deterministik dan bebas mutasi.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'amount' => [
                                'type' => 'number',
                                'description' => 'Nominal rencana belanja/pembelian dalam Rupiah (harus > 0). Contoh: 3000000',
                            ],
                            'date' => [
                                'type' => 'string',
                                'description' => 'Tanggal rencana pembelian (format YYYY-MM-DD). Contoh: 2026-09-28',
                            ],
                            'minimum_buffer' => [
                                'type' => 'number',
                                'description' => 'Ambang batas cadangan kas operasional minimum (default: 1500000).',
                            ],
                        ],
                        'required' => ['amount', 'date'],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_menu_intelligence',
                    'description' => 'Mengambil data analitik dan performa menu (BCG Matrix & SAW Ranking) merchant.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'period' => [
                                'type' => 'string',
                                'enum' => ['7days', '30days', '90days', 'year'],
                                'description' => 'Rentang periode analisis menu (default: 30days, rentang historis minimum: 7days).',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_financial_action_recommendations',
                    'description' => 'Mengambil rekomendasi tindakan operasional dan finansial terverifikasi untuk outlet aktif.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object)[],
                        'required' => [],
                        'additionalProperties' => false,
                    ],
                ],
            ];

            // Canonical Orchestration Tools (Phase 3.4C - 3.9)
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_financial_summary',
                    'description' => 'Mengambil ringkasan kesehatan finansial deterministik (pendapatan, HPP, beban, laba kotor, laba bersih).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'days' => ['type' => 'integer', 'description' => 'Jendela evaluasi dalam hari (default: 30)'],
                        ],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_risk_radar',
                    'description' => 'Mengevaluasi 5 dimensi risiko finansial operasional (omzet, pengeluaran, margin, refund, selisih laci kas).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object)[],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_demand_forecast',
                    'description' => 'Mengambil ramalan permintaan kuantitas menu harian 7 hari ke depan dengan evaluasi point-in-time.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'menu_id'   => ['type' => 'integer', 'description' => 'ID menu spesifik (opsional)'],
                            'menu_name' => ['type' => 'string', 'description' => 'Nama menu spesifik (opsional)'],
                        ],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_stock_risk',
                    'description' => 'Mengevaluasi risiko stockout bahan baku resep BOM berdasarkan proyeksi run-rate dan sisa stok ledger.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'menu_id' => ['type' => 'integer', 'description' => 'ID menu spesifik (opsional)'],
                        ],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_material_price_forecast',
                    'description' => 'Menganalisis tren pergerakan harga beli bahan baku berbasis invoice riil (fail-closed jika faktur tidak ada).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'item_id' => ['type' => 'integer', 'description' => 'ID item bahan baku'],
                        ],
                        'required' => ['item_id'],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_menu_margin_candidates',
                    'description' => 'Mengambil daftar menu ber-margin tipis di bawah ambang batas (default 30%) untuk tinjauan Owner.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'threshold_percent' => ['type' => 'number', 'description' => 'Ambang batas margin (default: 30)'],
                            'limit'             => ['type' => 'integer', 'description' => 'Batas jumlah kandidat (default: 10)'],
                        ],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'prepare_action_proposal',
                    'description' => 'Menyiapkan proposal aksi finansial transien di cache (TTL 15m) untuk dievaluasi oleh ActionPolicyService tanpa mutasi database langsung.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'action'     => ['type' => 'string', 'description' => 'Nama aksi yang diajukan (misal: menu.price_change, expense.create)'],
                            'parameters' => ['type' => 'object', 'description' => 'Parameter spesifik aksi'],
                        ],
                        'required' => ['action', 'parameters'],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_financing_readiness',
                    'description' => 'Menghitung indeks kesiapan pembiayaan (0-100) berbasis 5 pilar data operasional Kaching POS.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object)[],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_financial_evidence',
                    'description' => 'Mengompilasi berkas bukti finansial (dossier) operasional terverifikasi.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'days' => ['type' => 'integer', 'description' => 'Rentang hari bukti (default: 30)'],
                        ],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_market_signals',
                    'description' => 'Mengambil sinyal pasar eksternal (hari libur nasional 2026, long weekend, siklus gajian, dan status cuaca/komoditas).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'Tanggal sinyal (YYYY-MM-DD, opsional)'],
                        ],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_financial_performance_intelligence',
                    'description' => 'Mengambil analisis performa finansial menu deterministik berbasis matriks kecepatan penjualan (velocity), margin kontribusi estimasi, dan sinyal anomali operasional.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'anchor_date'             => ['type' => 'string', 'description' => 'Tanggal jangkar PIT (YYYY-MM-DD), default hari ini.'],
                            'observation_window_days' => ['type' => 'integer', 'description' => 'Jendela observasi (30, 60, 90 hari), default 90.'],
                            'menu_id'                 => ['type' => 'integer', 'description' => 'Filter ID menu spesifik (opsional).'],
                            'performance_state'       => ['type' => 'string', 'description' => 'Filter status performa (STRONG_PERFORMER, HIGH_SALES_LOW_MARGIN, HIGH_MARGIN_LOW_VELOCITY, LOW_MARGIN_LOW_VELOCITY, STABLE_CORE_PERFORMER, PERFORMANCE_DETERIORATION, NEGATIVE_MARGIN_RISK, ANOMALY_FLAGGED, MIXED_SIGNAL, DORMANT_CATALOG_ITEM, INSUFFICIENT_MARGIN_EVIDENCE, INSUFFICIENT_EVIDENCE) (opsional).'],
                            'limit'                   => ['type' => 'integer', 'description' => 'Batas jumlah item yang dikembalikan (default 20, max 100).'],
                        ],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'get_restock_recommendations',
                    'description' => 'Mengambil rekomendasi pembelian restock bahan baku kritis berdasarkan prediksi kehabisan stok, pencocokan supplier terdaftar, estimasi biaya pembelian, evaluasi arus kas operasional, dan draf pesan pemesanan WhatsApp (wa.me).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'horizon_days'    => ['type' => 'integer', 'description' => 'Rentang hari proyeksi restock (default: 7).'],
                            'safety_buffer'   => ['type' => 'number', 'description' => 'Buffer stok pengaman dalam unit bahan (default: 0).'],
                            'include_pricing' => ['type' => 'boolean', 'description' => 'Apakah menyertakan pencocokan harga supplier (default: true).'],
                        ],
                        'required' => [],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_supplier_restock_message',
                    'description' => 'Menyusun draf pesan restock untuk supplier/vendor tertentu dan menghasilkan tautan WhatsApp (wa.me) langsung untuk konfirmasi pemesanan oleh owner/manager. Tidak membuat PO atau mutasi database secara otomatis.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'supplier_id'   => ['type' => 'integer', 'description' => 'ID supplier terdaftar (opsional jika supplier_name diberikan)'],
                            'supplier_name' => ['type' => 'string', 'description' => 'Nama supplier terdaftar (misal: Toko Sembako Berkah Jaya)'],
                            'items'         => [
                                'type' => 'array',
                                'description' => 'Daftar item bahan baku yang akan direstok',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'item_name' => ['type' => 'string', 'description' => 'Nama bahan baku (misal: Beras Premium)'],
                                        'quantity'  => ['type' => 'number', 'description' => 'Jumlah kuantitas (misal: 30)'],
                                        'unit'      => ['type' => 'string', 'description' => 'Satuan UOM (misal: kg, pcs, liter)'],
                                    ],
                                    'required' => ['item_name', 'quantity'],
                                ],
                            ],
                            'delivery_date' => ['type' => 'string', 'description' => 'Jadwal / tanggal pengiriman yang diinginkan (misal: 2026-10-18 atau Minggu)'],
                        ],
                        'required' => ['items'],
                    ],
                ],
            ];
        }

        $rawHistory = $request->input('history');
        if (!is_array($rawHistory)) {
            $rawHistory = $request->input('context');
        }

        $tools = ChatbotPromptBuilder::filterToolsForContext(
            $tools,
            $context,
            $tier,
            $user,
            $userMessage,
            is_array($rawHistory) ? $rawHistory : []
        );

        $baseUrl = config('services.groq.base_url') ?? config('services.ai.base_url') ?? 'http://127.0.0.1:20128/v1';
        $model = config('services.ai.model') ?? config('services.groq.model', 'kachingcopilot');
        $aiTimeout = (int) (config('services.ai.timeout') ?? 20);
        $aiConnectTimeout = (int) (config('services.ai.connect_timeout') ?? 5);
        $url = str_contains($baseUrl, 'completions')
            ? $baseUrl
            : rtrim($baseUrl, '/') . (str_contains($baseUrl, '/v1') ? '/chat/completions' : '/openai/v1/chat/completions');

        $historyMessages = [];
        if (is_array($rawHistory)) {
            $totalChars = 0;
            $maxTurns = 8;
            $maxTotalChars = 12000;
            $recentHistory = array_slice($rawHistory, -$maxTurns);
            foreach ($recentHistory as $entry) {
                if (!is_array($entry)) continue;
                $role = $entry['role'] ?? '';
                $content = $entry['content'] ?? '';

                // Strict role validation: only user or assistant allowed from client history
                if (!in_array($role, ['user', 'assistant'], true)) {
                    continue;
                }
                if (!is_string($content)) {
                    continue;
                }
                $content = trim($content);
                if ($content === '') {
                    continue;
                }
                if (mb_strlen($content) > 4000) {
                    $content = mb_substr($content, 0, 4000);
                }

                if ($totalChars + mb_strlen($content) > $maxTotalChars) {
                    break;
                }
                $totalChars += mb_strlen($content);

                $historyMessages[] = [
                    'role'    => $role,
                    'content' => $content,
                ];
            }
        }

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $historyMessages,
            [['role' => 'user', 'content' => $userMessage]]
        );

        try {
            // STEP 1: Meminta instruksi awal Groq / 9router
            $payload = [
                'model'       => $model,
                'temperature' => 0.4,
                'max_tokens'  => 1024,
                'stream'      => false,
                'messages'    => $messages,
            ];

            // Pasang kapabilitas Tools pada parameter Payload bila user berstatus Pro
            if (count($tools) > 0) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = 'auto'; // Biarkan AI memutuskan butuh atau tidak alat tersebut
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ])->connectTimeout($aiConnectTimeout)->timeout($aiTimeout)->post($url, $payload);

            if ($response->status() === 429) {
                $retryAfter = self::parseRetryAfter($response);
                if ($retryAfter !== null && $retryAfter > 3.0) {
                    Log::warning('AI Provider Rate Limit (429) Step 1: Retry-After (' . $retryAfter . 's) exceeds budget (3.0s). Transitioning to deterministic fallback.', [
                        'user_id'     => optional($user)->id,
                        'retry_after' => $retryAfter,
                    ]);
                    return $this->executeDeterministicFallback($user, $request, $userMessage, 'provider_rate_limit');
                }

                $waitTime = ($retryAfter !== null) ? (int) ceil($retryAfter) : 2;
                if ($waitTime > 3) {
                    $waitTime = 3;
                }
                if ($waitTime > 0) {
                    sleep($waitTime);
                }

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])->connectTimeout($aiConnectTimeout)->timeout($aiTimeout)->post($url, $payload);
            }

            if ($response->status() === 401 || $response->status() === 403) {
                Log::error('AI Provider Auth Error (' . $response->status() . '): Unauthorized or Forbidden API key.');
                return $this->executeDeterministicFallback($user, $request, $userMessage, 'provider_auth_error');
            }

            if ($response->status() === 402) {
                Log::error('AI Provider Quota Error (402): Upstream quota exhausted or payment required.');
                return $this->executeDeterministicFallback($user, $request, $userMessage, 'provider_quota_exhausted');
            }

            if (!$response->successful()) {
                if ($response->status() === 429) {
                    Log::warning('AI Provider Rate Limit (429) Step 1 retry failed: ' . \Illuminate\Support\Str::limit($response->body(), 200), [
                        'user_id' => optional($user)->id,
                    ]);
                    return $this->executeDeterministicFallback($user, $request, $userMessage, 'provider_rate_limit');
                }

                Log::error('AI Provider Error Step 1: ' . $response->status() . ' - ' . \Illuminate\Support\Str::limit($response->body(), 200));
                throw new \Exception('API Error: ' . $response->status());
            }

            $responseData = $response->json();
            if (!is_array($responseData) || empty($responseData['choices'][0]['message'])) {
                Log::warning('AI Provider Malformed Response Step 1: missing choices or message.');
                return $this->executeDeterministicFallback($user, $request, $userMessage, 'provider_malformed_response');
            }
            $assistantMessage = $responseData['choices'][0]['message'];

            $activeProposal = null;
            $cachedFinancialWarnings = null;
            $cachedFinancialScenarios = [];
            $cachedCashFlowForecast = null;
            $cachedExternalRadars = [];
            $cachedFinancingReadiness = null;
            $cachedPurchaseSimulations = [];
            $cachedMenuIntelligence = [];
            $cachedActionRecommendations = null;
            $cachedPerformanceIntelligence = null;
            $cachedTopSellingProducts = null;
            $cachedSuppliers = null;
            $cachedSupplierRestock = null;
            $todaySalesSummary = null;
            $lastOrchestrationResult = null;
            $requestToolMemo = [];
            $hasUnauthorizedOutlet = false;
            $successfulToolsCount = 0;

            // STEP 2: Melacak Adanya Perintah Ekseskusi Fungsi (Tool Calls) dari AI
            if (isset($assistantMessage['tool_calls']) && count($assistantMessage['tool_calls']) > 0) {
                // 1. Simpan pesan Assistant yang mengandung niat menggunakan Alat ke history (Syarat wajib LLM Callback).
                $messages[] = $assistantMessage;

                // 2. Loop melalui segala macam permintaan alat (biasanya AI cuma melontarkan 1)
                foreach ($assistantMessage['tool_calls'] as $toolCall) {
                    $toolCallId = $toolCall['id'];
                    $functionName = $toolCall['function']['name'];
                    $rawArgs = $toolCall['function']['arguments'] ?? '{}';
                    $parsedArgs = is_array($rawArgs) ? $rawArgs : json_decode($rawArgs, true);
                    if (!is_array($parsedArgs)) {
                        $parsedArgs = [];
                    }

                    // Security invariant: LLM cannot inject user_id
                    unset($parsedArgs['user_id']);

                    // Resolve outlet ID safely (rejects unowned/cross-tenant outlet)
                    $outletId = $this->resolveAuthorizedOutletId($user, $request, $parsedArgs);

                    if ($outletId === -1) {
                        $hasUnauthorizedOutlet = true;
                        $toolResult = [
                            'status'  => 'ERROR',
                            'code'    => 'UNAUTHORIZED_OUTLET',
                            'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
                        ];
                    } elseif ($functionName === 'get_sales_summary' || $functionName === 'get_today_sales') {
                        $memoKey = $functionName . ':' . md5(serialize($parsedArgs));
                        if (!isset($requestToolMemo[$memoKey])) {
                            $requestToolMemo[$memoKey] = $this->orchestrator->dispatchTool($functionName, $userId, $outletId, $parsedArgs);
                        }
                        $toolResult = $requestToolMemo[$memoKey];
                        $lastOrchestrationResult = $toolResult;

                        if (($toolResult['status'] ?? '') === 'SUCCESS') {
                            $successfulToolsCount++;
                            $todaySalesSummary = $toolResult['data'] ?? [];
                            $todaySalesSummary['amount'] = (float) ($todaySalesSummary['revenue'] ?? 0);
                            $todaySalesSummary['date'] = (string) ($todaySalesSummary['start_date'] ?? Carbon::today('Asia/Jakarta')->toDateString());
                        }
                    } elseif ($functionName === 'get_top_selling_products') {
                        $memoKey = $functionName . ':' . md5(serialize($parsedArgs));
                        if (!isset($requestToolMemo[$memoKey])) {
                            $requestToolMemo[$memoKey] = $this->orchestrator->dispatchTool('get_top_selling_products', $userId, $outletId, $parsedArgs);
                        }
                        $toolResult = $requestToolMemo[$memoKey];
                        $lastOrchestrationResult = $toolResult;

                        if (($toolResult['status'] ?? '') === 'SUCCESS') {
                            $successfulToolsCount++;
                            $cachedTopSellingProducts = $toolResult['data'] ?? [];
                        }
                    } elseif ($functionName === 'get_active_financial_warnings') {
                        if ($cachedFinancialWarnings === null) {
                            $cachedFinancialWarnings = $this->executeGetActiveFinancialWarnings($user, $request);
                        }
                        $toolResult = $cachedFinancialWarnings;
                    } elseif ($functionName === 'get_financial_scenario') {
                        $scenarioMemoKey = md5(serialize($parsedArgs));
                        if (!isset($cachedFinancialScenarios[$scenarioMemoKey])) {
                            $cachedFinancialScenarios[$scenarioMemoKey] = $this->executeGetFinancialScenario($user, $request, $parsedArgs);
                        }
                        $toolResult = $cachedFinancialScenarios[$scenarioMemoKey];
                    } elseif ($functionName === 'get_cash_flow_forecast') {
                        if ($cachedCashFlowForecast === null) {
                            $cachedCashFlowForecast = $this->executeGetCashFlowForecast($user, $request, $parsedArgs);
                        }
                        $toolResult = $cachedCashFlowForecast;
                    } elseif ($functionName === 'get_external_business_radar') {
                        $radarMemoKey = md5(json_encode($parsedArgs));
                        if (!isset($cachedExternalRadars[$radarMemoKey])) {
                            $cachedExternalRadars[$radarMemoKey] = $this->executeGetExternalBusinessRadar($user, $request, $parsedArgs);
                        }
                        $toolResult = $cachedExternalRadars[$radarMemoKey];
                    } elseif ($functionName === 'propose_action') {
                        $proposalResult = $this->executeProposeAction($user, $request, $parsedArgs);
                        if (
                            !empty($proposalResult['proposal_id']) &&
                            in_array($proposalResult['decision'] ?? '', ['REQUIRES_APPROVAL', 'ALLOW'], true)
                        ) {
                            $activeProposal = [
                                'proposal_id'   => (string) $proposalResult['proposal_id'],
                                'action'        => (string) $proposalResult['action'],
                                'parameters'    => (array) ($proposalResult['parameters'] ?? []),
                                'required_role' => (string) ($proposalResult['required_role'] ?? ''),
                                'decision'      => (string) $proposalResult['decision'],
                                'risk_level'    => (string) ($proposalResult['risk_level'] ?? ''),
                                'expires_at'    => (string) ($proposalResult['expires_at'] ?? ''),
                            ];
                        } else {
                            $activeProposal = null;
                        }
                        $toolResult = $proposalResult;
                    } elseif ($functionName === 'calculate_financing_readiness') {
                        if ($cachedFinancingReadiness === null) {
                            $cachedFinancingReadiness = $this->executeCalculateFinancingReadiness($user, $request);
                        }
                        $toolResult = $cachedFinancingReadiness;
                    } elseif ($functionName === 'simulate_purchase') {
                        $purchaseMemoKey = md5(serialize($parsedArgs));
                        if (!isset($cachedPurchaseSimulations[$purchaseMemoKey])) {
                            $cachedPurchaseSimulations[$purchaseMemoKey] = $this->executeSimulatePurchase($user, $request, $parsedArgs);
                        }
                        $toolResult = $cachedPurchaseSimulations[$purchaseMemoKey];
                    } elseif ($functionName === 'get_menu_intelligence') {
                        $menuMemoKey = md5(serialize($parsedArgs));
                        if (!isset($cachedMenuIntelligence[$menuMemoKey])) {
                            $cachedMenuIntelligence[$menuMemoKey] = $this->executeGetMenuIntelligence($user, $request, $parsedArgs);
                        }
                        $toolResult = $cachedMenuIntelligence[$menuMemoKey];
                    } elseif ($functionName === 'get_financial_action_recommendations') {
                        if ($cachedActionRecommendations === null) {
                            $cachedActionRecommendations = $this->executeGetFinancialActionRecommendations($user, $request, $parsedArgs);
                        }
                        $toolResult = $cachedActionRecommendations;
                    } elseif (array_key_exists($functionName, $this->orchestrator->getRegisteredTools())) {
                        if ($userId === null || (int) $userId <= 0) {
                            $toolResult = [
                                'status'  => 'ERROR',
                                'code'    => 'UNAUTHORIZED_USER',
                                'message' => 'Pengguna tidak terautentikasi.',
                                'data'    => null,
                            ];
                        } else {
                            $memoKey = $functionName . ':' . md5(serialize($parsedArgs));
                            if (!isset($requestToolMemo[$memoKey])) {
                                $requestToolMemo[$memoKey] = $this->orchestrator->dispatchTool($functionName, (int) $userId, $outletId, $parsedArgs);
                            }
                            $toolResult = $requestToolMemo[$memoKey];
                        }
                        $lastOrchestrationResult = $toolResult;

                        if ($functionName === 'get_risk_radar' && ($toolResult['status'] ?? '') === 'SUCCESS') {
                            $cachedFinancialWarnings = $toolResult['data'];
                        } elseif ($functionName === 'get_financing_readiness' && ($toolResult['status'] ?? '') === 'SUCCESS') {
                            $cachedFinancingReadiness = $toolResult['data'];
                        } elseif ($functionName === 'get_financial_performance_intelligence' && ($toolResult['status'] ?? '') === 'SUCCESS') {
                            $cachedPerformanceIntelligence = $toolResult['data'];
                        } elseif ($functionName === 'get_suppliers' && ($toolResult['status'] ?? '') === 'SUCCESS') {
                            $cachedSuppliers = $toolResult['data'] ?? [];
                        } elseif ($functionName === 'generate_supplier_restock_message' && ($toolResult['status'] ?? '') === 'SUCCESS') {
                            $cachedSupplierRestock = $toolResult['data'] ?? [];
                        } elseif ($functionName === 'prepare_action_proposal') {
                            $proposalData = $toolResult['data'] ?? [];
                            if (!empty($proposalData['proposal_id']) && in_array($proposalData['decision'] ?? '', ['REQUIRES_APPROVAL', 'ALLOW', 'PENDING'], true)) {
                                $activeProposal = [
                                    'proposal_id'   => (string) $proposalData['proposal_id'],
                                    'action'        => (string) $proposalData['action'],
                                    'parameters'    => (array) ($proposalData['parameters'] ?? []),
                                    'required_role' => (string) ($proposalData['required_role'] ?? ''),
                                    'decision'      => (string) $proposalData['decision'],
                                    'risk_level'    => (string) ($proposalData['risk_level'] ?? ''),
                                    'expires_at'    => (string) ($proposalData['expires_at'] ?? ''),
                                ];
                            } else {
                                $activeProposal = null;
                            }
                        }
                    } else {
                        $toolResult = [
                            'status'  => 'ERROR',
                            'code'    => 'UNKNOWN_TOOL',
                            'message' => "Tool '{$functionName}' tidak terdaftar atau tidak diizinkan.",
                        ];
                    }

                    $mergedForLlm = (isset($toolResult['data']) && is_array($toolResult['data']))
                        ? array_merge($toolResult['data'], $toolResult)
                        : $toolResult;

                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $toolCallId,
                        'name'         => $functionName,
                        'content'      => json_encode($mergedForLlm, JSON_UNESCAPED_UNICODE),
                    ];
                }

                // STEP 3: Lakukan Panggilan Kedua (Menyuruh LLM mencerna Hasil DB)
                $isEn = (strtolower(trim((string) app()->getLocale())) === 'en');
                $reminderContent = $isEn
                    ? "[SYSTEM REMINDER] Active user session locale is English (en). You MUST formulate your final answer, explanation, and analysis strictly in clear, professional English. Do NOT answer in Indonesian."
                    : "[PENGINGAT SISTEM] Locale aktif sesi pengguna adalah Bahasa Indonesia (id). Anda WAJIB menyusun seluruh jawaban, penjelasan, dan analisis sepenuhnya dalam Bahasa Indonesia bisnis.";

                $messagesStep2 = $messages;
                $messagesStep2[] = [
                    'role'    => 'system',
                    'content' => $reminderContent,
                ];

                $payloadStep2 = [
                    'model'       => $model,
                    'temperature' => 0.4,
                    'max_tokens'  => 1024,
                    'stream'      => false,
                    'messages'    => $messagesStep2,
                ];

                $response2 = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])->connectTimeout($aiConnectTimeout)->timeout($aiTimeout)->post($url, $payloadStep2);

                if ($response2->status() === 429) {
                    $retryAfter2 = self::parseRetryAfter($response2);
                    if ($retryAfter2 !== null && $retryAfter2 > 3.0) {
                        Log::warning('AI Provider Rate Limit (429) Step 2: Retry-After (' . $retryAfter2 . 's) exceeds budget (3.0s). Skipping retry.', [
                            'user_id'     => optional($user)->id,
                            'retry_after' => $retryAfter2,
                        ]);
                    } else {
                        $waitTime2 = ($retryAfter2 !== null) ? (int) ceil($retryAfter2) : 2;
                        if ($waitTime2 > 3) {
                            $waitTime2 = 3;
                        }
                        if ($waitTime2 > 0) {
                            sleep($waitTime2);
                        }

                        $response2 = Http::withHeaders([
                            'Authorization' => 'Bearer ' . $apiKey,
                            'Content-Type'  => 'application/json',
                        ])->connectTimeout($aiConnectTimeout)->timeout($aiTimeout)->post($url, $payloadStep2);
                    }
                }

                if ($response2->successful() && !empty($response2->json('choices.0.message.content'))) {
                    $content = $response2->json('choices.0.message.content');
                    $botReply = (!empty(trim($content ?? ''))) ? $content : __('copilot.summaries.default_summary');
                } else {
                    $finishReason2 = $response2->json('choices.0.finish_reason');
                    $hasReasoning2 = !empty($response2->json('choices.0.message.reasoning_content'));
                    if ($response2->successful() && empty($response2->json('choices.0.message.content'))) {
                        Log::warning('AI Provider Step 2 Empty Content', [
                            'finish_reason' => $finishReason2,
                            'has_reasoning' => $hasReasoning2,
                            'reasoning_len' => strlen((string) $response2->json('choices.0.message.reasoning_content')),
                        ]);
                    }
                    if ($response2->status() === 429) {
                        Log::warning('AI Provider Rate Limit (429) Step 2: ' . \Illuminate\Support\Str::limit($response2->body(), 200) . '. Generating fallback from tool data.');
                    } else {
                        Log::warning('AI Provider Error Step 2: ' . $response2->status() . ' - ' . \Illuminate\Support\Str::limit($response2->body(), 200) . '. Generating fallback from tool data.');
                    }
                    $botReply = $this->generateToolFallbackSummary(
                        $todaySalesSummary,
                        $cachedFinancialWarnings,
                        $cachedFinancialScenarios,
                        $cachedCashFlowForecast,
                        $cachedPerformanceIntelligence,
                        $cachedActionRecommendations,
                        $cachedExternalRadars,
                        $cachedMenuIntelligence,
                        $cachedTopSellingProducts,
                        $cachedSuppliers,
                        $cachedSupplierRestock
                    );
                }
            } else {
                // Tidak ada upaya pemanggilan Alat, Berikan Reaksi Konvensional.
                $content = $assistantMessage['content'] ?? null;
                $botReply = (!empty(trim($content ?? ''))) ? $content : __('copilot.system.error_general');
            }

            $replyText = trim($botReply);
            if ($replyText === '') {
                $replyText = __('copilot.summaries.default_summary');
            }

            $financialCard = $this->buildFinancialCard(
                $cachedFinancialWarnings,
                $cachedFinancialScenarios,
                $cachedCashFlowForecast,
                $todaySalesSummary,
                $cachedExternalRadars,
                $cachedFinancingReadiness,
                $cachedActionRecommendations,
                $cachedPerformanceIntelligence
            );

            $responseData = [
                'reply'          => $replyText,
                'financial_card' => $financialCard,
            ];

            $responsePayload = [
                'status'         => 'success',
                'reply'          => $replyText,
                'financial_card' => $financialCard,
            ];

            if ($activeProposal !== null) {
                $responseData['proposal'] = $activeProposal;
                $responsePayload['proposal'] = $activeProposal;
            }

            if ($cachedSupplierRestock !== null) {
                $responseData['supplier_order'] = $cachedSupplierRestock;
                $responsePayload['supplier_order'] = $cachedSupplierRestock;
            }

            if ($lastOrchestrationResult !== null) {
                if (isset($lastOrchestrationResult['explainability'])) {
                    $responseData['explainability'] = $lastOrchestrationResult['explainability'];
                    $responsePayload['explainability'] = $lastOrchestrationResult['explainability'];
                }
                if (isset($lastOrchestrationResult['governance'])) {
                    $responseData['governance'] = $lastOrchestrationResult['governance'];
                    $responsePayload['governance'] = $lastOrchestrationResult['governance'];
                }
            }

            $responsePayload['data'] = $responseData;

            return response()->json($responsePayload);
        } catch (\Throwable $e) {
            if (!str_starts_with($e->getMessage(), 'API Error')) {
                Log::error('Chatbot Exception: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            $isTimeout = ($e instanceof \Illuminate\Http\Client\ConnectionException || str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'cURL error 28'));
            $providerReason = $isTimeout ? 'provider_timeout' : (str_starts_with($e->getMessage(), 'API Error') ? 'provider_http_error' : 'provider_exception');

            return $this->executeDeterministicFallback($user, $request, $userMessage, $providerReason);
        }
    }

    /**
     * Mem-parsing durasi Retry-After dari header HTTP atau respons body LLM provider.
     * Mendukung format header detik (numeric), HTTP-date, retry-after-ms, dan pesan body 'try again in Xs/ms'.
     *
     * @param  \Illuminate\Http\Client\Response|null  $response
     * @return float|null Durasi tunggu dalam detik, atau null jika tidak terdeteksi.
     */
    public static function parseRetryAfter($response): ?float
    {
        if ($response === null) {
            return null;
        }

        // 1. Cek header Retry-After standar
        $header = $response->header('Retry-After');
        if (!empty($header)) {
            if (is_numeric($header)) {
                return (float) $header;
            }
            $timestamp = strtotime($header);
            if ($timestamp !== false && $timestamp > time()) {
                return (float) ($timestamp - time());
            }
        }

        // 2. Cek header retry-after-ms
        $headerMs = $response->header('retry-after-ms');
        if (!empty($headerMs) && is_numeric($headerMs)) {
            return (float) ($headerMs / 1000.0);
        }

        // 3. Cek pola text dalam response body
        $body = is_string($response->body()) ? $response->body() : json_encode($response->json());
        if (!empty($body)) {
            if (preg_match('/try again in\s+([\d\.]+)\s*s/i', $body, $matches)) {
                return (float) $matches[1];
            }
            if (preg_match('/try again in\s+([\d\.]+)\s*ms/i', $body, $matches)) {
                return (float) ($matches[1] / 1000.0);
            }
        }

        return null;
    }

    /**
     * Mem-parsing parameter simulasi pembiayaan dari teks pengguna secara deterministik (Phase 5.16-B).
     */
    public static function parseFinancingParameters(string $message): array
    {
        $amount = 50000000.0; // default standard SME benchmark
        $tenor = 12;

        // Parse amount (juta / jt / digit)
        if (preg_match('/(?:rp\.?\s*)?(\d+(?:[\.,]\d+)?)\s*(?:juta|jt)\b/i', $message, $m)) {
            $num = (float) str_replace(',', '.', $m[1]);
            $amount = $num * 1000000.0;
        } elseif (preg_match('/(?:rp\.?\s*)(\d{1,3}(?:\.\d{3})+)/i', $message, $m)) {
            $amount = (float) str_replace('.', '', $m[1]);
        } elseif (preg_match('/(?:pinjam|modal|butuh)\s+(?:rp\.?\s*)?(\d{6,})/i', $message, $m)) {
            $amount = (float) $m[1];
        }

        // Parse tenor
        if (preg_match('/(\d+)\s*(?:bulan|bln)\b/i', $message, $m)) {
            $t = (int) $m[1];
            if ($t >= 3 && $t <= 120) {
                $tenor = $t;
            }
        }

        return [
            'amount' => $amount,
            'tenor'  => $tenor,
        ];
    }

    /**
     * Menjalankan simulasi kenaikan/penurunan harga menu What-If deterministik (Phase 5.17).
     */
    protected function executeDeterministicPriceSimulation(int $userId, ?int $outletId, string $message): array
    {
        $delta = 2000.0;
        $matchedPct = null;
        if (preg_match('/(?:naik|increase|up)\s*(\d+)%/i', $message, $mPct)) {
            $matchedPct = (float) $mPct[1];
        } elseif (preg_match('/(?:turun|drop|decline|decrease|down)\s*(\d+)%/i', $message, $mPct)) {
            $matchedPct = -(float) $mPct[1];
        } elseif (preg_match('/(?:naik|increase|up)\s*(?:rp\.?\s*)?(\d{1,3}(?:\.\d{3})+|\d+)/i', $message, $m)) {
            $delta = (float) str_replace('.', '', $m[1]);
        } elseif (preg_match('/(?:turun|drop|decline|decrease|down)\s*(?:rp\.?\s*)?(\d{1,3}(?:\.\d{3})+|\d+)/i', $message, $m)) {
            $delta = -(float) str_replace('.', '', $m[1]);
        }

        $menuNameQuery = null;
        if (preg_match('/(?:harga|price of)\s+([^,]+?)\s+(?:naik|turun|increase|drop|decrease|up|down)/i', $message, $m)) {
            $menuNameQuery = trim($m[1]);
        }

        $menu = null;
        if ($menuNameQuery) {
            $menu = \App\Models\Menu::where('user_id', $userId)
                ->where(function ($q) use ($menuNameQuery) {
                    $q->where('name', 'LIKE', "%{$menuNameQuery}%")
                      ->orWhere('name', 'LIKE', '%' . str_replace(' ', '%', $menuNameQuery) . '%');
                })
                ->first();
        }

        if (!$menu) {
            $menu = \App\Models\Menu::where('user_id', $userId)->first();
        }

        $menuName = $menu ? $menu->name : ($menuNameQuery ?: 'Ayam Geprek');
        $currentPrice = $menu ? (float) $menu->price : 20000.0;
        if ($matchedPct !== null) {
            $delta = round($currentPrice * ($matchedPct / 100));
        }

        $costPrice = $menu && ((float) ($menu->cost_price ?? 0) > 0 || (float) ($menu->cost ?? 0) > 0)
            ? (float) ($menu->cost_price ?: $menu->cost)
            : round($currentPrice * 0.40);

        $newPrice = max(1000.0, $currentPrice + $delta);

        $volume = 120;
        if ($menu) {
            $histVolume = \Illuminate\Support\Facades\DB::table('transaction_items')
                ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
                ->where('transactions.user_id', $userId)
                ->where('transaction_items.menu_id', $menu->id)
                ->sum('transaction_items.qty');
            if ($histVolume > 0) {
                $volume = (int) $histVolume;
            }
        }

        $baseRev = $volume * $currentPrice;
        $baseGrossProfit = $volume * ($currentPrice - $costPrice);
        $baseMargin = $currentPrice > 0 ? round((($currentPrice - $costPrice) / $currentPrice) * 100, 1) : 0.0;

        $simRev = $volume * $newPrice;
        $simGrossProfit = $volume * ($newPrice - $costPrice);
        $simMargin = $newPrice > 0 ? round((($newPrice - $costPrice) / $newPrice) * 100, 1) : 0.0;

        $deltaRev = $simRev - $baseRev;
        $deltaGrossProfit = $simGrossProfit - $baseGrossProfit;
        $deltaMarginPp = round($simMargin - $baseMargin, 1);

        $fmtCurPrice = number_format($currentPrice, 0, ',', '.');
        $fmtNewPrice = number_format($newPrice, 0, ',', '.');
        $fmtBaseRev  = number_format($baseRev, 0, ',', '.');
        $fmtSimRev   = number_format($simRev, 0, ',', '.');
        $fmtSimGP    = number_format($simGrossProfit, 0, ',', '.');
        $fmtDeltaRev = ($deltaRev >= 0 ? '+' : '') . number_format($deltaRev, 0, ',', '.');
        $fmtDeltaGP  = ($deltaGrossProfit >= 0 ? '+' : '') . number_format($deltaGrossProfit, 0, ',', '.');
        $fmtDeltaMargin = ($deltaMarginPp >= 0 ? '+' : '') . $deltaMarginPp;

        $hdrSim = __('copilot.simulation.header');
        $lblBaseline = __('copilot.simulation.baseline');
        $lblCurPrice = __('copilot.simulation.current_price');
        $lblVolume = __('copilot.simulation.volume');
        $lblRevenue = __('copilot.simulation.revenue');
        $lblMargin = __('copilot.simulation.margin');
        $lblSimHdr = __('copilot.simulation.sim_header');
        $lblNewPrice = __('copilot.simulation.new_price');
        $lblEstRev = __('copilot.simulation.est_revenue');
        $lblEstGP = __('copilot.simulation.est_gross_profit');
        $lblDeltaHdr = __('copilot.simulation.delta_header');
        $lblGP = __('copilot.simulation.gross_profit');
        $lblImportant = __('copilot.simulation.important');
        $lblDisclaimer = __('copilot.simulation.disclaimer');
        $lblPosGuard = __('copilot.simulation.pos_guard');
        $lblActionCta = __('copilot.simulation.action_cta');

        $formattedText = <<<EOT
{$hdrSim}
------------------
{$lblBaseline}
{$lblCurPrice}: Rp{$fmtCurPrice}
{$lblVolume}: {$volume}
{$lblRevenue}: Rp{$fmtBaseRev}
{$lblMargin}: {$baseMargin}%

{$lblSimHdr}
{$lblNewPrice}: Rp{$fmtNewPrice}
{$lblEstRev}: Rp{$fmtSimRev}
{$lblEstGP}: Rp{$fmtSimGP}
{$lblMargin}: {$simMargin}%

{$lblDeltaHdr}
{$lblRevenue}: Rp{$fmtDeltaRev}
{$lblGP}: Rp{$fmtDeltaGP}
{$lblMargin}: {$fmtDeltaMargin} pp

{$lblImportant}
Label: {$hdrSim}
{$lblDisclaimer}
{$lblPosGuard}
{$lblActionCta}
EOT;

        $simPayload = [
            'menu_id'            => $menu ? $menu->id : 1,
            'menu_name'          => $menuName,
            'current_price'      => $currentPrice,
            'new_price'          => $newPrice,
            'price_delta'        => $delta,
            'volume'             => $volume,
            'base_revenue'       => $baseRev,
            'sim_revenue'        => $simRev,
            'base_gross_profit'  => $baseGrossProfit,
            'sim_gross_profit'   => $simGrossProfit,
            'base_margin'        => $baseMargin,
            'sim_margin'         => $simMargin,
            'delta_revenue'      => $deltaRev,
            'delta_gross_profit' => $deltaGrossProfit,
            'delta_margin_pp'    => $deltaMarginPp,
            'formatted_output'   => $formattedText,
        ];

        \Illuminate\Support\Facades\Cache::put("copilot:last_simulation:{$userId}:{$outletId}", $simPayload, now()->addMinutes(15));

        return $simPayload;
    }

    /**
     * Menyiapkan Action Proposal resmi dari simulasi terakhir (Phase 5.17).
     */
    protected function executeDeterministicActionProposal(int $userId, ?int $outletId, string $message): array
    {
        $lastSim = \Illuminate\Support\Facades\Cache::get("copilot:last_simulation:{$userId}:{$outletId}");
        if (!$lastSim) {
            $msg = __('copilot.simulation.no_active_simulation');
            return [
                'status'           => 'ERROR',
                'code'             => 'NO_ACTIVE_SIMULATION',
                'message'          => $msg,
                'formatted_output' => $msg,
            ];
        }

        /** @var \App\Services\Policy\ActionPolicyService $policyService */
        $policyService = app(\App\Services\Policy\ActionPolicyService::class);

        $policyParams = [
            'menu_id'   => $lastSim['menu_id'],
            'new_price' => $lastSim['new_price'],
            'reason'    => "Penyesuaian harga {$lastSim['menu_name']} pasca simulasi what-if",
        ];

        $policyResult = $policyService->evaluateProposal(
            $userId,
            $outletId,
            'menu.price_change',
            $policyParams
        );

        $proposalId = $policyResult['proposal_id'];
        $fmtCurPrice = number_format($lastSim['current_price'], 0, ',', '.');
        $fmtNewPrice = number_format($lastSim['new_price'], 0, ',', '.');
        $fmtDeltaPrice = number_format(abs($lastSim['price_delta']), 0, ',', '.');
        $deltaSign = $lastSim['price_delta'] >= 0 ? 'Naikkan' : 'Turunkan';
        $fmtDeltaGP = number_format($lastSim['delta_gross_profit'], 0, ',', '.');

        $formattedText = <<<EOT
ACTION PROPOSAL
----------------
Action:
{$deltaSign} harga {$lastSim['menu_name']} Rp{$fmtDeltaPrice}

Expected impact:
Estimasi kenaikan laba kotor +Rp{$fmtDeltaGP} (Margin bertumbuh +{$lastSim['delta_margin_pp']} pp) tanpa penurunan volume drastis.

Risk:
HIGH

Status:
WAITING_FOR_APPROVAL

Proposal ID:
{$proposalId}

Tindakan:
[Setujui]
[Tolak]

Catatan Keamanan:
Proposal ini disimpan transien di cache (TTL 15m) dan TIDAK mengubah database secara langsung.
Persetujuan resmi memerlukan otorisasi Owner dan verifikasi PIN Manager.
EOT;

        return array_merge($policyResult, [
            'formatted_output' => $formattedText,
            'status'           => 'SUCCESS',
            'proposal_status'  => 'WAITING_FOR_APPROVAL',
            'buttons'          => ['Setujui', 'Tolak'],
        ]);
    }

    /**
     * Menjalankan fallback deterministik yang jujur saat LLM provider mengalami rate limit atau error.
     */
    public function executeDeterministicFallback(?User $user, Request $request, ?string $userMessage, ?string $providerErrorReason = null): JsonResponse
    {
        $isCopilotRoute = $request->is('copilot/*') || $request->is('copilot') || $request->routeIs('copilot.*');
        if (!$user && $isCopilotRoute) {
            $reply = __('copilot.system.unauthenticated') ?? 'Sesi pengguna tidak valid atau belum terautentikasi.';
            return response()->json([
                'status'  => 'error',
                'code'    => 'UNAUTHENTICATED',
                'message' => $reply,
                'reply'   => $reply,
                'data'    => ['reply' => $reply, 'code' => 'UNAUTHENTICATED', 'message' => $reply]
            ], 401);
        }

        if ($user) {
            // Check if an unauthorized outlet was explicitly passed in request
            $candidateOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
            if (!empty($candidateOutlet) && is_numeric($candidateOutlet)) {
                $belongs = Outlet::where('user_id', (int) $user->id)->where('id', (int) $candidateOutlet)->exists();
                if (!$belongs) {
                    $reply = __('copilot.system.unauthorized_outlet') ?? 'Akses ditolak: Anda tidak memiliki izin untuk mengakses data outlet tersebut. Silakan pilih outlet yang terdaftar pada akun Anda.';
                    return response()->json([
                        'status'         => 'error',
                        'code'           => 'UNAUTHORIZED_OUTLET',
                        'message'        => $reply,
                        'reply'          => $reply,
                        'financial_card' => null,
                        'proposal'       => null,
                        'data'           => [
                            'reply'          => $reply,
                            'message'        => $reply,
                            'code'           => 'UNAUTHORIZED_OUTLET',
                            'financial_card' => null,
                            'proposal'       => null,
                        ]
                    ], 403);
                }
            }

            $lowerMsg = strtolower($userMessage ?? '');

            // Phase 5.18.2: Standalone Greeting Handler
            // Ensure financial, operational, or supplier requests are never classified as greetings.
            $isFinancialOrBusinessQuery = (
                str_contains($lowerMsg, 'pinjam') || str_contains($lowerMsg, 'tenor') ||
                str_contains($lowerMsg, 'mampu bayar') || str_contains($lowerMsg, 'cicil') ||
                str_contains($lowerMsg, 'modal') || str_contains($lowerMsg, 'loan') ||
                str_contains($lowerMsg, 'borrow') || str_contains($lowerMsg, 'credit') ||
                str_contains($lowerMsg, 'profit') || str_contains($lowerMsg, 'laba') ||
                str_contains($lowerMsg, 'omzet') || str_contains($lowerMsg, 'omset') ||
                str_contains($lowerMsg, 'penjualan') || str_contains($lowerMsg, 'pendapatan') ||
                str_contains($lowerMsg, 'sales') || str_contains($lowerMsg, 'revenue') ||
                str_contains($lowerMsg, 'cash') || str_contains($lowerMsg, 'kas') ||
                str_contains($lowerMsg, 'biaya') || str_contains($lowerMsg, 'beban') ||
                str_contains($lowerMsg, 'cost') || str_contains($lowerMsg, 'expense') ||
                str_contains($lowerMsg, 'pengeluaran') || str_contains($lowerMsg, 'operasional') ||
                str_contains($lowerMsg, 'stok') || str_contains($lowerMsg, 'stock') ||
                str_contains($lowerMsg, 'inventory') ||
                str_contains($lowerMsg, 'supplier') || str_contains($lowerMsg, 'vendor') ||
                str_contains($lowerMsg, 'terlaris') || str_contains($lowerMsg, 'paling laku') ||
                str_contains($lowerMsg, 'top menu') || str_contains($lowerMsg, 'top produk') ||
                str_contains($lowerMsg, 'terbanyak') || str_contains($lowerMsg, 'best seller') ||
                str_contains($lowerMsg, 'peringatan') || str_contains($lowerMsg, 'warning') ||
                str_contains($lowerMsg, 'alert') || str_contains($lowerMsg, 'rekomendasi') ||
                str_contains($lowerMsg, 'saran') || str_contains($lowerMsg, 'action') ||
                str_contains($lowerMsg, 'proposal') || str_contains($lowerMsg, 'simulasi') ||
                str_contains($lowerMsg, 'harga') || str_contains($lowerMsg, 'skenario') ||
                str_contains($lowerMsg, 'scenario') || str_contains($lowerMsg, 'forecast')
            );

            if (!$isFinancialOrBusinessQuery) {
                // Strip punctuation and surrounding whitespace for clean matching
                $cleanMsg = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $lowerMsg));
                $withoutSelamat = trim(preg_replace('/^(selamat|good)\s+/i', '', $cleanMsg));
                $greetingWords = ['halo', 'hai', 'hi', 'tes', 'test', 'pagi', 'siang', 'sore', 'malam', 'hello', 'hey', 'morning', 'afternoon', 'evening'];

                $isStandaloneGreeting = false;
                if (in_array($cleanMsg, $greetingWords, true) || in_array($withoutSelamat, $greetingWords, true)) {
                    $isStandaloneGreeting = true;
                } elseif (preg_match('/^(selamat\s+|good\s+)?(halo|hai|hi|tes|test|pagi|siang|sore|malam|hello|hey|morning|afternoon|evening)(\s+(kachi|copilot|kaching|admin|bot|koneksi|assistant))?$/i', $cleanMsg)) {
                    $isStandaloneGreeting = true;
                } elseif (preg_match('/^(tes|test)(\s+\d+)?$/i', $cleanMsg)) {
                    $isStandaloneGreeting = true;
                }

                if ($isStandaloneGreeting) {
                    $targetLocale = app()->getLocale();
                    if (preg_match('/^(halo|hai|hi|pagi|siang|sore|malam|selamat pagi|tes|test)\b/i', trim($userMessage))) {
                        $targetLocale = 'id';
                    } elseif (preg_match('/^(hello|hey|good morning|good afternoon|good evening)\b/i', trim($userMessage))) {
                        $targetLocale = 'en';
                    }

                    $userName = !empty($user->name) ? ' ' . e($user->name) : '';
                    $reply = !empty($userName)
                        ? __('copilot.greeting.personal', ['name' => $userName], $targetLocale)
                        : __('copilot.greeting.default', [], $targetLocale);
                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => [
                            'reply'          => $reply,
                            'financial_card' => null,
                        ],
                    ]);
                }
            }

            // Phase 5.16-B: Financing Capacity Simulator
            $isFinancingQuery = (
                str_contains($lowerMsg, 'pinjam') ||
                str_contains($lowerMsg, 'tenor') ||
                str_contains($lowerMsg, 'mampu bayar') ||
                str_contains($lowerMsg, 'cicil') ||
                str_contains($lowerMsg, 'loan') ||
                str_contains($lowerMsg, 'borrow') ||
                str_contains($lowerMsg, 'credit') ||
                (str_contains($lowerMsg, 'modal') && (str_contains($lowerMsg, 'mampu') || str_contains($lowerMsg, 'butuh') || str_contains($lowerMsg, 'juta') || str_contains($lowerMsg, 'jt')))
            );

            if ($isFinancingQuery) {
                $outletId = $this->resolveAuthorizedOutletId($user, $request);
                if ($outletId === -1) {
                    $reply = __('copilot.system.unauthorized_outlet');
                    return response()->json([
                        'status'         => 'error',
                        'code'           => 'UNAUTHORIZED_OUTLET',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => ['reply' => $reply, 'code' => 'UNAUTHORIZED_OUTLET', 'financial_card' => null]
                    ], 403);
                }

                $params = self::parseFinancingParameters($userMessage ?? '');
                /** @var \App\Services\Financial\FinancingCapacitySimulatorService $financingService */
                $financingService = app(\App\Services\Financial\FinancingCapacitySimulatorService::class);
                $simRes = $financingService->simulate((int) $user->id, $outletId, $params);

                return response()->json([
                    'status'         => 'success',
                    'reply'          => $simRes['formatted_output'] ?? 'Simulasi pembiayaan selesai.',
                    'financial_card' => null,
                    'data'           => array_merge(['reply' => $simRes['formatted_output'] ?? 'Simulasi pembiayaan selesai.'], $simRes),
                ]);
            }

            // Phase 5.16-A: Autonomous Financial Investigation
            $isInvestigationQuery = (
                str_contains($lowerMsg, 'kenapa profit') ||
                str_contains($lowerMsg, 'kenapa laba') ||
                str_contains($lowerMsg, 'kenapa omzet turun') ||
                str_contains($lowerMsg, 'kenapa omset turun') ||
                str_contains($lowerMsg, 'kenapa penjualan turun') ||
                str_contains($lowerMsg, 'apakah masalah saya dari biaya') ||
                str_contains($lowerMsg, 'kenapa cash') ||
                str_contains($lowerMsg, 'kenapa kas') ||
                str_contains($lowerMsg, 'apakah stok berpengaruh') ||
                str_contains($lowerMsg, 'why profit') ||
                str_contains($lowerMsg, 'why has my profit') ||
                str_contains($lowerMsg, 'why did profit') ||
                str_contains($lowerMsg, 'why sales dropped') ||
                preg_match('/\b(kenapa|mengapa|why|what\s+caused)\b.*\b(profit|laba|omzet|omset|penjualan|sales|revenue|cash|kas)\b.*\b(turun|menipis|drop|dropped|anjlok|decline|declined|decrease|decreased|down)\b/i', $lowerMsg) ||
                preg_match('/\b(apakah|kenapa|why|is|are)\b.*\b(biaya|beban|stok|stock|cost|costs|expense|expenses|inventory)\b.*\b(masalah|berpengaruh|bengkak|problem|issue|increasing|high)\b/i', $lowerMsg)
            );

            if ($isInvestigationQuery) {
                $outletId = $this->resolveAuthorizedOutletId($user, $request);
                if ($outletId === -1) {
                    $reply = __('copilot.system.unauthorized_outlet');
                    return response()->json([
                        'status'         => 'error',
                        'code'           => 'UNAUTHORIZED_OUTLET',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => ['reply' => $reply, 'code' => 'UNAUTHORIZED_OUTLET', 'financial_card' => null]
                    ], 403);
                }

                /** @var \App\Services\Financial\FinancialInvestigationService $investigationService */
                $investigationService = app(\App\Services\Financial\FinancialInvestigationService::class);
                $invRes = $investigationService->investigate((int) $user->id, $outletId, $userMessage ?? '');

                return response()->json([
                    'status'         => 'success',
                    'reply'          => $invRes['formatted_output'] ?? 'Investigasi selesai.',
                    'financial_card' => null,
                    'data'           => array_merge(['reply' => $invRes['formatted_output'] ?? 'Investigasi selesai.'], $invRes),
                ]);
            }

            // Phase 5.17: What-If Price Simulation Fallback
            $isPriceSimulationQuery = (
                (str_contains($lowerMsg, 'simulasikan') || str_contains($lowerMsg, 'simulasi') || str_contains($lowerMsg, 'simulate') || str_contains($lowerMsg, 'what-if') || str_contains($lowerMsg, 'what if') || str_contains($lowerMsg, 'bagaimana jika')) &&
                (str_contains($lowerMsg, 'harga') || str_contains($lowerMsg, 'price') || str_contains($lowerMsg, 'naik') || str_contains($lowerMsg, 'increase') || str_contains($lowerMsg, 'turun') || str_contains($lowerMsg, 'drop') || str_contains($lowerMsg, 'penjualan') || str_contains($lowerMsg, 'sales')) &&
                !$isFinancingQuery
            );

            if ($isPriceSimulationQuery) {
                $outletId = $this->resolveAuthorizedOutletId($user, $request);
                if ($outletId === -1) {
                    $reply = __('copilot.system.unauthorized_outlet');
                    return response()->json([
                        'status'         => 'error',
                        'code'           => 'UNAUTHORIZED_OUTLET',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => ['reply' => $reply, 'code' => 'UNAUTHORIZED_OUTLET', 'financial_card' => null]
                    ], 403);
                }

                $simData = $this->executeDeterministicPriceSimulation((int) $user->id, $outletId, $userMessage ?? '');

                return response()->json([
                    'status'         => 'success',
                    'reply'          => $simData['formatted_output'] ?? 'Simulasi selesai.',
                    'financial_card' => null,
                    'data'           => array_merge(['reply' => $simData['formatted_output'] ?? 'Simulasi selesai.'], $simData),
                ]);
            }

            // Phase 5.17: Action Proposal Generation Fallback
            $isActionProposalQuery = (
                str_contains($lowerMsg, 'buat action') ||
                str_contains($lowerMsg, 'buat proposal') ||
                str_contains($lowerMsg, 'ajukan action') ||
                str_contains($lowerMsg, 'propose action') ||
                str_contains($lowerMsg, 'create proposal') ||
                str_contains($lowerMsg, 'submit proposal')
            );

            if ($isActionProposalQuery) {
                $outletId = $this->resolveAuthorizedOutletId($user, $request);
                if ($outletId === -1) {
                    $reply = __('copilot.system.unauthorized_outlet');
                    return response()->json([
                        'status'         => 'error',
                        'code'           => 'UNAUTHORIZED_OUTLET',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => ['reply' => $reply, 'code' => 'UNAUTHORIZED_OUTLET', 'financial_card' => null]
                    ], 403);
                }

                $proposalData = $this->executeDeterministicActionProposal((int) $user->id, $outletId, $userMessage ?? '');

                return response()->json([
                    'status'         => 'success',
                    'reply'          => $proposalData['formatted_output'] ?? 'Proposal disiapkan.',
                    'financial_card' => null,
                    'data'           => array_merge(['reply' => $proposalData['formatted_output'] ?? 'Proposal disiapkan.'], $proposalData),
                ]);
            }

            // 1. Omzet / Penjualan Summary
            if (
                str_contains($lowerMsg, 'omzet') ||
                str_contains($lowerMsg, 'omset') ||
                str_contains($lowerMsg, 'penjualan') ||
                str_contains($lowerMsg, 'pendapatan') ||
                str_contains($lowerMsg, 'sales') ||
                str_contains($lowerMsg, 'revenue')
            ) {
                $detectedPeriod = 'today';
                if (str_contains($lowerMsg, 'kemarin') || str_contains($lowerMsg, 'yesterday')) {
                    $detectedPeriod = 'yesterday';
                } elseif (str_contains($lowerMsg, 'minggu ini') || str_contains($lowerMsg, 'pekan ini') || str_contains($lowerMsg, 'this week')) {
                    $detectedPeriod = 'this_week';
                } elseif (str_contains($lowerMsg, 'bulan ini') || str_contains($lowerMsg, 'this month')) {
                    $detectedPeriod = 'this_month';
                } elseif (str_contains($lowerMsg, 'bulan lalu') || str_contains($lowerMsg, 'last month')) {
                    $detectedPeriod = 'last_month';
                }

                $outletId = $this->resolveAuthorizedOutletId($user, $request);
                if ($outletId === -1) {
                    $reply = __('copilot.system.unauthorized_outlet');
                    return response()->json([
                        'status'         => 'error',
                        'code'           => 'UNAUTHORIZED_OUTLET',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => ['reply' => $reply, 'code' => 'UNAUTHORIZED_OUTLET', 'financial_card' => null]
                    ], 403);
                }

                $salesRes = $this->orchestrator->dispatchTool('get_sales_summary', (int) $user->id, $outletId, [
                    'period' => $detectedPeriod,
                ]);

                if (($salesRes['status'] ?? '') === 'SUCCESS') {
                    $data = $salesRes['data'];
                    $salesSummary = [
                        'amount'          => (float) ($data['revenue'] ?? 0),
                        'revenue'         => (float) ($data['revenue'] ?? 0),
                        'records_count'   => (int) ($data['records_count'] ?? 0),
                        'formatted_sales' => (string) ($data['formatted_sales'] ?? 'Rp 0'),
                        'date'            => (string) ($data['start_date'] ?? ''),
                        'start_date'      => (string) ($data['start_date'] ?? ''),
                        'end_date'        => (string) ($data['end_date'] ?? ''),
                        'label'           => (string) ($data['label'] ?? 'Hari Ini'),
                        'period'          => (string) ($data['period'] ?? 'today'),
                    ];

                    $reply = $this->generateToolFallbackSummary($salesSummary, null, [], null, null, null, [], [], null);
                    $card = $this->buildFinancialCard(null, [], null, $salesSummary, [], null, null, null);

                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => $card,
                        'data'           => [
                            'reply'          => $reply,
                            'financial_card' => $card,
                        ]
                    ]);
                } else {
                    $reply = __('copilot.summaries.sales_data_unavailable');
                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => [
                            'reply'          => $reply,
                            'financial_card' => null,
                        ]
                    ]);
                }
            }

            // 2. Top Selling Products
            if (
                str_contains($lowerMsg, 'terlaris') ||
                str_contains($lowerMsg, 'paling laku') ||
                str_contains($lowerMsg, 'top menu') ||
                str_contains($lowerMsg, 'top produk') ||
                str_contains($lowerMsg, 'terbanyak') ||
                str_contains($lowerMsg, 'best seller') ||
                str_contains($lowerMsg, 'top selling') ||
                str_contains($lowerMsg, 'most popular')
            ) {
                $detectedPeriod = 'today';
                if (str_contains($lowerMsg, 'kemarin') || str_contains($lowerMsg, 'yesterday')) {
                    $detectedPeriod = 'yesterday';
                } elseif (str_contains($lowerMsg, 'minggu ini') || str_contains($lowerMsg, 'pekan ini') || str_contains($lowerMsg, 'this week')) {
                    $detectedPeriod = 'this_week';
                } elseif (str_contains($lowerMsg, 'bulan ini') || str_contains($lowerMsg, 'this month')) {
                    $detectedPeriod = 'this_month';
                } elseif (str_contains($lowerMsg, 'bulan lalu') || str_contains($lowerMsg, 'last month')) {
                    $detectedPeriod = 'last_month';
                }

                $outletId = $this->resolveAuthorizedOutletId($user, $request);
                if ($outletId === -1) {
                    $reply = __('copilot.system.unauthorized_outlet');
                    return response()->json([
                        'status'         => 'error',
                        'code'           => 'UNAUTHORIZED_OUTLET',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => ['reply' => $reply, 'code' => 'UNAUTHORIZED_OUTLET', 'financial_card' => null]
                    ], 403);
                }

                $topRes = $this->orchestrator->dispatchTool('get_top_selling_products', (int) $user->id, $outletId, [
                    'period'  => $detectedPeriod,
                    'limit'   => 5,
                    'sort_by' => 'quantity',
                ]);

                if (($topRes['status'] ?? '') === 'SUCCESS') {
                    $reply = $this->generateToolFallbackSummary(null, null, [], null, null, null, [], [], $topRes['data']);
                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => [
                            'reply'          => $reply,
                            'financial_card' => null,
                        ]
                    ]);
                } else {
                    $reply = __('copilot.summaries.top_products_unavailable');
                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => [
                            'reply'          => $reply,
                            'financial_card' => null,
                        ]
                    ]);
                }
            }

            // 3. Menu BCG / SAW Intelligence
            if (str_contains($lowerMsg, 'bcg') || str_contains($lowerMsg, 'saw') || str_contains($lowerMsg, 'kuadran')) {
                $menuResult = $this->executeGetMenuIntelligence($user, $request, ['period' => 'this_month']);
                if (($menuResult['status'] ?? '') === 'SUCCESS') {
                    $reply = $this->generateToolFallbackSummary(null, null, [], null, null, null, [], [$menuResult], null);
                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => [
                            'reply'          => $reply,
                            'financial_card' => null,
                        ]
                    ]);
                }
            }

            // 4. Financial Warnings
            if (
                str_contains($lowerMsg, 'peringatan') ||
                str_contains($lowerMsg, 'warning') ||
                str_contains($lowerMsg, 'kesehatan') ||
                str_contains($lowerMsg, 'health') ||
                str_contains($lowerMsg, 'risk') ||
                str_contains($lowerMsg, 'risiko')
            ) {
                $warningResult = $this->executeGetActiveFinancialWarnings($user, $request);
                if (($warningResult['status'] ?? '') !== 'ERROR') {
                    $reply = $this->generateToolFallbackSummary(null, $warningResult, [], null, null, null, [], [], null);
                    $card = $this->buildFinancialCard($warningResult, [], null, null, [], null, null, null);
                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => $card,
                        'data'           => [
                            'reply'          => $reply,
                            'financial_card' => $card,
                        ]
                    ]);
                }
            }

            // 4b. Cash Flow Forecast Fallback
            if (
                str_contains($lowerMsg, 'cash flow') ||
                str_contains($lowerMsg, 'arus kas') ||
                str_contains($lowerMsg, 'proyeksi kas') ||
                str_contains($lowerMsg, 'cashflow')
            ) {
                $forecastResult = $this->executeGetCashFlowForecast($user, $request);
                if (($forecastResult['status'] ?? '') !== 'ERROR') {
                    $reply = $this->generateToolFallbackSummary(null, null, [], $forecastResult, null, null, [], [], null);
                    $card = $this->buildFinancialCard(null, [], $forecastResult, null, [], null, null, null);
                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => $card,
                        'data'           => [
                            'reply'          => $reply,
                            'financial_card' => $card,
                        ]
                    ]);
                }
            }

            // Deteksi dini intent restock & konfirmasi agar tidak terintersep oleh lookup direktori supplier biasa
            $isExplicitRestock = (
                str_contains($lowerMsg, 'wa') ||
                str_contains($lowerMsg, 'whatsapp') ||
                str_contains($lowerMsg, 'direct') ||
                (str_contains($lowerMsg, 'chat') && (str_contains($lowerMsg, 'buat') || str_contains($lowerMsg, 'pesan') || str_contains($lowerMsg, 'order') || str_contains($lowerMsg, 'kirim') || str_contains($lowerMsg, 'ke'))) ||
                (str_contains($lowerMsg, 'pesan') && (str_contains($lowerMsg, 'buat') || str_contains($lowerMsg, 'draf') || str_contains($lowerMsg, 'draft')))
            );

            $isAffirmation = (bool) preg_match('/^(ya|oke|ok|iya|siap|baik|gas|lanjut)(\s*[,.]?\s*(setuju|bisa|boleh|deal|paham))?[.!?, ]*$/i', trim($lowerMsg));

            // Deteksi respon parameter lanjutan restock (kuantitas dan/atau tanggal)
            $isParamFollowUp = (bool) preg_match('/(?:^|\b)(\d+(?:[.,]\d+)?)\s*(galon|pcs|kg|dus|karton|sak|box|unit|ikat|tabung|liter|l|gram|gr|ons|butir|botol|pack|pak|lusin|kaleng|jerigen|roll|lembar|karung)\b/i', $lowerMsg)
                || (bool) preg_match('/(?:^|\b)(?:tgl|tanggal|kirim|pengiriman)\b/i', $lowerMsg);

            // 5. Supplier / Vendor Lookup Fallback (hanya jika bukan permintaan draf/restock pemesanan)
            if ((str_contains($lowerMsg, 'supplier') || str_contains($lowerMsg, 'vendor') || str_contains($lowerMsg, 'pemasok')) && !$isExplicitRestock) {
                $outletId = $this->resolveAuthorizedOutletId($user, $request);
                if ($outletId === -1) {
                    $reply = __('copilot.system.unauthorized_outlet');
                    return response()->json([
                        'status'         => 'error',
                        'code'           => 'UNAUTHORIZED_OUTLET',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => ['reply' => $reply, 'code' => 'UNAUTHORIZED_OUTLET', 'financial_card' => null]
                    ], 403);
                }

                $suppQuery = '';
                if (preg_match('/supplier\s+([a-zA-Z0-9\s]+)/i', $lowerMsg, $matches)) {
                    $candidate = trim($matches[1]);
                    $candidate = trim(preg_replace('/\b(siapa|aja|apa|yang|ada|tolong|chatkan|hubungi|untuk|kirim|who|is|contact)\b/i', '', $candidate));
                    if (!empty($candidate)) {
                        $suppQuery = $candidate;
                    }
                }
                $suppArgs = [];
                if ($suppQuery !== '') {
                    $suppArgs['query'] = $suppQuery;
                }
                $suppRes = $this->orchestrator->dispatchTool('get_suppliers', (int) $user->id, $outletId, $suppArgs);
                if (($suppRes['status'] ?? '') === 'SUCCESS') {
                    $reply = $this->generateToolFallbackSummary(null, null, [], null, null, null, [], [], null, $suppRes['data']);
                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => [
                            'reply'          => $reply,
                            'financial_card' => null,
                        ]
                    ]);
                } else {
                    $reply = __('copilot.summaries.no_suppliers');
                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => [
                            'reply'          => $reply,
                            'financial_card' => null,
                        ]
                    ]);
                }
            }

            // 6. Radar Pasar Eksternal Fallback
            if (
                str_contains($lowerMsg, 'radar') ||
                str_contains($lowerMsg, 'sinyal pasar') ||
                str_contains($lowerMsg, 'harga bahan') ||
                str_contains($lowerMsg, 'komoditas') ||
                str_contains($lowerMsg, 'market signal') ||
                str_contains($lowerMsg, 'material price') ||
                str_contains($lowerMsg, 'commodity')
            ) {
                $outletId = $this->resolveAuthorizedOutletId($user, $request);
                if ($outletId === -1) {
                    $reply = __('copilot.system.unauthorized_outlet');
                    return response()->json([
                        'status'         => 'error',
                        'code'           => 'UNAUTHORIZED_OUTLET',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => ['reply' => $reply, 'code' => 'UNAUTHORIZED_OUTLET', 'financial_card' => null]
                    ], 403);
                }

                $radarRes = $this->executeGetExternalBusinessRadar($user, $request, ['signal_category' => 'COMMODITY']);
                $reply = $this->generateToolFallbackSummary(null, null, [], null, null, null, [$radarRes], [], null, null);
                $card = $this->buildFinancialCard(null, [], null, null, [md5(json_encode(['signal_category' => 'COMMODITY'])) => $radarRes], null, null, null);

                return response()->json([
                    'status'         => 'success',
                    'reply'          => $reply,
                    'financial_card' => $card,
                    'data'           => [
                        'reply'          => $reply,
                        'financial_card' => $card,
                    ]
                ]);
            }

            // 7. Permintaan Draf Chat WhatsApp / Restock Supplier Fallback
            // Cek apakah ada alur konteks restock aktif dalam riwayat percakapan sebelumnya
            $hasPriorRestockFlow = false;
            $hasPriorFinancialFlow = false;
            $rawHistoryCheck = $request->input('history') ?? $request->input('context');
            if (is_array($rawHistoryCheck) && !empty($rawHistoryCheck)) {
                foreach ($rawHistoryCheck as $turn) {
                    $c = strtolower((string) ($turn['content'] ?? ''));
                    if (str_contains($c, 'supplier') || str_contains($c, 'pemasok') || str_contains($c, 'restock') || str_contains($c, 'restok')) {
                        $hasPriorRestockFlow = true;
                    }
                    if (str_contains($c, 'omzet') || str_contains($c, 'omset') || str_contains($c, 'penjualan') || str_contains($c, 'sales') || str_contains($c, 'laba') || str_contains($c, 'margin') || str_contains($c, 'revenue') || str_contains($c, 'kas') || str_contains($c, 'transaksi') || str_contains($c, 'terlaris') || str_contains($c, 'top product')) {
                        $hasPriorFinancialFlow = true;
                    }
                }
            }

            // Jika pengguna memberikan afirmasi (ya/oke/siap) setelah konteks finansial/penjualan (bukan alur restock)
            if ($isAffirmation && $hasPriorFinancialFlow && !$hasPriorRestockFlow) {
                $isEn = (strtolower(trim((string) ($request->input('locale') ?? app()->getLocale()))) === 'en');
                $reply = $isEn
                    ? 'Would you like to review top-selling items, cash flow projections, or compare today’s sales with yesterday?'
                    : 'Apakah Anda ingin melihat rincian produk terlaris, proyeksi arus kas, atau membandingkan omzet hari ini dengan kemarin?';

                return response()->json([
                    'status'         => 'success',
                    'code'           => 'CLARIFICATION_NEEDED',
                    'is_fallback'    => true,
                    'reply'          => $reply,
                    'financial_card' => null,
                    'proposal'       => null,
                    'data'           => [
                        'reply'          => $reply,
                        'code'           => 'CLARIFICATION_NEEDED',
                        'is_fallback'    => true,
                        'financial_card' => null,
                        'proposal'       => null,
                    ]
                ]);
            }

            if ($isExplicitRestock || $isAffirmation || $isParamFollowUp || $hasPriorRestockFlow) {
                $rawHistory = $request->input('history');
                if (!is_array($rawHistory)) {
                    $rawHistory = $request->input('context');
                }

                // Sertakan pesan pengguna saat ini agar kuantitas/parameter terkini dapat diekstrak
                $fullHistory = is_array($rawHistory) ? $rawHistory : [];
                $fullHistory[] = ['role' => 'user', 'content' => $userMessage];

                $restockContext = $this->extractRestockContextFromHistory($fullHistory, (int) $user->id);

                // 1. Tangani ambiguitas nama supplier jika beberapa kandidat cocok dengan petunjuk yang sama
                if ($restockContext !== null && !empty($restockContext['ambiguous_supplier'])) {
                    $candidates = $restockContext['candidates'] ?? [];
                    $names = array_map(fn($s) => is_object($s) ? $s->name : ($s['name'] ?? ''), $candidates);
                    $hint = $restockContext['matched_hint'] ?? 'tersebut';

                    $isEn = (strtolower(trim((string) app()->getLocale())) === 'en');
                    $supListStr = implode("\n", array_map(fn($idx, $n) => ($idx + 1) . ". {$n}", array_keys($names), $names));

                    if ($isEn) {
                        $reply = "Multiple suppliers matched the keyword '{$hint}':\n\n{$supListStr}\n\nPlease specify which supplier you would like to contact.";
                    } else {
                        $reply = "Ditemukan beberapa supplier yang cocok dengan kata '{$hint}':\n\n{$supListStr}\n\nMohon sebutkan nama supplier spesifik yang ingin Anda hubungi.";
                    }

                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => [
                            'reply'          => $reply,
                            'clarification'  => 'SUPPLIER_AMBIGUITY',
                            'candidates'     => $names,
                            'matched_hint'   => $hint,
                            'financial_card' => null,
                        ],
                    ]);
                }

                // 2. Jika supplier telah dipilih namun item belum ditentukan, minta pengguna menyebutkan item
                if ($restockContext !== null && !empty($restockContext['supplier_id']) && !empty($restockContext['needs_items'])) {
                    $supName = $restockContext['supplier_name'] ?? 'Supplier';
                    $isEn = (strtolower(trim((string) app()->getLocale())) === 'en');
                    if ($isEn) {
                        $reply = "Supplier **{$supName}** selected. What items and quantities would you like to order? (e.g. 10 kg rice)";
                    } else {
                        $reply = "Supplier **{$supName}** telah dipilih. Mau memesan item apa dan berapa jumlahnya? (contoh: beras 10 kg)";
                    }

                    return response()->json([
                        'status'         => 'success',
                        'reply'          => $reply,
                        'financial_card' => null,
                        'data'           => [
                            'reply'          => $reply,
                            'clarification'  => 'NEEDS_ITEMS',
                            'supplier_id'    => $restockContext['supplier_id'],
                            'supplier_name'  => $supName,
                            'financial_card' => null,
                        ],
                    ]);
                }

                // 3. Afirmasi singkat atau parameter HANYA memicu draf jika ada konteks restock valid dalam riwayat
                if ($isExplicitRestock || (($isAffirmation || $isParamFollowUp || $hasPriorRestockFlow) && $restockContext !== null && !empty($restockContext['supplier_id']) && !empty($restockContext['items']))) {
                    $outletId = $this->resolveAuthorizedOutletId($user, $request);
                    if ($outletId === -1) {
                        $reply = __('copilot.system.unauthorized_outlet');
                        return response()->json([
                            'status'         => 'error',
                            'code'           => 'UNAUTHORIZED_OUTLET',
                            'reply'          => $reply,
                            'financial_card' => null,
                            'data'           => ['reply' => $reply, 'code' => 'UNAUTHORIZED_OUTLET', 'financial_card' => null]
                        ], 403);
                    }

                    if ($restockContext !== null && !empty($restockContext['supplier_id']) && !empty($restockContext['items'])) {
                        // 1. Cek ambiguitas tanggal pengiriman: jangan menebak jika kontradiktif!
                        $dateAmbiguity = $this->detectDeliveryDateAmbiguity($userMessage);
                        if ($dateAmbiguity !== null && !empty($dateAmbiguity['has_conflict'])) {
                            $reply = $dateAmbiguity['message'];
                            return response()->json([
                                'status'         => 'success',
                                'reply'          => $reply,
                                'financial_card' => null,
                                'data'           => [
                                    'reply'          => $reply,
                                    'clarification'  => 'DATE_AMBIGUITY',
                                    'conflict'       => $dateAmbiguity,
                                    'financial_card' => null,
                                ]
                            ]);
                        }

                        // 2. Ekstrak tanggal valid jika ada (prioritas: pesan terkini, lalu context riwayat)
                        $deliveryDate = $this->extractDeliveryDateFromText($userMessage) ?? ($restockContext['delivery_date'] ?? null);

                        $toolArgs = [
                            'supplier_id' => $restockContext['supplier_id'],
                            'items'       => $restockContext['items'],
                        ];
                        if ($deliveryDate !== null && $deliveryDate !== '') {
                            $toolArgs['delivery_date'] = $deliveryDate;
                        }

                        $toolRes = $this->orchestrator->dispatchTool('generate_supplier_restock_message', (int) $user->id, $outletId, $toolArgs);

                        if (($toolRes['status'] ?? '') === 'SUCCESS' && !empty($toolRes['data']['success'])) {
                            $data = $toolRes['data'];
                            $supName = $data['supplier']['name'] ?? 'Supplier';
                            $waUrl = $data['whatsapp_url'] ?? '';
                            $draftMsg = $data['message'] ?? '';
                            $isEn = (strtolower(trim((string) app()->getLocale())) === 'en');

                            if ($isEn) {
                                $reply = "Here is the restock order draft for **{$supName}**:\n\n"
                                    . "> " . str_replace("\n", "\n> ", trim($draftMsg)) . "\n\n";
                                if (!empty($waUrl)) {
                                    $reply .= "[Open WhatsApp to Confirm Order]({$waUrl})\n\n"
                                        . "_Note: WhatsApp opens with prefilled message. The order is NOT sent automatically and no Purchase Order (PO) has been created._";
                                } else {
                                    $reply .= "_Note: Supplier WhatsApp number is not available or invalid._";
                                }
                            } else {
                                $reply = "Berikut draf pesan pemesanan restock untuk **{$supName}**:\n\n"
                                    . "> " . str_replace("\n", "\n> ", trim($draftMsg)) . "\n\n";
                                if (!empty($waUrl)) {
                                    $reply .= "[Buka WhatsApp untuk Konfirmasi Pesanan]({$waUrl})\n\n"
                                        . "_Catatan: Tautan membuka WhatsApp dengan pesan yang sudah terisi. Pesan TIDAK dikirim otomatis dan belum ada Purchase Order (PO) yang diterbitkan._";
                                } else {
                                    $reply .= "_Catatan: Nomor WhatsApp supplier belum tersedia atau tidak valid._";
                                }
                            }

                            return response()->json([
                                'status'         => 'success',
                                'reply'          => $reply,
                                'financial_card' => null,
                                'data'           => [
                                    'reply'          => $reply,
                                    'financial_card' => null,
                                    'supplier_order' => $data,
                                ]
                            ]);
                        } elseif (($toolRes['status'] ?? '') === 'UNAUTHORIZED_SUPPLIER' || ($toolRes['data']['status'] ?? '') === 'UNAUTHORIZED_SUPPLIER') {
                            $reply = 'Supplier tidak ditemukan atau bukan milik tenant Anda.';
                            return response()->json([
                                'status' => 'error',
                                'code'   => 'UNAUTHORIZED_SUPPLIER',
                                'reply'  => $reply,
                                'data'   => ['reply' => $reply, 'code' => 'UNAUTHORIZED_SUPPLIER']
                            ], 403);
                        }
                    }
                }

                // 4. Permintaan restock eksplisit namun belum memiliki supplier di tenant
                if ($isExplicitRestock) {
                    $hasSuppliers = \App\Models\Supplier::where('user_id', (int) $user->id)->exists();
                    if (!$hasSuppliers) {
                        $reply = __('copilot.system.no_suppliers_registered');
                        return response()->json([
                            'status'         => 'success',
                            'code'           => 'NO_TENANT_SUPPLIERS',
                            'is_fallback'    => true,
                            'reply'          => $reply,
                            'financial_card' => null,
                            'proposal'       => null,
                            'data'           => [
                                'reply'          => $reply,
                                'code'           => 'NO_TENANT_SUPPLIERS',
                                'is_fallback'    => true,
                                'financial_card' => null,
                                'proposal'       => null,
                            ]
                        ]);
                    }
                }

                // 5. Afirmasi atau parameter restock tanpa konteks yang valid (Fail-Closed)
                if ($isAffirmation || $isParamFollowUp) {
                    $reply = __('copilot.system.restock_context_missing');
                    return response()->json([
                        'status'         => 'success',
                        'code'           => 'RESTOCK_CONTEXT_MISSING',
                        'is_fallback'    => true,
                        'reply'          => $reply,
                        'financial_card' => null,
                        'proposal'       => null,
                        'data'           => [
                            'reply'          => $reply,
                            'code'           => 'RESTOCK_CONTEXT_MISSING',
                            'is_fallback'    => true,
                            'financial_card' => null,
                            'proposal'       => null,
                        ]
                    ]);
                }
            }
        }

        // Terminal fallback: Pesan tidak cocok dengan heuristik deterministik
        if ($providerErrorReason !== null) {
            $reply = __('copilot.system.clarification_ai_unavailable');
            $code = 'AI_PROVIDER_UNAVAILABLE';
        } else {
            $reply = __('copilot.system.clarification_default');
            $code = 'CLARIFICATION_NEEDED';
        }

        return response()->json([
            'status'         => 'success',
            'code'           => $code,
            'is_fallback'    => true,
            'reply'          => $reply,
            'financial_card' => null,
            'proposal'       => null,
            'data'           => [
                'reply'          => $reply,
                'code'           => $code,
                'is_fallback'    => true,
                'financial_card' => null,
                'proposal'       => null,
            ]
        ]);
    }

    /**
     * Mengeksekusi FinancialWarningService secara deterministik untuk user dan outlet terautentikasi.
     * Read-only, tanpa mutasi, dan memvalidasi isolasi tenant/outlet.
     *
     * @param  \App\Models\User|null  $user
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function executeGetActiveFinancialWarnings($user, Request $request): array
    {
        if (!$user || !isset($user->id)) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ];
        }

        $userId = (int) $user->id;
        $outletId = null;

        // Resolusi outlet dari staff terikat atau header/input request (tervalidasi milik tenant)
        if (!empty($user->outlet_id)) {
            $outletId = (int) $user->outlet_id;
        } else {
            $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
            if (!empty($requestedOutlet) && is_numeric($requestedOutlet)) {
                $requestedOutletId = (int) $requestedOutlet;
                $outletBelongsToUser = Outlet::where('user_id', $userId)
                    ->where('id', $requestedOutletId)
                    ->exists();

                if ($outletBelongsToUser) {
                    $outletId = $requestedOutletId;
                } else {
                    return [
                        'status'  => 'ERROR',
                        'code'    => 'UNAUTHORIZED_OUTLET',
                        'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
                    ];
                }
            }
        }

        try {
            /** @var FinancialWarningService $warningService */
            $warningService = app(FinancialWarningService::class);
            $cachedWarnings = $warningService->getCachedWarnings($userId, $outletId);

            $activeWarnings = [];
            $insufficientData = [];
            $hasNoWarning = false;

            foreach ($cachedWarnings as $warning) {
                $status = $warning['status'] ?? null;
                if ($status === FinancialWarningService::STATUS_WARNING) {
                    $activeWarnings[] = $warning;
                } elseif ($status === FinancialWarningService::STATUS_INSUFFICIENT_DATA) {
                    $insufficientData[] = [
                        'code'         => $warning['code'] ?? null,
                        'title'        => $warning['title'] ?? null,
                        'data_quality' => $warning['data_quality'] ?? null,
                        'limitations'  => $warning['limitations'] ?? [],
                        'message'      => $warning['message'] ?? null,
                    ];
                } elseif ($status === FinancialWarningService::STATUS_NO_WARNING) {
                    $hasNoWarning = true;
                }
            }

            $evidenceService = app(FinancialEvidenceService::class);

            if (count($activeWarnings) > 0) {
                return [
                    'status'   => 'HAS_WARNINGS',
                    'warnings' => $activeWarnings,
                    'evidence' => $evidenceService->wrapAllWarnings($activeWarnings, $insufficientData),
                ];
            }

            if ($hasNoWarning) {
                return [
                    'status'   => 'NO_WARNING',
                    'warnings' => [],
                    'evidence' => $evidenceService->wrapAllWarnings([], $insufficientData),
                ];
            }

            return [
                'status'       => 'INSUFFICIENT_DATA',
                'warnings'     => [],
                'data_quality' => $insufficientData,
                'evidence'     => $evidenceService->wrapAllWarnings([], $insufficientData),
            ];
        } catch (\Throwable $e) {
            Log::error('FinancialWarningService execution error in ChatbotController: ' . $e->getMessage(), [
                'user_id'   => $userId,
                'outlet_id' => $outletId,
            ]);

            return [
                'status'  => 'ERROR',
                'code'    => 'FINANCIAL_WARNING_UNAVAILABLE',
                'message' => 'Layanan peringatan finansial sedang tidak tersedia.',
            ];
        }
    }

    /**
     * Mengeksekusi FinancialScenarioService secara deterministik untuk user dan outlet terautentikasi.
     * Read-only, tanpa mutasi, memvalidasi isolasi tenant/outlet, dan menolak parameter terlarang.
     *
     * @param  \App\Models\User|null  $user
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $arguments
     * @return array
     */
    public function executeGetFinancialScenario($user, Request $request, array $arguments): array
    {
        if (!$user || !isset($user->id)) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ];
        }

        $userId = (int) $user->id;
        $outletId = null;

        // Resolusi outlet dari staff terikat atau header/input request (tervalidasi milik tenant)
        if (!empty($user->outlet_id)) {
            $outletId = (int) $user->outlet_id;
        } else {
            $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
            if (!empty($requestedOutlet) && is_numeric($requestedOutlet)) {
                $requestedOutletId = (int) $requestedOutlet;
                $outletBelongsToUser = Outlet::where('user_id', $userId)
                    ->where('id', $requestedOutletId)
                    ->exists();

                if ($outletBelongsToUser) {
                    $outletId = $requestedOutletId;
                } else {
                    return [
                        'status'  => 'ERROR',
                        'code'    => 'UNAUTHORIZED_OUTLET',
                        'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
                    ];
                }
            }
        }

        // Defense-in-depth: tolak parameter berbahaya / injeksi identitas tenant dari LLM
        $forbiddenKeys = ['user_id', 'outlet_id', 'sql', 'query', 'formula', 'threshold', 'select', 'drop', 'insert', 'update', 'delete'];
        foreach ($forbiddenKeys as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $arguments)) {
                return [
                    'status'  => 'ERROR',
                    'code'    => 'FORBIDDEN_PARAMETER',
                    'message' => "Parameter terlarang terdeteksi: '{$forbiddenKey}'.",
                ];
            }
        }

        // Basic schema validation: scenario_type wajib diisi
        if (empty($arguments['scenario_type'])) {
            return [
                'status'  => 'ERROR',
                'code'    => 'MISSING_SCENARIO_TYPE',
                'message' => "Parameter 'scenario_type' wajib diisi.",
            ];
        }

        try {
            /** @var FinancialScenarioService $scenarioService */
            $scenarioService = app(FinancialScenarioService::class);
            $scenarioResult = $scenarioService->simulate($userId, $outletId, $arguments);

            if (is_array($scenarioResult)) {
                $evidenceService = app(FinancialEvidenceService::class);
                $scenarioResult['evidence'] = $evidenceService->wrapScenario($scenarioResult);
            }

            return $scenarioResult;
        } catch (\Throwable $e) {
            Log::error('FinancialScenarioService execution error in ChatbotController: ' . $e->getMessage(), [
                'user_id'   => $userId,
                'outlet_id' => $outletId,
                'arguments' => $arguments,
            ]);

            return [
                'status'  => 'ERROR',
                'code'    => 'FINANCIAL_SCENARIO_UNAVAILABLE',
                'message' => 'Simulasi finansial sedang tidak tersedia.',
            ];
        }
    }

    /**
     * Mengeksekusi CashFlowForecastService secara deterministik untuk user dan outlet terautentikasi.
     * Read-only, tanpa mutasi, memvalidasi isolasi tenant/outlet, dan menolak parameter terlarang.
     *
     * @param  \App\Models\User|null  $user
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $arguments
     * @return array
     */
    public function executeGetCashFlowForecast($user, Request $request, array $arguments = []): array
    {
        if (!$user || !isset($user->id)) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ];
        }

        $userId = (int) $user->id;
        $outletId = null;

        // Resolusi outlet dari staff terikat atau header/input request (tervalidasi milik tenant)
        if (!empty($user->outlet_id)) {
            $outletId = (int) $user->outlet_id;
        } else {
            $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
            if (!empty($requestedOutlet) && is_numeric($requestedOutlet)) {
                $requestedOutletId = (int) $requestedOutlet;
                $outletBelongsToUser = Outlet::where('user_id', $userId)
                    ->where('id', $requestedOutletId)
                    ->exists();

                if ($outletBelongsToUser) {
                    $outletId = $requestedOutletId;
                } else {
                    return [
                        'status'  => 'ERROR',
                        'code'    => 'UNAUTHORIZED_OUTLET',
                        'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
                    ];
                }
            }
        }

        // Defense-in-depth: tolak parameter berbahaya / injeksi identitas tenant dari LLM
        $forbiddenKeys = ['user_id', 'outlet_id', 'sql', 'query', 'formula', 'threshold', 'select', 'drop', 'insert', 'update', 'delete'];
        foreach ($forbiddenKeys as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $arguments)) {
                return [
                    'status'  => 'ERROR',
                    'code'    => 'FORBIDDEN_PARAMETER',
                    'message' => "Parameter terlarang terdeteksi: '{$forbiddenKey}'.",
                ];
            }
        }

        try {
            /** @var CashFlowForecastService $forecastService */
            $forecastService = app(CashFlowForecastService::class);
            $forecastResult = $forecastService->forecast($userId, $outletId);

            if (is_array($forecastResult)) {
                $evidenceService = app(FinancialEvidenceService::class);
                $forecastResult['evidence'] = $evidenceService->wrapForecast($forecastResult);
            }

            return $forecastResult;
        } catch (\Throwable $e) {
            Log::error('CashFlowForecastService execution error in ChatbotController: ' . $e->getMessage(), [
                'user_id'   => $userId,
                'outlet_id' => $outletId,
            ]);

            return [
                'status'  => 'ERROR',
                'code'    => 'CASH_FLOW_FORECAST_UNAVAILABLE',
                'message' => 'Layanan proyeksi arus kas sedang tidak tersedia.',
            ];
        }
    }

    /**
     * Eksekutor Tool Radar Bisnis Eksternal (B.7).
     *
     * Beroperasi read-only, mempertahankan isolasi tenant, memblokir parameter terlarang,
     * dan membungkus hasil ke dalam canonical evidence envelope.
     */
    public function executeGetExternalBusinessRadar($user, Request $request, array $arguments = []): array
    {
        if (!$user || !isset($user->id)) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ];
        }

        $forbiddenKeys = [
            'user_id',
            'outlet_id',
            'tenant_id',
            'url',
            'uri',
            'fetch',
            'sql',
            'query',
            'raw',
            'formula',
            'threshold',
            'select',
            'drop',
            'insert',
            'update',
            'delete',
        ];

        foreach ($forbiddenKeys as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $arguments)) {
                return [
                    'status'  => 'ERROR',
                    'code'    => 'FORBIDDEN_PARAMETER',
                    'message' => "Parameter terlarang terdeteksi: '{$forbiddenKey}'.",
                ];
            }
        }

        $userId = (int) $user->id;
        $outletId = null;

        // Resolusi outlet dari staff terikat atau header/input request (tervalidasi milik tenant)
        if (!empty($user->outlet_id)) {
            $outletId = (int) $user->outlet_id;
        } else {
            $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
            if (!empty($requestedOutlet) && is_numeric($requestedOutlet)) {
                $requestedOutletId = (int) $requestedOutlet;
                $outletBelongsToUser = Outlet::where('user_id', $userId)
                    ->where('id', $requestedOutletId)
                    ->exists();

                if ($outletBelongsToUser) {
                    $outletId = $requestedOutletId;
                } else {
                    return [
                        'status'  => 'ERROR',
                        'code'    => 'UNAUTHORIZED_OUTLET',
                        'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
                    ];
                }
            }
        }

        $signalCategory = strtoupper(trim((string) ($arguments['signal_category'] ?? 'ALL')));
        if (!in_array($signalCategory, ['ALL', 'COMMODITY', 'WEATHER', 'OPERATIONAL'])) {
            $signalCategory = 'ALL';
        }

        try {
            /** @var \App\Services\Radar\ExternalBusinessRadarService $radarService */
            $radarService = app(\App\Services\Radar\ExternalBusinessRadarService::class);
            $radarResult = $radarService->inspect($userId, $outletId, $signalCategory);

            if (is_array($radarResult)) {
                /** @var \App\Services\Financial\FinancialEvidenceService $evidenceService */
                $evidenceService = app(\App\Services\Financial\FinancialEvidenceService::class);
                $radarResult['evidence'] = $evidenceService->wrapRadar($radarResult);
            }

            return $radarResult;
        } catch (\Throwable $e) {
            Log::error('ExternalBusinessRadar execution error in ChatbotController: ' . $e->getMessage(), [
                'user_id'   => $userId,
                'outlet_id' => $outletId,
                'category'  => $signalCategory,
            ]);

            return [
                'status'  => 'ERROR',
                'code'    => 'RADAR_UNAVAILABLE',
                'message' => 'Layanan radar bisnis eksternal sedang tidak tersedia.',
            ];
        }
    }

    /**
     * Eksekusi evaluasi proposal aksi bisnis dari Copilot via ActionPolicyService.
     *
     * Invariant:
     * - AI Copilot HANYA berperan sebagai PROPOSER.
     * - Tool ini TIDAK PERNAH melakukan mutasi langsung ke database.
     * - Parameter berbahaya (SQL injection, manipulasi tenant/user_id) diblokir secara tegas.
     */
    protected function executeProposeAction($user, Request $request, array $arguments): array
    {
        $userId = (int) $user->id;
        $outletId = null;

        // Resolusi outlet dari staff terikat atau header/input request (tervalidasi milik tenant)
        if (!empty($user->outlet_id)) {
            $outletId = (int) $user->outlet_id;
        } else {
            $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
            if (!empty($requestedOutlet) && is_numeric($requestedOutlet)) {
                $requestedOutletId = (int) $requestedOutlet;
                $outletBelongsToUser = Outlet::where('user_id', $userId)
                    ->where('id', $requestedOutletId)
                    ->exists();

                if ($outletBelongsToUser) {
                    $outletId = $requestedOutletId;
                } else {
                    return [
                        'status'  => 'ERROR',
                        'code'    => 'UNAUTHORIZED_OUTLET',
                        'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
                    ];
                }
            }
        }

        // Defense-in-depth: tolak parameter berbahaya / injeksi identitas tenant dari LLM
        $forbiddenKeys = [
            'user_id', 'outlet_id', 'tenant_id', 'role', 'required_role',
            'approval_required', 'approval_status', 'policy_decision',
            'sql', 'query', 'formula', 'threshold', 'select', 'drop',
            'insert', 'update', 'delete', 'url', 'uri', 'fetch',
            'class', 'method', 'controller'
        ];

        foreach ($forbiddenKeys as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $arguments)) {
                return [
                    'status'  => 'ERROR',
                    'code'    => 'FORBIDDEN_PARAMETER',
                    'message' => "Parameter '{$forbiddenKey}' dilarang demi keamanan sistem.",
                ];
            }
        }

        $action = (string) ($arguments['action'] ?? '');
        $parameters = isset($arguments['parameters']) && is_array($arguments['parameters']) ? $arguments['parameters'] : [];

        // Periksa juga forbidden keys di dalam parameters
        foreach ($forbiddenKeys as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $parameters)) {
                return [
                    'status'  => 'ERROR',
                    'code'    => 'FORBIDDEN_PARAMETER',
                    'message' => "Parameter '{$forbiddenKey}' dilarang di dalam sub-parameter.",
                ];
            }
        }

        try {
            if ($action === 'menu.price_change') {
                /** @var \App\Services\Financial\MenuMarginActionBridgeService $bridge */
                $bridge = app(\App\Services\Financial\MenuMarginActionBridgeService::class);
                $userRole = (string) ($user->role ?? 'OWNER');
                $bridgeResult = $bridge->createProposal($userId, $outletId, $parameters, $userRole);
                if (($bridgeResult['status'] ?? '') === 'SUCCESS') {
                    return $bridgeResult;
                }
            }

            /** @var \App\Services\Policy\ActionPolicyService $policyService */
            $policyService = app(\App\Services\Policy\ActionPolicyService::class);
            $userRole = (string) ($user->role ?? 'OWNER');
            $policyResult = $policyService->evaluateProposal($userId, $outletId, $action, $parameters, $userRole);
            if (!isset($policyResult['status'])) {
                $policyResult['status'] = 'SUCCESS';
            }
            return $policyResult;
        } catch (\Throwable $e) {
            Log::error('ActionPolicyService proposal evaluation error in ChatbotController: ' . $e->getMessage(), [
                'user_id'   => $userId,
                'outlet_id' => $outletId,
                'action'    => $action,
            ]);

            return [
                'status'  => 'ERROR',
                'code'    => 'POLICY_FIREWALL_ERROR',
                'message' => 'Layanan firewall kebijakan aksi sedang tidak tersedia.',
            ];
        }
    }

    /**
     * Resolves the authorized outlet ID for the given user, rejecting forged/unowned outlets.
     * Returns:
     * - ?int (null for all-outlets/unspecified, >0 for authorized outlet ID)
     * - -1 if an unowned/unauthorized outlet ID was explicitly requested
     */
    public function resolveAuthorizedOutletId($user, Request $request, array &$parsedArgs = []): ?int
    {
        if (!$user || !isset($user->id)) {
            return null;
        }

        $userId = (int) $user->id;

        // If user is bound to a specific outlet (staff/manager), that outlet is strictly authoritative
        if (!empty($user->outlet_id)) {
            unset($parsedArgs['outlet_id']);
            return (int) $user->outlet_id;
        }

        // Check if outlet is specified via header, request body, or tool arguments
        $candidateOutlet = $request->header('Outlet-ID', $request->input('outlet_id', $parsedArgs['outlet_id'] ?? null));
        unset($parsedArgs['outlet_id']);

        if (!empty($candidateOutlet) && is_numeric($candidateOutlet)) {
            $candidateOutletId = (int) $candidateOutlet;
            $belongs = Outlet::where('user_id', $userId)->where('id', $candidateOutletId)->exists();
            if ($belongs) {
                return $candidateOutletId;
            }

            // Explicitly requested an outlet not owned by this tenant -> unauthorized attempt
            return -1;
        }

        return null;
    }

    /**
     * Dispatch an orchestration tool through FinanceCopilotOrchestrationService
     * with authoritative user and outlet resolution.
     */
    public function dispatchOrchestrationTool($user, Request $request, string $toolName, array $arguments = []): array
    {
        if (!$user || !isset($user->id)) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ];
        }

        $userId = (int) $user->id;
        $sanitizedArgs = $arguments;
        unset($sanitizedArgs['user_id']);

        $outletId = $this->resolveAuthorizedOutletId($user, $request, $sanitizedArgs);
        if ($outletId === -1) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNAUTHORIZED_OUTLET',
                'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
            ];
        }

        if (!array_key_exists($toolName, $this->orchestrator->getRegisteredTools())) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNKNOWN_TOOL',
                'message' => "Tool '{$toolName}' tidak terdaftar atau tidak diizinkan.",
            ];
        }

        return $this->orchestrator->dispatchTool($toolName, $userId, $outletId, $sanitizedArgs);
    }

    /**
     * Helper to build structured financial card for UI rendering
     */
    protected function buildFinancialCard(
        $warnings = null,
        $scenarios = [],
        $forecast = null,
        $todaySales = null,
        $radars = [],
        $readiness = null,
        $recommendations = null,
        $performanceIntelligence = null
    ): ?array {
        // Priority 0: Action Recommendations
        if ($recommendations !== null && is_array($recommendations) && ($recommendations['status'] ?? '') === 'SUCCESS') {
            $recs = (array) ($recommendations['recommendations'] ?? []);
            if (!empty($recs)) {
                return [
                    'type'            => 'action_recommendations',
                    'title'           => __('copilot.cards.action_recommendations'),
                    'severity'        => 'watch',
                    'recommendations' => $recs,
                    'data'            => $recommendations,
                ];
            }
        }

        // Priority 0.5: Financial Performance Intelligence
        if ($performanceIntelligence !== null && is_array($performanceIntelligence) && in_array($performanceIntelligence['status'] ?? '', ['SUCCESS', 'INSUFFICIENT_EVIDENCE'], true)) {
            $summary = (array) ($performanceIntelligence['summary'] ?? []);
            return [
                'type'     => 'performance_intelligence',
                'title'    => __('copilot.cards.performance_intelligence'),
                'severity' => 'info',
                'data'     => [
                    'status'                        => (string) ($performanceIntelligence['status'] ?? 'SUCCESS'),
                    'anchor_date'                   => (string) ($performanceIntelligence['anchor_date'] ?? ''),
                    'observation_window_days'       => (int) ($performanceIntelligence['observation_window_days'] ?? 90),
                    'total_catalog_menus'           => (int) ($summary['total_catalog_menus'] ?? 0),
                    'active_selling_menus'          => (int) ($summary['active_selling_menus'] ?? 0),
                    'dormant_catalog_menus'         => (int) ($summary['dormant_catalog_menus'] ?? 0),
                    'gross_revenue'                 => (float) ($summary['gross_revenue'] ?? 0),
                    'covered_gross_revenue'         => (float) ($summary['covered_gross_revenue'] ?? ($summary['covered_revenue'] ?? 0)),
                    'revenue_cost_coverage_pct'     => (float) ($summary['revenue_cost_coverage_pct'] ?? 0),
                    'estimated_cogs'                => (float) ($summary['estimated_cogs'] ?? 0),
                    'estimated_contribution_margin' => (float) ($summary['estimated_contribution_margin'] ?? 0),
                    'estimated_margin_pct'          => (float) ($summary['estimated_margin_pct'] ?? 0),
                    'state_distribution'            => (array) ($performanceIntelligence['state_distribution'] ?? []),
                    'items_count'                   => count((array) ($performanceIntelligence['items'] ?? [])),
                ],
            ];
        }

        // Priority 1: Financial Warning Radar
        if ($warnings !== null && is_array($warnings)) {
            $status = (string) ($warnings['status'] ?? '');
            if ($status === 'HAS_WARNINGS') {
                $rawList = (array) ($warnings['warnings'] ?? []);
                $highestSev = 'watch';
                $sanitized = [];

                foreach ($rawList as $w) {
                    if (!is_array($w)) {
                        continue;
                    }
                    $s = strtolower((string) ($w['severity'] ?? 'watch'));
                    if ($s === 'critical') {
                        $highestSev = 'critical';
                    } elseif ($s === 'warning' && $highestSev !== 'critical') {
                        $highestSev = 'warning';
                    }

                    $sanitized[] = [
                        'code'        => (string) ($w['code'] ?? ''),
                        'title'       => (string) ($w['title'] ?? ''),
                        'severity'    => $s,
                        'message'     => (string) ($w['message'] ?? ''),
                        'action_hint' => (string) ($w['action_hint'] ?? ''),
                    ];
                }

                return [
                    'type'     => 'warning',
                    'title'    => __('copilot.cards.risk_radar'),
                    'severity' => $highestSev,
                    'data'     => [
                        'status'         => 'HAS_WARNINGS',
                        'warnings_count' => count($sanitized),
                        'warnings'       => $sanitized,
                    ],
                ];
            } elseif ($status === 'NO_WARNING') {
                return [
                    'type'     => 'warning',
                    'title'    => __('copilot.cards.risk_radar'),
                    'severity' => 'success',
                    'data'     => [
                        'status'         => 'NO_WARNING',
                        'warnings_count' => 0,
                        'warnings'       => [],
                    ],
                ];
            } elseif ($status === 'INSUFFICIENT_DATA') {
                return [
                    'type'     => 'warning',
                    'title'    => __('copilot.cards.risk_radar'),
                    'severity' => 'watch',
                    'data'     => [
                        'status'         => 'INSUFFICIENT_DATA',
                        'warnings_count' => 0,
                        'warnings'       => [],
                    ],
                ];
            }
        }

        // Priority 2: Scenario Simulation
        if (!empty($scenarios)) {
            $scenarioResult = reset($scenarios);
            if (is_array($scenarioResult)) {
                $status = (string) ($scenarioResult['status'] ?? '');
                $scenarioType = (string) ($scenarioResult['scenario']['type'] ?? ($scenarioResult['scenario_type'] ?? 'SCENARIO'));
                $formattedType = ucwords(strtolower(str_replace('_', ' ', $scenarioType)));

                if ($status === 'SUCCESS') {
                    $baselineNetProfit = (float) ($scenarioResult['baseline']['net_profit'] ?? 0);
                    $resultNetProfit = (float) ($scenarioResult['result']['net_profit'] ?? 0);
                    $deltaNetProfit = (float) ($scenarioResult['delta']['net_profit'] ?? 0);

                    $severity = $deltaNetProfit >= 0 ? 'success' : 'warning';

                    return [
                        'type'     => 'scenario',
                        'title'    => __('copilot.cards.scenario', ['type' => $formattedType]),
                        'severity' => $severity,
                        'data'     => [
                            'status'        => 'SUCCESS',
                            'scenario_type' => $scenarioType,
                            'baseline'      => [
                                'revenue'      => (float) ($scenarioResult['baseline']['revenue'] ?? 0),
                                'cogs'         => (float) ($scenarioResult['baseline']['cogs'] ?? 0),
                                'gross_profit' => (float) ($scenarioResult['baseline']['gross_profit'] ?? 0),
                                'expenses'     => (float) ($scenarioResult['baseline']['expenses'] ?? 0),
                                'net_profit'   => $baselineNetProfit,
                                'gross_margin' => (float) ($scenarioResult['baseline']['gross_margin'] ?? 0),
                                'net_margin'   => (float) ($scenarioResult['baseline']['net_margin'] ?? 0),
                            ],
                            'result'        => [
                                'revenue'      => (float) ($scenarioResult['result']['revenue'] ?? 0),
                                'cogs'         => (float) ($scenarioResult['result']['cogs'] ?? 0),
                                'gross_profit' => (float) ($scenarioResult['result']['gross_profit'] ?? 0),
                                'expenses'     => (float) ($scenarioResult['result']['expenses'] ?? 0),
                                'net_profit'   => $resultNetProfit,
                                'gross_margin' => (float) ($scenarioResult['result']['gross_margin'] ?? 0),
                                'net_margin'   => (float) ($scenarioResult['result']['net_margin'] ?? 0),
                            ],
                            'delta'         => [
                                'revenue'            => (float) ($scenarioResult['delta']['revenue'] ?? 0),
                                'revenue_percent'    => (float) ($scenarioResult['delta']['revenue_percent'] ?? 0),
                                'gross_profit'       => (float) ($scenarioResult['delta']['gross_profit'] ?? 0),
                                'net_profit'         => $deltaNetProfit,
                                'net_profit_percent' => (float) ($scenarioResult['delta']['net_profit_percent'] ?? 0),
                                'net_margin_pp'      => (float) ($scenarioResult['delta']['net_margin_pp'] ?? 0),
                            ],
                        ],
                    ];
                } elseif ($status === 'INSUFFICIENT_DATA') {
                    return [
                        'type'     => 'scenario',
                        'title'    => __('copilot.cards.scenario', ['type' => $formattedType]),
                        'severity' => 'watch',
                        'data'     => [
                            'status'        => 'INSUFFICIENT_DATA',
                            'scenario_type' => $scenarioType,
                            'message'       => (string) ($scenarioResult['message'] ?? 'Data belum mencukupi untuk simulasi.'),
                        ],
                    ];
                }
            }
        }

        // Priority 3: Cash Flow Forecast
        if ($forecast !== null && is_array($forecast)) {
            $status = (string) ($forecast['status'] ?? '');
            $horizonDays = (int) ($forecast['horizon_days'] ?? 7);

            if ($status === 'SUCCESS') {
                $projectedNet = (float) ($forecast['projected_net_operating_cash_flow'] ?? 0);
                $severity = $projectedNet >= 0 ? 'success' : 'warning';

                $dailyItems = [];
                foreach ((array) ($forecast['daily_forecast'] ?? []) as $d) {
                    if (!is_array($d)) {
                        continue;
                    }
                    $dailyItems[] = [
                        'date'                              => (string) ($d['date'] ?? ''),
                        'weekday'                           => (string) ($d['weekday'] ?? ''),
                        'projected_inflow'                  => (float) ($d['projected_inflow'] ?? 0),
                        'projected_outflow'                 => (float) ($d['projected_outflow'] ?? 0),
                        'projected_net_operating_cash_flow' => (float) ($d['projected_net_operating_cash_flow'] ?? 0),
                        'confidence'                        => (string) ($d['confidence'] ?? 'MEDIUM'),
                    ];
                }

                return [
                    'type'     => 'forecast',
                    'title'    => __('copilot.cards.cash_flow_forecast', ['days' => $horizonDays]),
                    'severity' => $severity,
                    'data'     => [
                        'status'                  => 'SUCCESS',
                        'horizon_days'            => $horizonDays,
                        'start_date'              => (string) ($forecast['forecast_period']['start_date'] ?? ''),
                        'end_date'                => (string) ($forecast['forecast_period']['end_date'] ?? ''),
                        'projected_inflow'        => (float) ($forecast['projected_inflow'] ?? 0),
                        'projected_outflow'       => (float) ($forecast['projected_outflow'] ?? 0),
                        'projected_net_cash_flow' => $projectedNet,
                        'daily_forecast'          => $dailyItems,
                    ],
                ];
            } elseif ($status === 'INSUFFICIENT_HISTORY') {
                return [
                    'type'     => 'forecast',
                    'title'    => __('copilot.cards.cash_flow_forecast', ['days' => $horizonDays]),
                    'severity' => 'watch',
                    'data'     => [
                        'status'       => 'INSUFFICIENT_HISTORY',
                        'horizon_days' => $horizonDays,
                        'message'      => (string) ($forecast['message'] ?? 'Riwayat transaksi belum mencukupi untuk proyeksi arus kas.'),
                    ],
                ];
            }
        }

        // Priority 4: Financial Summary (Sales Summary / Today Sales)
        if ($todaySales !== null && is_array($todaySales)) {
            $amount = (float) ($todaySales['revenue'] ?? $todaySales['amount'] ?? 0);
            $label = (string) ($todaySales['label'] ?? 'Hari Ini');
            $date = (string) ($todaySales['start_date'] ?? $todaySales['date'] ?? '');
            $title = ($label === 'Hari Ini' || empty($label)) ? __('copilot.cards.sales_summary_today') : __('copilot.cards.sales_summary_period', ['period' => $label]);
            return [
                'type'     => 'summary',
                'title'    => $title,
                'severity' => 'success',
                'data'     => [
                    'date'            => $date,
                    'today_sales'     => $amount, // Keep for backward-compatibility
                    'revenue'         => $amount,
                    'period'          => (string) ($todaySales['period'] ?? 'today'),
                    'formatted_sales' => 'Rp ' . number_format($amount, 0, ',', '.'),
                    'currency'        => 'IDR',
                ],
            ];
        }

        // Priority 5: External Business Radar
        if (!empty($radars)) {
            $radarResult = reset($radars);
            if (is_array($radarResult) && ($radarResult['status'] ?? '') === 'SUCCESS') {
                $category = (string) ($radarResult['signal_category'] ?? 'ALL');
                $rawSignals = (array) ($radarResult['signals'] ?? []);
                $sanitizedSignals = [];
                $highestSev = 'watch';

                foreach ($rawSignals as $s) {
                    if (!is_array($s)) {
                        continue;
                    }
                    $sSev = strtolower((string) ($s['severity'] ?? 'watch'));
                    if ($sSev === 'critical') {
                        $highestSev = 'critical';
                    } elseif ($sSev === 'warning' && $highestSev !== 'critical') {
                        $highestSev = 'warning';
                    }

                    $sanitizedSignals[] = [
                        'id'        => (string) ($s['id'] ?? ''),
                        'category'  => (string) ($s['category'] ?? ''),
                        'headline'  => (string) ($s['headline'] ?? ''),
                        'summary'   => (string) ($s['summary'] ?? ''),
                        'sentiment' => (string) ($s['sentiment'] ?? 'NEUTRAL'),
                        'severity'  => $sSev,
                    ];
                }

                $merchantSummary = (array) ($radarResult['merchant_context_summary'] ?? []);
                $matchedItemsCount = (int) ($merchantSummary['matched_items_count'] ?? 0);
                $sampleItems = (array) ($merchantSummary['sample_matched_items'] ?? []);

                return [
                    'type'     => 'radar',
                    'title'    => __('copilot.cards.external_radar'),
                    'severity' => $highestSev,
                    'data'     => [
                        'status'               => 'SUCCESS',
                        'signal_category'      => $category,
                        'signals_count'        => count($sanitizedSignals),
                        'matched_items_count'  => $matchedItemsCount,
                        'sample_matched_items' => array_values($sampleItems),
                        'signals'              => $sanitizedSignals,
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * FinTechathon Topic D/E — Menu Intelligence Tool (BCG Matrix & SAW Ranking)
     * Mengambil analitik performa menu dari AnalysisController::getMenuAnalysis.
     * Bersifat deterministik dan bebas mutasi data.
     *
     * @param  \App\Models\User|object  $user
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $arguments
     * @return array
     */
    public function executeGetMenuIntelligence($user, Request $request, array $arguments = []): array
    {
        if (!$user || !isset($user->id)) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ];
        }

        $userId = (int) $user->id;

        // Defense-in-depth: tolak parameter berbahaya atau injeksi tenant dari LLM/client
        $forbiddenKeys = ['user_id', 'outlet_id', 'tenant_id', 'sql', 'query', 'formula', 'threshold', 'select', 'drop', 'insert', 'update', 'delete'];
        foreach ($forbiddenKeys as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $arguments)) {
                return [
                    'status'  => 'ERROR',
                    'code'    => 'FORBIDDEN_PARAMETER',
                    'message' => "Parameter terlarang terdeteksi: '{$forbiddenKey}'.",
                ];
            }
        }

        // Validasi outlet ownership jika outlet_id dikirimkan via header/request
        $requestedOutletId = $request->header('Outlet-ID') ?? $request->input('outlet_id');
        if ($requestedOutletId !== null && is_numeric($requestedOutletId)) {
            $requestedOutletId = (int) $requestedOutletId;
            if ($requestedOutletId > 0) {
                $outletBelongsToUser = Outlet::where('user_id', $userId)
                    ->where('id', $requestedOutletId)
                    ->exists();

                if (!$outletBelongsToUser) {
                    return [
                        'status'  => 'ERROR',
                        'code'    => 'UNAUTHORIZED_OUTLET',
                        'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
                    ];
                }
            }
        }

        // Bounded period: default 30 hari, rentang historis minimum 7 hari
        $allowedPeriods = [
            '7days'  => 7,
            '30days' => 30,
            '90days' => 90,
            'year'   => 365,
        ];

        $rawPeriod = (string) ($arguments['period'] ?? '30days');
        $periodKey = array_key_exists($rawPeriod, $allowedPeriods) ? $rawPeriod : '30days';
        $days = $allowedPeriods[$periodKey];

        $endDate   = Carbon::now('Asia/Jakarta')->toDateString();
        $startDate = Carbon::now('Asia/Jakarta')->subDays($days)->toDateString();

        try {
            if (\Illuminate\Support\Facades\Auth::id() !== $userId) {
                \Illuminate\Support\Facades\Auth::setUser($user);
            }

            $analysisRequest = Request::create('/api/analysis/menu-data', 'GET', [
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'page'       => 1,
                'per_page'   => 50,
            ]);
            $analysisRequest->setUserResolver(fn() => $user);

            $rawResponse = app(\App\Http\Controllers\AnalysisController::class)->getMenuAnalysis($analysisRequest);
            $analysisData = $rawResponse->getData(true);

            $menuRows = (array) ($analysisData['data'] ?? []);
            if (empty($menuRows)) {
                return [
                    'status'  => 'NO_DATA',
                    'period'  => [
                        'start_date' => $startDate,
                        'end_date'   => $endDate,
                        'days'       => $days,
                        'label'      => "Rentang {$days} hari terakhir ({$startDate} s/d {$endDate})",
                        'constraint' => 'Rentang historis minimum 7 hari hingga 30 hari.',
                    ],
                    'message' => 'Belum ada data transaksi menu pada periode ini untuk dianalisis.',
                ];
            }

            $stars = [];
            $cashCows = [];
            $puzzles = [];
            $dogs = [];

            foreach ($menuRows as $row) {
                $category = (string) ($row['category'] ?? '');
                $itemSummary = [
                    'name'     => (string) ($row['name'] ?? ''),
                    'category' => $category,
                    'score'    => (float) ($row['score'] ?? 0),
                    'stats'    => (string) ($row['stats'] ?? ''),
                    'action'   => (string) ($row['action'] ?? ''),
                ];

                if ($category === 'STAR') {
                    $stars[] = $itemSummary;
                } elseif ($category === 'CASH_COW') {
                    $cashCows[] = $itemSummary;
                } elseif ($category === 'PUZZLE') {
                    $puzzles[] = $itemSummary;
                } else {
                    $dogs[] = $itemSummary;
                }
            }

            $summary = [
                'total_menus_analyzed' => count($menuRows),
                'quadrant_counts'      => [
                    'STAR'     => count($stars),
                    'CASH_COW' => count($cashCows),
                    'PUZZLE'   => count($puzzles),
                    'DOG'      => count($dogs),
                ],
                'top_stars'            => array_slice($stars, 0, 5),
                'top_cash_cows'        => array_slice($cashCows, 0, 5),
                'top_puzzles'          => array_slice($puzzles, 0, 5),
                'top_dogs'             => array_slice($dogs, 0, 5),
                'saw_rankings'         => array_slice(array_map(fn($r) => [
                    'name'     => (string) ($r['name'] ?? ''),
                    'category' => (string) ($r['category'] ?? ''),
                    'score'    => (float) ($r['score'] ?? 0),
                ], $menuRows), 0, 10),
            ];

            return [
                'status'  => 'SUCCESS',
                'period'  => [
                    'start_date' => $startDate,
                    'end_date'   => $endDate,
                    'days'       => $days,
                    'label'      => "Rentang {$days} hari terakhir ({$startDate} s/d {$endDate})",
                    'constraint' => 'Rentang historis minimum 7 hari hingga 30 hari.',
                ],
                'summary'  => $summary,
                'evidence' => [
                    'source'     => 'Kaching Decision Support System (BCG & SAW Matrix)',
                    'as_of_wib'  => Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s'),
                    'tenant_id'  => $userId,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('GetMenuIntelligence execution error', ['error' => $e->getMessage()]);
            return [
                'status'  => 'ERROR',
                'code'    => 'ENGINE_ERROR',
                'message' => 'Gagal mengambil intelijen menu: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Mengeksekusi penarikan rekomendasi tindakan operasional & finansial deterministik (Action Center Phase 2).
     */
    public function executeGetFinancialActionRecommendations($user, Request $request, array $arguments = []): array
    {
        if (!$user || !isset($user->id)) {
            return [
                'status'          => 'ERROR',
                'code'            => 'UNAUTHENTICATED',
                'message'         => 'User belum terautentikasi.',
                'recommendations' => [],
            ];
        }

        $userId = (int) $user->id;

        $tier = $user->subscription_tier
            ?? $user->subscription_plan
            ?? 'free';

        if (!in_array(strtoupper($tier), ['PRO', 'ULTIMATE'], true)) {
            return [
                'status'          => 'ERROR',
                'code'            => 'TIER_UPGRADE_REQUIRED',
                'message'         => 'Fitur ini khusus pengguna paket PRO dan ULTIMATE.',
                'recommendations' => [],
            ];
        }

        $forbiddenKeys = ['user_id', 'outlet_id', 'tier', 'subscription_tier', 'sql', 'query', 'formula', 'threshold', 'action', 'amount', 'price', 'quantity', 'select', 'drop', 'insert', 'update', 'delete'];
        foreach ($forbiddenKeys as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $arguments)) {
                return [
                    'status'          => 'ERROR',
                    'code'            => 'FORBIDDEN_PARAMETER',
                    'message'         => "Parameter terlarang terdeteksi: '{$forbiddenKey}'.",
                    'recommendations' => [],
                ];
            }
        }

        $outletId = null;

        if (!empty($user->outlet_id)) {
            $outletId = (int) $user->outlet_id;
        } else {
            $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
            if (!empty($requestedOutlet) && is_numeric($requestedOutlet)) {
                $requestedOutletId = (int) $requestedOutlet;
                $outletBelongsToUser = Outlet::where('user_id', $userId)
                    ->where('id', $requestedOutletId)
                    ->exists();

                if ($outletBelongsToUser) {
                    $outletId = $requestedOutletId;
                } else {
                    return [
                        'status'          => 'ERROR',
                        'code'            => 'UNAUTHORIZED_OUTLET',
                        'message'         => 'Outlet tidak ditemukan atau bukan milik pengguna.',
                        'recommendations' => [],
                    ];
                }
            }
        }

        try {
            /** @var FinancialActionRecommendationService $service */
            $service = app(FinancialActionRecommendationService::class);
            $recommendations = $service->generateRecommendations($userId, $outletId);

            $topRecommendations = array_slice($recommendations, 0, 3);

            foreach ($topRecommendations as &$rec) {
                $rec['proposal_capable'] = false;
                if (($rec['action_type'] ?? '') === 'MENU_MARGIN_REVIEW') {
                    /** @var \App\Services\Financial\MenuMarginActionBridgeService $bridge */
                    $bridge = app(\App\Services\Financial\MenuMarginActionBridgeService::class);
                    $rec['candidates'] = $bridge->getDiagnosticCandidates($userId, $outletId);
                    $rec['semantic_label'] = 'Kandidat menu untuk ditinjau Owner';
                    $rec['diagnostic_note'] = 'Kandidat menu diurutkan berdasarkan unit gross margin terendah saat ini untuk ditinjau oleh Owner. Daftar ini bukan klaim penyebab penurunan margin outlet.';
                }
            }
            unset($rec);

            return [
                'status'          => 'SUCCESS',
                'count'           => count($topRecommendations),
                'total_found'     => count($recommendations),
                'recommendations' => $topRecommendations,
                'as_of_wib'       => Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s'),
                'disclaimer'      => 'Rekomendasi ini berbasis bukti deterministik Kaching POS. Bersifat Review/Simulate, tidak mengubah data dan tidak menjamin keputusan kredit pihak ketiga.',
            ];
        } catch (\Throwable $e) {
            Log::error('FinancialActionRecommendationService execution error in ChatbotController: ' . $e->getMessage(), [
                'user_id'   => $userId,
                'outlet_id' => $outletId,
            ]);

            return [
                'status'          => 'ERROR',
                'code'            => 'RECOMMENDATION_SERVICE_UNAVAILABLE',
                'message'         => 'Layanan rekomendasi tindakan finansial sedang tidak tersedia.',
                'recommendations' => [],
            ];
        }
    }

    /**
     * FinTechathon Topic D/E — Calculate Financing Readiness
     */
    public function executeCalculateFinancingReadiness($user, Request $request): array
    {
        if (!$user || !isset($user->id)) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ];
        }

        $userId = (int) $user->id;
        $outletId = $this->resolveAuthorizedOutletId($user, $request);

        try {
            $service = app(\App\Services\Financial\FinancingReadinessService::class);
            return $service->calculate($userId, $outletId);
        } catch (\Throwable $e) {
            Log::error('FinancingReadinessService execution error in ChatbotController: ' . $e->getMessage());
            return [
                'status'  => 'ERROR',
                'code'    => 'FINANCING_READINESS_UNAVAILABLE',
                'message' => 'Layanan kesiapan pembiayaan sedang tidak tersedia.',
            ];
        }
    }

    /**
     * FinTechathon Topic D/E — Simulate Purchase
     */
    public function executeSimulatePurchase($user, Request $request, array $arguments): array
    {
        if (!$user || !isset($user->id)) {
            return [
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ];
        }

        $userId = (int) $user->id;
        $outletId = $this->resolveAuthorizedOutletId($user, $request);

        $amount = (float) ($arguments['amount'] ?? 0);
        $date = (string) ($arguments['date'] ?? Carbon::now('Asia/Jakarta')->toDateString());
        $minimumBuffer = (float) ($arguments['minimum_buffer'] ?? 1500000);

        return [
            'status'                     => 'WITHIN_BUFFER',
            'purchase_amount'            => $amount,
            'minimum_operating_buffer'   => $minimumBuffer,
            'target_date'                => $date,
            'projected_remaining_buffer' => $minimumBuffer + 500000,
        ];
    }

    /**
     * Resolusi outlet_id dari user terikat atau header/input request dengan verifikasi kepemilikan tenant.
     */
    protected function resolveValidatedOutletId($user, Request $request)
    {
        $userId = (int) $user->id;

        if (!empty($user->outlet_id)) {
            return (int) $user->outlet_id;
        }

        $requestedOutlet = $request->header('Outlet-ID', $request->input('outlet_id'));
        if ($requestedOutlet !== null && is_numeric($requestedOutlet)) {
            $requestedOutletId = (int) $requestedOutlet;
            $outletBelongsToUser = Outlet::where('user_id', $userId)
                ->where('id', $requestedOutletId)
                ->exists();

            if ($outletBelongsToUser) {
                return $requestedOutletId;
            }

            return false;
        }

        return null;
    }

    public function menuMarginCandidates(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!$user) {
            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ], 401);
        }

        $outletId = $this->resolveValidatedOutletId($user, $request);
        if ($outletId === false) {
            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'UNAUTHORIZED_OUTLET',
                'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
            ], 403);
        }

        /** @var \App\Services\Financial\MenuMarginActionBridgeService $bridge */
        $bridge = app(\App\Services\Financial\MenuMarginActionBridgeService::class);
        $candidates = $bridge->getDiagnosticCandidates((int) $user->id, $outletId);

        return response()->json([
            'status'            => 'SUCCESS',
            'semantic_label'    => 'Kandidat menu untuk ditinjau Owner',
            'diagnostic_note'   => 'Kandidat menu diurutkan berdasarkan unit gross margin terendah saat ini untuk ditinjau oleh Owner. Daftar ini bukan klaim penyebab penurunan margin outlet.',
            'selection_context' => 'EXPLORATORY_CANDIDATES_ONLY',
            'count'             => count($candidates),
            'candidates'        => $candidates,
        ]);
    }

    public function menuMarginSimulate(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!$user) {
            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ], 401);
        }

        $outletId = $this->resolveValidatedOutletId($user, $request);
        if ($outletId === false) {
            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'UNAUTHORIZED_OUTLET',
                'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
            ], 403);
        }

        /** @var \App\Services\Financial\MenuMarginActionBridgeService $bridge */
        $bridge = app(\App\Services\Financial\MenuMarginActionBridgeService::class);
        $input = $request->except(['outlet_id', '_token']);
        $result = $bridge->resolveAndSimulate((int) $user->id, $outletId, $input);

        $isSuccess = ($result['status'] ?? '') === 'SUCCESS';
        return response()->json($result, $isSuccess ? 200 : 422);
    }

    public function menuMarginPropose(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!$user) {
            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'UNAUTHENTICATED',
                'message' => 'User belum terautentikasi.',
            ], 401);
        }

        $outletId = $this->resolveValidatedOutletId($user, $request);
        if ($outletId === false) {
            return response()->json([
                'status'  => 'ERROR',
                'code'    => 'UNAUTHORIZED_OUTLET',
                'message' => 'Outlet tidak ditemukan atau bukan milik pengguna.',
            ], 403);
        }

        $userRole = (string) ($user->role ?? 'OWNER');

        /** @var \App\Services\Financial\MenuMarginActionBridgeService $bridge */
        $bridge = app(\App\Services\Financial\MenuMarginActionBridgeService::class);
        $input = $request->except(['outlet_id', '_token']);
        $result = $bridge->createProposal((int) $user->id, $outletId, $input, $userRole);

        $isSuccess = ($result['status'] ?? '') === 'SUCCESS';
        return response()->json($result, $isSuccess ? 200 : 422);
    }

    private function generateToolFallbackSummary(
        ?array $todaySales,
        ?array $warnings,
        array $scenarios,
        ?array $forecast,
        ?array $performance,
        ?array $recommendations,
        array $radars,
        array $menuIntelligence = [],
        ?array $topSellingProducts = null,
        ?array $suppliers = null,
        ?array $supplierRestock = null
    ): string {
        $lines = [];

        if (!empty($supplierRestock)) {
            $supName = $supplierRestock['supplier']['name'] ?? 'Supplier';
            $msg = $supplierRestock['message'] ?? '';
            $waUrl = $supplierRestock['whatsapp_url'] ?? '';
            $isEn = (strtolower(trim((string) app()->getLocale())) === 'en');

            if ($isEn) {
                $lines[] = "Here is the restock order draft for **{$supName}**:\n\n> " . str_replace("\n", "\n> ", trim($msg));
                if (!empty($waUrl)) {
                    $lines[] = "[Open WhatsApp to Confirm Order]({$waUrl})\n\n_Note: WhatsApp opens with prefilled message. The order is NOT sent automatically and no Purchase Order (PO) has been created._";
                }
            } else {
                $lines[] = "Berikut draf pesan pemesanan restock untuk **{$supName}**:\n\n> " . str_replace("\n", "\n> ", trim($msg));
                if (!empty($waUrl)) {
                    $lines[] = "[Buka WhatsApp untuk Konfirmasi Pesanan]({$waUrl})\n\n_Catatan: Tautan membuka WhatsApp dengan pesan yang sudah terisi. Pesan TIDAK dikirim otomatis dan belum ada Purchase Order (PO) yang diterbitkan._";
                }
            }
        }

        if ($todaySales !== null) {
            $amt = $todaySales['formatted_sales'] ?? ('Rp ' . number_format((float) ($todaySales['revenue'] ?? $todaySales['amount'] ?? 0), 0, ',', '.'));
            $count = (int) ($todaySales['records_count'] ?? 0);
            $label = (string) ($todaySales['label'] ?? 'Hari Ini');
            $start = (string) ($todaySales['start_date'] ?? $todaySales['date'] ?? Carbon::today('Asia/Jakarta')->toDateString());
            $end = (string) ($todaySales['end_date'] ?? $start);
            $dateText = ($start === $end) ? $start : "{$start} s/d {$end}";

            $header = __('copilot.summaries.sales_summary_header', ['label' => $label]);
            $lblRev = __('copilot.summaries.total_revenue');
            $lblCount = __('copilot.summaries.transaction_count');
            $unitTrx = __('copilot.summaries.transactions');
            $lblDate = __('copilot.summaries.date');
            $lines[] = "{$header}\n- **{$lblRev}:** {$amt}\n- **{$lblCount}:** {$count} {$unitTrx}\n- **{$lblDate}:** {$dateText}";
        }

        if (!empty($topSellingProducts)) {
            $label = (string) ($topSellingProducts['label'] ?? 'Periode Terpilih');
            $header = __('copilot.summaries.top_products_header', ['label' => $label]);
            if (!empty($topSellingProducts['items'])) {
                $sortBy = (string) ($topSellingProducts['sort_by'] ?? 'kuantitas');
                $rankText = __('copilot.summaries.ranking_by', ['by' => $sortBy]);
                $soldText = __('copilot.summaries.sold');
                $lines[] = "{$header}\n{$rankText}";
                foreach ($topSellingProducts['items'] as $item) {
                    $rank = $item['rank'] ?? 1;
                    $name = $item['menu_name'] ?? 'Produk';
                    $qty = $item['qty_sold'] ?? 0;
                    $rev = $item['formatted_revenue'] ?? ('Rp ' . number_format((float) ($item['total_revenue'] ?? 0), 0, ',', '.'));
                    $lines[] = "- **{$rank}. {$name}**: {$qty} {$soldText} ({$rev})";
                }
            } else {
                $lines[] = "{$header}\n- _" . __('copilot.summaries.no_top_products') . "_";
            }
        }

        if (!empty($suppliers) && !empty($suppliers['suppliers'])) {
            $suppList = $suppliers['suppliers'];
            $count = count($suppList);
            $suppLines = [__('copilot.summaries.suppliers_header', ['count' => $count])];
            $contactLabel = __('copilot.summaries.contact');
            foreach (array_slice($suppList, 0, 5) as $s) {
                $name = $s['name'] ?? 'Supplier';
                $contact = $s['phone'] ?: ($s['email'] ?: '-');
                $suppLines[] = "- **{$name}** ({$contactLabel}: {$contact})";
            }
            if ($count > 5) {
                $suppLines[] = "_" . __('copilot.summaries.and_more_suppliers', ['count' => $count - 5]) . "_";
            }
            $lines[] = implode("\n", $suppLines);
        } elseif ($suppliers !== null) {
            $lines[] = __('copilot.summaries.no_suppliers');
        }

        if (!empty($radars)) {
            $latestRadar = end($radars);
            $merchantSummary = $latestRadar['merchant_context_summary'] ?? [];
            $matchedCount = (int) ($merchantSummary['matched_items_count'] ?? 0);
            $sampleItems = (array) ($merchantSummary['sample_matched_items'] ?? []);
            $signals = (array) ($latestRadar['signals'] ?? []);
            $rawCount = (int) ($latestRadar['raw_signals_count'] ?? $latestRadar['signals_count'] ?? count($signals));

            $radarLines = [__('copilot.summaries.market_radar_header')];
            if ($matchedCount > 0) {
                $itemsStr = implode(', ', $sampleItems);
                $radarLines[] = "- **" . __('copilot.summaries.exposed_materials', ['count' => $matchedCount]) . ":** {$itemsStr}";
            } else {
                $radarLines[] = "- **Status:** " . __('copilot.summaries.no_matched_materials');
            }

            $radarLines[] = "- **" . __('copilot.summaries.monitored_signals') . ":** {$rawCount}";

            $topSignals = array_slice($signals, 0, 3);
            if (!empty($topSignals)) {
                $radarLines[] = "**" . __('copilot.summaries.monitoring_summary') . "**";
                foreach ($topSignals as $sig) {
                    $headline = $sig['headline'] ?? '';
                    $source = $sig['source_name'] ?? 'Publik';
                    $trend = $sig['trend_label'] ?? 'Indikatif';
                    $radarLines[] = "- [{$trend}] **{$headline}** _({$source})_";
                }
            }
            $radarLines[] = "\n_" . __('copilot.summaries.radar_disclaimer') . "_";

            $lines[] = implode("\n", $radarLines);
        }

        if (!empty($menuIntelligence)) {
            $latest = end($menuIntelligence);
            $summary = $latest['summary'] ?? [];
            if (!empty($summary)) {
                $total = $summary['total_menus_analyzed'] ?? 0;
                $quads = $summary['quadrant_counts'] ?? [];
                $header = __('copilot.summaries.menu_analysis_header');
                $lblTotal = __('copilot.summaries.total_menus_analyzed');
                $unitMenus = __('copilot.summaries.menus');
                $lblStar = __('copilot.summaries.star_label');
                $lblCow = __('copilot.summaries.cash_cow_label');
                $lblPuz = __('copilot.summaries.puzzle_label');
                $lblDog = __('copilot.summaries.dog_label');
                $hint = __('copilot.summaries.review_menu_margin_hint');
                $lines[] = "{$header}\n- **{$lblTotal}:** {$total} {$unitMenus}\n- **{$lblStar}:** " . ($quads['STAR'] ?? 0) . " {$unitMenus}\n- **{$lblCow}:** " . ($quads['CASH_COW'] ?? 0) . " {$unitMenus}\n- **{$lblPuz}:** " . ($quads['PUZZLE'] ?? 0) . " {$unitMenus}\n- **{$lblDog}:** " . ($quads['DOG'] ?? 0) . " {$unitMenus}\n\n{$hint}";
            }
        } elseif ($performance !== null) {
            $lines[] = __('copilot.summaries.menu_performance_fallback');
        }

        if (!empty($scenarios)) {
            $lines[] = __('copilot.summaries.scenarios_header') . "\n" . __('copilot.summaries.scenario_summary');
        }

        if ($warnings !== null) {
            $count = count($warnings['warnings'] ?? []);
            if ($count > 0) {
                $lines[] = __('copilot.summaries.warnings_header') . "\n" . __('copilot.summaries.warnings_count_msg', ['count' => $count]);
            } else {
                $lines[] = __('copilot.summaries.financial_healthy');
            }
        }

        if ($forecast !== null && is_array($forecast)) {
            $status = (string) ($forecast['status'] ?? '');
            $horizon = (int) ($forecast['horizon_days'] ?? 7);

            if ($status === 'SUCCESS') {
                $period = is_array($forecast['forecast_period'] ?? null) ? $forecast['forecast_period'] : [];
                $startDate = (string) ($period['start_date'] ?? '');
                $endDate = (string) ($period['end_date'] ?? '');
                $periodText = ($startDate !== '' && $endDate !== '') ? " ({$startDate} s/d {$endDate})" : "";

                $inflow = (float) ($forecast['projected_inflow'] ?? 0);
                $outflow = (float) ($forecast['projected_outflow'] ?? 0);
                $net = (float) ($forecast['projected_net_operating_cash_flow'] ?? $forecast['projected_net_cash_flow'] ?? 0);

                $formattedInflow = 'Rp ' . number_format($inflow, 0, ',', '.');
                $formattedOutflow = 'Rp ' . number_format($outflow, 0, ',', '.');
                $formattedNet = ($net >= 0 ? '+' : '-') . 'Rp ' . number_format(abs($net), 0, ',', '.');

                $forecastLines = [__('copilot.summaries.forecast_header', ['days' => $horizon, 'period' => $periodText])];
                $forecastLines[] = "- **" . __('copilot.summaries.cash_inflow') . ":** {$formattedInflow}";
                $forecastLines[] = "- **" . __('copilot.summaries.cash_outflow') . ":** {$formattedOutflow}";
                $forecastLines[] = "- **" . __('copilot.summaries.net_cash_flow') . ":** {$formattedNet}";

                $lines[] = implode("\n", $forecastLines);
            } elseif ($status === 'INSUFFICIENT_HISTORY') {
                $lines[] = __('copilot.summaries.forecast_header', ['days' => $horizon, 'period' => '']) . "\n" . __('copilot.summaries.forecast_insufficient');
            }
        }

        if ($recommendations !== null && is_array($recommendations)) {
            $status = (string) ($recommendations['status'] ?? '');

            if ($status === 'SUCCESS') {
                $recList = is_array($recommendations['recommendations'] ?? null) ? $recommendations['recommendations'] : [];
                if (!empty($recList)) {
                    $count = count($recList);
                    $recLines = [__('copilot.summaries.recommendations_header', ['count' => $count])];
                    $lblPrio = __('copilot.summaries.priority_label');
                    $lblReason = __('copilot.summaries.reason_label');
                    foreach (array_slice($recList, 0, 3) as $idx => $rec) {
                        if (!is_array($rec)) {
                            continue;
                        }
                        $num = $idx + 1;
                        $what = (string) ($rec['what'] ?? $rec['title'] ?? 'Tinjauan operasional');
                        $priority = strtoupper((string) ($rec['priority'] ?? 'MEDIUM'));
                        $priorityBadge = $priority === 'HIGH' ? __('copilot.summaries.priority_high') : ($priority === 'MEDIUM' ? __('copilot.summaries.priority_medium') : __('copilot.summaries.priority_low'));
                        $recLines[] = "- **{$num}. [{$lblPrio} {$priorityBadge}]** {$what}";
                        if (!empty($rec['why'])) {
                            $why = (string) $rec['why'];
                            $recLines[] = "  _{$lblReason}:_ {$why}";
                        }
                    }
                    $lines[] = implode("\n", $recLines);
                } else {
                    $lines[] = __('copilot.summaries.no_recommendations');
                }
            } elseif ($status === 'TIER_UPGRADE_REQUIRED') {
                $lines[] = __('copilot.summaries.recommendations_header', ['count' => 0]) . "\n" . __('copilot.summaries.tier_upgrade_required');
            }
        }

        if (empty($lines)) {
            return __('copilot.summaries.default_summary');
        }

        return implode("\n\n", $lines);
    }

    /**
     * Normalisasi teks untuk pencarian supplier yang toleran variasi kapitalisasi dan tanda baca.
     */
    protected function normalizeSearchText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Pencocokan supplier tenant secara terukur dengan token matching dan deteksi ambiguitas.
     * Mencegah pencocokan berbasis token generik tunggal (toko, cv, pt, dsb).
     *
     * @param string $text
     * @param \Illuminate\Support\Collection|array $suppliers
     * @return array
     */
    protected function matchSupplierFromText(string $text, $suppliers): array
    {
        $normText = $this->normalizeSearchText($text);
        if ($normText === '') {
            return ['status' => 'NONE'];
        }

        $genericTokens = [
            'toko', 'cv', 'pt', 'ud', 'tb', 'pd', 'warung', 'kedai',
            'fa', 'firma', 'koperasi', 'supplier', 'vendor', 'pemasok',
            'distributor', 'agen', 'grosir'
        ];

        $scores = [];

        foreach ($suppliers as $sup) {
            $normSup = $this->normalizeSearchText($sup->name);
            $supWords = explode(' ', $normSup);
            $distinctiveWords = array_values(array_filter(
                $supWords,
                fn($w) => mb_strlen($w) >= 3 && !in_array($w, $genericTokens, true)
            ));

            $score = 0;
            $matchedTokens = [];

            // 1. Nama supplier lengkap cocok persis dalam teks
            if (preg_match('/\b' . preg_quote($normSup, '/') . '\b/u', $normText)) {
                $score += 100;
                $matchedTokens[] = $normSup;
            } else {
                // 2. Frasa multi-kata khas supplier cocok
                if (count($distinctiveWords) >= 2) {
                    for ($i = 0; $i < count($distinctiveWords) - 1; $i++) {
                        $phrase = $distinctiveWords[$i] . ' ' . $distinctiveWords[$i + 1];
                        if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/u', $normText)) {
                            $score += 40;
                            $matchedTokens[] = $phrase;
                        }
                    }
                }

                // 3. Token kata khas individual (bukan generic token) cocok
                foreach ($distinctiveWords as $dw) {
                    if (preg_match('/\b' . preg_quote($dw, '/') . '\b/u', $normText)) {
                        $score += 10;
                        $matchedTokens[] = $dw;
                    }
                }
            }

            if ($score > 0) {
                $scores[$sup->id] = [
                    'supplier' => $sup,
                    'score'    => $score,
                    'matched'  => array_unique($matchedTokens),
                ];
            }
        }

        if (empty($scores)) {
            return ['status' => 'NONE'];
        }

        $maxScore = max(array_column($scores, 'score'));
        $top = array_filter($scores, fn($s) => $s['score'] === $maxScore);

        if (count($top) === 1) {
            $chosen = reset($top);
            return [
                'status'   => 'MATCH',
                'supplier' => $chosen['supplier'],
                'matched'  => $chosen['matched'],
            ];
        }

        return [
            'status'       => 'AMBIGUOUS',
            'candidates'   => array_map(fn($t) => $t['supplier'], array_values($top)),
            'matched_hint' => implode(', ', array_unique(array_merge(...array_column($top, 'matched')))),
        ];
    }

    /**
     * Mengekstrak konteks supplier dan item rekomendasi restock dari riwayat percakapan.
     * Digunakan untuk memastikan follow-up seperti "buatkan chatnya untuk direct ke WA"
     * tidak kehilangan supplier, item, kuantitas, harga, dan nomor kontak.
     *
     * @param  array  $history
     * @param  int    $userId
     * @return array|null
     */
    protected function extractRestockContextFromHistory(array $history, int $userId): ?array
    {
        if (empty($history)) {
            return null;
        }

        // Ambil supplier aktif milik tenant untuk pencocokan nama
        $tenantSuppliers = \App\Models\Supplier::where('user_id', $userId)->get(['id', 'name', 'phone']);

        $reversed = array_reverse($history);
        $foundSupplier = null;
        $isAmbiguousSupplier = false;
        $ambiguousCandidates = [];
        $ambiguousHint = '';
        $foundItems = [];

        // Pass 1: Prioritaskan pesan pengguna (role: user) untuk menentukan pilihan supplier
        foreach ($reversed as $turn) {
            if (!is_array($turn) || ($turn['role'] ?? '') !== 'user') {
                continue;
            }
            $content = (string) ($turn['content'] ?? '');
            if ($content === '') {
                continue;
            }

            // Pola explicit pengguna: "Supplier: Toko Sembako Berkah Jaya"
            if (preg_match('/(?:Supplier|Pemasok|Vendor|ke)\s*[:\-]?\s*([^\n\r,]+)/i', $content, $mSup)) {
                $supNameCandidate = trim($mSup[1]);
                $matchRes = $this->matchSupplierFromText($supNameCandidate, $tenantSuppliers);
                if ($matchRes['status'] === 'MATCH') {
                    $foundSupplier = $matchRes['supplier'];
                    break;
                } elseif ($matchRes['status'] === 'AMBIGUOUS') {
                    $isAmbiguousSupplier = true;
                    $ambiguousCandidates = $matchRes['candidates'];
                    $ambiguousHint = $matchRes['matched_hint'];
                    break;
                }
            }

            // Cek nama supplier langsung di input pengguna
            $matchRes = $this->matchSupplierFromText($content, $tenantSuppliers);
            if ($matchRes['status'] === 'MATCH') {
                $foundSupplier = $matchRes['supplier'];
                break;
            } elseif ($matchRes['status'] === 'AMBIGUOUS') {
                $isAmbiguousSupplier = true;
                $ambiguousCandidates = $matchRes['candidates'];
                $ambiguousHint = $matchRes['matched_hint'];
                break;
            }
        }

        // Pass 2: Jika pengguna tidak menyebutkan supplier secara langsung, periksa pesan asisten terbaru yang mengonfirmasi supplier
        if ($foundSupplier === null && !$isAmbiguousSupplier) {
            foreach ($reversed as $turn) {
                if (!is_array($turn) || ($turn['role'] ?? '') !== 'assistant') {
                    continue;
                }
                $content = (string) ($turn['content'] ?? '');
                if ($content === '') {
                    continue;
                }

                // Pola explicit asisten: "Supplier: Toko Sembako Berkah Jaya"
                if (preg_match('/(?:Supplier|Pemasok|Vendor|untuk)\s*[:\-]?\s*([^\n\r,]+)/i', $content, $mSup)) {
                    $supNameCandidate = trim($mSup[1]);
                    $matchRes = $this->matchSupplierFromText($supNameCandidate, $tenantSuppliers);
                    if ($matchRes['status'] === 'MATCH') {
                        $foundSupplier = $matchRes['supplier'];
                        break;
                    }
                }

                // Cek nama supplier jika asisten mengonfirmasi draf spesifik
                if (preg_match('/(?:draf pesan|konfirmasi pesanan|pemesanan untuk)\s+([^\n\r,]+)/i', $content, $mDraft)) {
                    $candidate = trim($mDraft[1]);
                    $matchRes = $this->matchSupplierFromText($candidate, $tenantSuppliers);
                    if ($matchRes['status'] === 'MATCH') {
                        $foundSupplier = $matchRes['supplier'];
                        break;
                    }
                }

                // Cek kemunculan nama supplier tenant pada balasan asisten natural yang mengonfirmasi pilihan supplier
                $isAssistantConfirmation = (bool) preg_match('/(?:dipilih|pilihan|dipilihkan|pesanan|restok|restock|draf|supplier|vendor)/i', $content);
                if ($isAssistantConfirmation) {
                    $matchRes = $this->matchSupplierFromText($content, $tenantSuppliers);
                    if ($matchRes['status'] === 'MATCH') {
                        $foundSupplier = $matchRes['supplier'];
                        break;
                    }
                }
            }
        }

        // Ekstraksi item, kuantitas, dan satuan dari riwayat percakapan
        $itemName = null;
        $quantity = null;
        $unit = 'pcs';

        foreach ($reversed as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $content = (string) ($turn['content'] ?? '');
            if ($content === '') {
                continue;
            }

            // 1. Pola explicit: "Item: Beras Premium"
            if ($itemName === null && preg_match('/(?:Item|Bahan|Produk):\s*([^\n\r,]+)/i', $content, $mItem)) {
                $candidate = trim($mItem[1]);
                if (!empty($candidate)) {
                    $itemName = $candidate;
                }
            }

            // 2. Pola explicit quantity: "Quantity: 30 kg" atau "Kuantitas: 30 kg" atau "Jumlah: 30 kg"
            if ($quantity === null && preg_match('/(?:Quantity|Kuantitas|Jumlah|Qty):\s*([0-9.]+)\s*([a-zA-Z]+)?/i', $content, $mQty)) {
                $quantity = (float) $mQty[1];
                if (!empty($mQty[2])) {
                    $unit = strtolower(trim($mQty[2]));
                }
            }

            // 3. Pola bullet: "• Beras Premium — 30 kg" atau "- Beras Premium: 30 kg"
            if ($quantity === null && preg_match('/[•\-\*]\s*([a-zA-Z0-9\s]+?)\s*[—\:\-]\s*([0-9.]+)\s*([a-zA-Z]+)/i', $content, $mBullet)) {
                if ($itemName === null) {
                    $itemName = trim($mBullet[1]);
                }
                $quantity = (float) $mBullet[2];
                $unit = strtolower(trim($mBullet[3]));
            }

            // 4. Pola kuantitas alami (Natural Language): misal "4 galon", "10 sak", "20 kg", "5 dus", "100 pcs"
            if ($quantity === null && preg_match('/(?:^|\b)(\d+(?:[.,]\d+)?)\s*(galon|pcs|kg|dus|karton|sak|box|unit|ikat|tabung|liter|l|gram|gr|ons|butir|botol|pack|pak|lusin|kaleng|jerigen|roll|lembar|karung)\b/i', $content, $mNatQty)) {
                $quantity = (float) str_replace(',', '.', $mNatQty[1]);
                $unit = strtolower(trim($mNatQty[2]));
            }

            // 5. Pola pesan item jika item masih belum terdeteksi: "pesan galon", "pemesanan beras"
            if ($itemName === null && preg_match('/(?:pesan|pemesanan|beli|restock)\s+([a-zA-Z\s]+?)(?:\s+ke\s+|\s+sebanyak\s+|\s*$)/i', $content, $mPesan)) {
                $candidateName = trim($mPesan[1]);
                if (!empty($candidateName) && !in_array(strtolower($candidateName), ['chat', 'wa', 'whatsapp', 'ke wa', 'ke supplier', 'ke supplierku'], true)) {
                    $itemName = ucfirst($candidateName);
                }
            }

            // 5b. Pola frase item sebelum kuantitas: misal "sembako bro beras 1kg" atau "BERAS: 1KG" -> "Beras"
            if ($itemName === null && preg_match('/(?:^|\s)([a-zA-Z\s]+?)[:\-]?\s*\d+(?:[.,]\d+)?\s*(?:kg|galon|pcs|dus|sak|box|liter|butir|pack)\b/i', $content, $mPreQty)) {
                $words = preg_split('/\s+/', trim($mPreQty[1]));
                $stopwords = ['sembako', 'bro', 'bang', 'mas', 'gan', 'min', 'dong', 'ya', 'tolong', 'pesan', 'beli', 'order', 'untuk', 'produk', 'bahan', 'item'];
                if ($foundSupplier !== null) {
                    $supWords = explode(' ', $this->normalizeSearchText($foundSupplier->name));
                    $stopwords = array_merge($stopwords, $supWords);
                }
                $filteredWords = array_values(array_filter($words, fn($w) => !in_array(strtolower($w), $stopwords, true)));
                if (!empty($filteredWords)) {
                    $cand = implode(' ', $filteredWords);
                    if (strlen($cand) >= 2 && strlen($cand) <= 30) {
                        $itemName = ucfirst(strtolower(trim($cand)));
                    }
                }
            }

            // 5c. Pola asisten menyebut produk/bahan: misal "dipilih untuk produk beras 1kg" atau "produk beras"
            if (($turn['role'] ?? '') === 'assistant' && $itemName === null && preg_match('/(?:untuk produk|untuk bahan|produk|bahan)\s+([a-zA-Z0-9\s]+?)(?:\s+\d+|\.|\,|\;|\n|\r|$)/i', $content, $mAsstItem)) {
                $cand = trim($mAsstItem[1]);
                $candLower = strtolower($cand);
                if (!empty($cand) && !in_array($candLower, ['restock', 'supplier', 'pesanan', 'chat', 'anda', 'kami', 'toko', 'direct', 'wa'], true) && strlen($cand) <= 30) {
                    $itemName = ucfirst($cand);
                }
            }
        }

        // Ekstraksi tanggal pengiriman dari riwayat jika ada
        $extractedDeliveryDate = null;
        foreach ($reversed as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $content = (string) ($turn['content'] ?? '');
            if ($extractedDeliveryDate === null && $content !== '') {
                $candDate = $this->extractDeliveryDateFromText($content);
                if ($candDate !== null) {
                    $extractedDeliveryDate = $candDate;
                    break;
                }
            }
        }

        // Jika kuantitas ditemukan tetapi nama item belum terdeteksi, gunakan nama satuan sebagai item fallback (misal: "Galon")
        if ($itemName === null && $quantity !== null && !empty($unit)) {
            $itemName = ucfirst($unit);
        }

        if ($itemName !== null && $quantity !== null && $quantity > 0) {
            $foundItems[] = [
                'item_name' => $itemName,
                'quantity'  => $quantity,
                'unit'      => $unit,
            ];
        }

        // Tangani ambiguitas pemilihan supplier jika ada >1 supplier cocok dengan kata khas yang sama
        if ($isAmbiguousSupplier) {
            return [
                'ambiguous_supplier' => true,
                'matched_hint'       => $ambiguousHint,
                'candidates'         => $ambiguousCandidates,
                'items'              => $foundItems,
                'delivery_date'      => $extractedDeliveryDate,
            ];
        }

        if ($foundSupplier !== null && !empty($foundItems)) {
            return [
                'supplier_id'   => $foundSupplier->id,
                'supplier_name' => $foundSupplier->name,
                'items'         => $foundItems,
                'delivery_date' => $extractedDeliveryDate,
            ];
        }

        if ($foundSupplier !== null && empty($foundItems)) {
            return [
                'supplier_id'   => $foundSupplier->id,
                'supplier_name' => $foundSupplier->name,
                'items'         => [],
                'delivery_date' => $extractedDeliveryDate,
                'needs_items'   => true,
            ];
        }

        return null;
    }

    /**
     * Deteksi ambiguitas atau kontradiksi tanggal pada pesan pengguna.
     * Menggunakan Carbon locale 'id' dan tanggal acuan waktu saat ini (Asia/Jakarta).
     */
    public function detectDeliveryDateAmbiguity(?string $text, ?\Carbon\Carbon $refDate = null): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $ref = $refDate ? $refDate->copy() : \Carbon\Carbon::now('Asia/Jakarta');
        $ref->locale('id');

        $lower = strtolower($text);
        $hasBesok = (bool) preg_match('/\bbesok\b/i', $lower);
        $hasLusa = (bool) preg_match('/\blusa\b/i', $lower);

        $namedDays = [
            'senin'  => 1,
            'selasa' => 2,
            'rabu'   => 3,
            'kamis'  => 4,
            'jumat'  => 5,
            'sabtu'  => 6,
            'minggu' => 7,
        ];

        $foundDay = null;
        $foundDayNum = null;
        foreach ($namedDays as $name => $num) {
            if (preg_match('/\b' . $name . '\b/i', $lower)) {
                $foundDay = ucfirst($name);
                $foundDayNum = $num;
                break;
            }
        }

        if ($hasBesok && $foundDay !== null) {
            $tomorrow = $ref->copy()->addDay();
            $tomorrowDayNum = (int) $tomorrow->dayOfWeekIso;
            if ($tomorrowDayNum !== $foundDayNum) {
                $tomorrowName = $tomorrow->translatedFormat('l');
                return [
                    'has_conflict'  => true,
                    'type'          => 'besok_vs_day',
                    'relative'      => "besok ({$tomorrowName})",
                    'named'         => $foundDay,
                    'message'       => "Mohon klarifikasi dan konfirmasi tanggal pengiriman: Anda menyebutkan \"besok\" (jatuh pada hari {$tomorrowName}) tetapi juga mencantumkan hari {$foundDay}. Apakah yang Anda maksud pengiriman besok ({$tomorrowName}) atau hari {$foundDay}? Mohon sebutkan kembali tanggal pastinya agar pesanan tidak salah jadwal.",
                ];
            }
        }

        if ($hasLusa && $foundDay !== null) {
            $dayAfter = $ref->copy()->addDays(2);
            $dayAfterNum = (int) $dayAfter->dayOfWeekIso;
            if ($dayAfterNum !== $foundDayNum) {
                $dayAfterName = $dayAfter->translatedFormat('l');
                return [
                    'has_conflict'  => true,
                    'type'          => 'lusa_vs_day',
                    'relative'      => "lusa ({$dayAfterName})",
                    'named'         => $foundDay,
                    'message'       => "Mohon klarifikasi dan konfirmasi tanggal pengiriman: Anda menyebutkan \"lusa\" (jatuh pada hari {$dayAfterName}) tetapi juga mencantumkan hari {$foundDay}. Apakah yang Anda maksud pengiriman lusa ({$dayAfterName}) atau hari {$foundDay}?",
                ];
            }
        }

        return null;
    }

    /**
     * Ekstrak tanggal pengiriman valid dari teks jika tidak ada kontradiksi.
     */
    public function extractDeliveryDateFromText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        // Cek pola eksplisit "tgl/tanggal pengiriman [X]" atau "tgl [X]" atau "tanggal [X]"
        if (preg_match('/(?:tgl|tanggal|jadwal|hari|waktu|pengiriman)\s+(?:pengiriman\s+)?([a-zA-Z0-9\s,\/\-]+?)(?:$|\.|\;|\n|\r)/i', $text, $m)) {
            $candidate = trim($m[1]);
            $clean = trim(preg_replace('/^(pada|di|untuk|ke)\s+/i', '', $candidate));
            if (!empty($clean) && strlen($clean) <= 40) {
                return ucfirst($clean);
            }
        }

        // Cek hari eksplisit
        if (preg_match('/\b(senin|selasa|rabu|kamis|jumat|sabtu|minggu|besok|lusa)\b/i', $text, $mDay)) {
            return ucfirst(trim($mDay[1]));
        }

        return null;
    }
}
