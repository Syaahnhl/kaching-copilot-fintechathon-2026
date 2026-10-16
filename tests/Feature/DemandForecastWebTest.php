<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\MenuDemandForecast;
use App\Models\Outlet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemandForecastWebTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;
    protected Outlet $outletA;
    protected Outlet $outletB;
    protected Outlet $otherOutlet;
    protected Menu $menuA;
    protected Menu $menuB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'testing.merchant@kaching.test',
            'role'  => 'owner',
        ]);

        $this->outletA = Outlet::create([
            'user_id'   => $this->user->id,
            'name'      => 'Outlet Utama Cabang 1',
            'device_id' => 'DEV-OUTLET-A',
        ]);

        $this->outletB = Outlet::create([
            'user_id'   => $this->user->id,
            'name'      => 'Outlet Kedua Cabang 2',
            'device_id' => 'DEV-OUTLET-B',
        ]);

        $this->otherUser = User::factory()->create([
            'email' => 'other.merchant@kaching.test',
            'role'  => 'owner',
        ]);

        $this->otherOutlet = Outlet::create([
            'user_id'   => $this->otherUser->id,
            'name'      => 'Outlet Tenant Lain',
            'device_id' => 'DEV-OTHER-OUTLET',
        ]);

        $this->menuA = Menu::create([
            'user_id'    => $this->user->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Nasi Goreng Spesial',
            'category'   => 'Makanan',
            'price'      => 25000,
            'cost_price' => 12000,
            'stock'      => 100,
            'unit'       => 'Porsi',
        ]);

        $this->menuB = Menu::create([
            'user_id'    => $this->user->id,
            'outlet_id'  => $this->outletA->id,
            'name'       => 'Es Teh Manis',
            'category'   => 'Minuman',
            'price'      => 5000,
            'cost_price' => 2000,
            'stock'      => 200,
            'unit'       => 'Gelas',
        ]);
    }

    /**
     * Skenario 1: User belum terautentikasi dialihkan ke halaman login (302/401).
     */
    public function test_unauthenticated_user_cannot_access_demand_forecast_page(): void
    {
        $response = $this->get(route('copilot.intelligence.demand-forecast'));
        $response->assertRedirect('/login');
    }

    /**
     * Skenario 2: User terautentikasi (testing account) dapat mengakses halaman web demand forecast (200).
     */
    public function test_authenticated_testing_user_can_access_demand_forecast_page(): void
    {
        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.demand-forecast'));

        $response->assertStatus(200);
        $response->assertViewIs('backoffice.intelligence.demand_forecast');
        $response->assertSee('Prediksi Permintaan Menu (Demand Forecast)');
        $response->assertSee('Outlet Utama Cabang 1');
        $response->assertSee('Nasi Goreng Spesial');
    }

    /**
     * Skenario 3: Non-testing account mendapatkan respons 403 Forbidden.
     */
    public function test_non_testing_account_is_forbidden_from_preview(): void
    {
        $regularUser = User::factory()->create([
            'email' => 'regular.merchant@domain.com',
            'role'  => 'owner',
        ]);

        $response = $this->actingAs($regularUser)->get(route('copilot.intelligence.demand-forecast'));
        $response->assertStatus(403);
    }

    /**
     * Skenario 4: User tidak dapat mengakses outlet milik tenant lain via query parameter (403).
     */
    public function test_user_cannot_access_other_tenant_outlet_via_query(): void
    {
        $response = $this->actingAs($this->user)->get(route('copilot.intelligence.demand-forecast', [
            'outlet_id' => $this->otherOutlet->id,
        ]));

        $response->assertStatus(403);
    }

    /**
     * Skenario 5: AJAX Data Endpoint mengembalikan JSON forecast sesuai outlet aktif dan kontrak DTO.
     */
    public function test_ajax_data_endpoint_returns_forecast_records(): void
    {
        MenuDemandForecast::create([
            'user_id'            => $this->user->id,
            'outlet_id'          => $this->outletA->id,
            'menu_id'            => $this->menuA->id,
            'target_date'        => '2026-10-05',
            'cutoff_date'        => '2026-10-01',
            'predicted_quantity' => 25.5,
            'lower_bound_p10'    => 20.0,
            'upper_bound_p90'    => 32.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        $response = $this->actingAs($this->user)->getJson(route('copilot.intelligence.demand-forecast.data', [
            'outlet_id' => $this->outletA->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'outlet' => ['id', 'name'],
                'forecasts' => [
                    '*' => [
                        'id',
                        'menu_id',
                        'menu_name',
                        'menu_category',
                        'target_date',
                        'cutoff_date',
                        'predicted_quantity',
                        'lower_bound_p10',
                        'upper_bound_p90',
                        'uom',
                        'model_version',
                        'feature_version',
                        'serving_origin',
                        'history_status',
                        'generated_at',
                    ],
                ],
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                    'from',
                    'to',
                ],
            ],
        ]);

        $this->assertEquals('success', $response->json('status'));
        $this->assertEquals('Nasi Goreng Spesial', $response->json('data.forecasts.0.menu_name'));
        $this->assertEquals(25.5, $response->json('data.forecasts.0.predicted_quantity'));
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }

    /**
     * Skenario 6: AJAX Data Endpoint menolak akses ke outlet tenant lain (404 Not Found anti-idor).
     */
    public function test_ajax_data_endpoint_rejects_foreign_outlet(): void
    {
        $response = $this->actingAs($this->user)->getJson(route('copilot.intelligence.demand-forecast.data', [
            'outlet_id' => $this->otherOutlet->id,
        ]));

        $response->assertStatus(404);
        $this->assertEquals('error', $response->json('status'));
    }

    /**
     * Skenario 7: Filter menu_id dan tanggal bekerja dengan benar pada endpoint AJAX.
     */
    public function test_ajax_data_endpoint_applies_date_and_menu_filters(): void
    {
        // Item 1: Menu A pada 2026-10-05
        MenuDemandForecast::create([
            'user_id'            => $this->user->id,
            'outlet_id'          => $this->outletA->id,
            'menu_id'            => $this->menuA->id,
            'target_date'        => '2026-10-05',
            'cutoff_date'        => '2026-10-01',
            'predicted_quantity' => 15.0,
            'lower_bound_p10'    => 10.0,
            'upper_bound_p90'    => 20.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        // Item 2: Menu B pada 2026-10-08
        MenuDemandForecast::create([
            'user_id'            => $this->user->id,
            'outlet_id'          => $this->outletA->id,
            'menu_id'            => $this->menuB->id,
            'target_date'        => '2026-10-08',
            'cutoff_date'        => '2026-10-01',
            'predicted_quantity' => 40.0,
            'lower_bound_p10'    => 30.0,
            'upper_bound_p90'    => 50.0,
            'uom'                => 'Gelas',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        // Query Filter Menu A saja
        $resA = $this->actingAs($this->user)->getJson(route('copilot.intelligence.demand-forecast.data', [
            'outlet_id' => $this->outletA->id,
            'menu_id'   => $this->menuA->id,
        ]));
        $resA->assertStatus(200);
        $this->assertCount(1, $resA->json('data.forecasts'));
        $this->assertEquals($this->menuA->id, $resA->json('data.forecasts.0.menu_id'));

        // Query Filter rentang tanggal eksklusif (hanya mencakup 2026-10-08)
        $resDate = $this->actingAs($this->user)->getJson(route('copilot.intelligence.demand-forecast.data', [
            'outlet_id'   => $this->outletA->id,
            'start_date'  => '2026-10-07',
            'end_date'    => '2026-10-10',
        ]));
        $resDate->assertStatus(200);
        $this->assertCount(1, $resDate->json('data.forecasts'));
        $this->assertEquals($this->menuB->id, $resDate->json('data.forecasts.0.menu_id'));
    }

    /**
     * Skenario 8: Overview Kaching Intelligence menampilkan card Demand Forecast dengan benar.
     */
    public function test_intelligence_overview_displays_demand_forecast_card(): void
    {
        $response = $this->actingAs($this->user)->get(route('copilot.intelligence'));

        $response->assertStatus(200);
        $response->assertViewIs('backoffice.intelligence.overview');
        $response->assertSee('Demand Forecast');
        $response->assertSee('Prediksi kebutuhan menu harian berbasis model statistik.');
        $response->assertSee(route('copilot.intelligence.demand-forecast'));
    }
}
