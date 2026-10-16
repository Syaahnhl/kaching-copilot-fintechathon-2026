<?php

namespace Tests\Feature\Api;

use App\Models\Menu;
use App\Models\MenuDemandForecast;
use App\Models\Outlet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🧪 DemandForecastApiTest
 *
 * Feature Test untuk DemandForecastApiController & Endpoint Read-Only Peramalan Kebutuhan Menu.
 *
 * Verifikasi 12 Skenario Inti:
 * 1. Request tanpa autentikasi ditolak (401).
 * 2. User tidak dapat membaca forecast milik tenant lain (404/anti-enumeration).
 * 3. User tidak dapat membaca forecast outlet yang bukan miliknya (404).
 * 4. User yang berwenang dapat membaca forecast milik outletnya (200 + struktur JSON valid).
 * 5. Filter start_date dan end_date berfungsi benar.
 * 6. Filter menu_id berfungsi menyaring item menu spesifik.
 * 7. Hasil kosong dikembalikan sebagai respons valid dengan koleksi kosong (200 + forecasts: []).
 * 8. Format tanggal tidak valid ditolak dengan 422.
 * 9. start_date setelah end_date ditolak dengan 422.
 * 10. per_page di luar batas (0 atau >200) ditolak dengan 422, dan nilai valid dipaginasikan.
 * 11. Request hanya membaca data: jumlah dan isi record database tidak berubah.
 * 12. Pastikan data outlet lain tidak bocor dalam hasil, pagination, atau metadata respons.
 * 13. Alias endpoint /api/demand-forecast/outlets/{outletId} juga berfungsi identik.
 * 14. Rentang tanggal melebihi 90 hari ditolak dengan 422.
 */
class DemandForecastApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantA;
    protected User $tenantB;
    protected Outlet $outletA1;
    protected Outlet $outletA2;
    protected Outlet $outletB1;
    protected Menu $menuA1;
    protected Menu $menuA2;
    protected Menu $menuB1;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Tenant A
        $this->tenantA = User::factory()->create([
            'name'               => 'Owner Resto A',
            'email'              => 'owner.a@kaching.id',
            'account_status'     => 'active',
            'subscription_plan'  => 'pro',
        ]);

        $this->outletA1 = Outlet::create([
            'user_id'   => $this->tenantA->id,
            'name'      => 'Kaching Resto Menteng (A1)',
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $this->outletA2 = Outlet::create([
            'user_id'   => $this->tenantA->id,
            'name'      => 'Kaching Resto Kemang (A2)',
            'timezone'  => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $this->menuA1 = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA1->id,
            'name'       => 'Nasi Goreng Spesial',
            'category'   => 'Makanan',
            'price'      => 25000,
            'cost_price' => 12000,
            'unit'       => 'Porsi',
        ]);

        $this->menuA2 = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA1->id,
            'name'       => 'Es Teh Manis',
            'category'   => 'Minuman',
            'price'      => 5000,
            'cost_price' => 1500,
            'unit'       => 'Gelas',
        ]);

        // 2. Setup Tenant B (Cross-tenant Isolation Guard)
        $this->tenantB = User::factory()->create([
            'name'               => 'Owner Cafe B',
            'email'              => 'owner.b@kaching.id',
            'account_status'     => 'active',
            'subscription_plan'  => 'pro',
        ]);

        $this->outletB1 = Outlet::create([
            'user_id'   => $this->tenantB->id,
            'name'      => 'Kaching Cafe Kuta (B1)',
            'timezone'  => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $this->menuB1 = Menu::create([
            'user_id'    => $this->tenantB->id,
            'outlet_id'  => $this->outletB1->id,
            'name'       => 'Kopi Susu Bali',
            'category'   => 'Minuman',
            'price'      => 20000,
            'cost_price' => 8000,
            'unit'       => 'Cup',
        ]);
    }

    /**
     * Skenario 1: Request tanpa autentikasi ditolak (401).
     */
    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}");

        $response->assertStatus(401);
    }

    /**
     * Skenario 2: User tidak dapat membaca forecast milik tenant lain (404/anti-enumeration).
     */
    public function test_user_cannot_access_other_tenant_outlet_forecast_returns_404(): void
    {
        Sanctum::actingAs($this->tenantA);

        // Tenant A mencoba mengakses Outlet milik Tenant B
        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletB1->id}");

        $response->assertStatus(404)
            ->assertJson([
                'status'  => 'error',
                'message' => 'Outlet tidak ditemukan atau Anda tidak memiliki akses ke cabang ini.',
            ]);
    }

    /**
     * Skenario 3: User tidak dapat membaca forecast outlet yang tidak eksis (404).
     */
    public function test_user_cannot_access_non_existent_outlet_returns_404(): void
    {
        Sanctum::actingAs($this->tenantA);

        $response = $this->getJson('/api/v1/demand-forecast/outlets/99999');

        $response->assertStatus(404)
            ->assertJson([
                'status'  => 'error',
                'message' => 'Outlet tidak ditemukan atau Anda tidak memiliki akses ke cabang ini.',
            ]);
    }

    /**
     * Skenario 4: User yang berwenang dapat membaca forecast milik outletnya (200 + struktur JSON valid).
     */
    public function test_authorized_user_can_read_their_outlet_forecast(): void
    {
        Sanctum::actingAs($this->tenantA);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-04',
            'cutoff_date'        => '2026-10-03',
            'uom'                => 'Porsi',
            'predicted_quantity' => 35.5,
            'lower_bound_p10'    => 28.0,
            'upper_bound_p90'    => 45.0,
            'model_version'      => 'catboost_v1.0.0',
            'feature_version'    => 'f37_native_v1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::parse('2026-10-03 12:00:00'),
        ]);

        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'outlet' => [
                        'id'   => $this->outletA1->id,
                        'name' => $this->outletA1->name,
                    ],
                    'forecasts' => [
                        [
                            'menu_id'            => $this->menuA1->id,
                            'menu_name'          => 'Nasi Goreng Spesial',
                            'menu_category'      => 'Makanan',
                            'target_date'        => '2026-10-04',
                            'cutoff_date'        => '2026-10-03',
                            'predicted_quantity' => 35.5,
                            'lower_bound_p10'    => 28.0,
                            'upper_bound_p90'    => 45.0,
                            'uom'                => 'Porsi',
                            'model_version'      => 'catboost_v1.0.0',
                            'feature_version'    => 'f37_native_v1',
                            'serving_origin'     => 'CATBOOST_BATCH',
                            'history_status'     => 'SUFFICIENT',
                        ],
                    ],
                    'pagination' => [
                        'current_page' => 1,
                        'per_page'     => 50,
                        'total'        => 1,
                    ],
                ],
            ]);
    }

    /**
     * Skenario 5: Filter start_date dan end_date berfungsi benar.
     */
    public function test_filter_start_date_and_end_date_filters_forecasts_correctly(): void
    {
        Sanctum::actingAs($this->tenantA);

        // Buat 3 data ramalan pada tanggal target berbeda
        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-01',
            'cutoff_date'        => '2026-09-30',
            'predicted_quantity' => 10.0,
            'lower_bound_p10'    => 5.0,
            'upper_bound_p90'    => 15.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-05',
            'cutoff_date'        => '2026-10-04',
            'predicted_quantity' => 20.0,
            'lower_bound_p10'    => 15.0,
            'upper_bound_p90'    => 25.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-10',
            'cutoff_date'        => '2026-10-09',
            'predicted_quantity' => 30.0,
            'lower_bound_p10'    => 25.0,
            'upper_bound_p90'    => 35.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        // Filter tanggal 2026-10-04 s/d 2026-10-06 (hanya harus mengembalikan record 2026-10-05)
        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}?start_date=2026-10-04&end_date=2026-10-06");

        $response->assertStatus(200);
        $data = $response->json('data.forecasts');
        $this->assertCount(1, $data);
        $this->assertEquals('2026-10-05', $data[0]['target_date']);
        $this->assertEquals(20.0, $data[0]['predicted_quantity']);
    }

    /**
     * Skenario 6: Filter menu_id berfungsi menyaring item menu spesifik.
     */
    public function test_filter_menu_id_filters_forecasts_correctly(): void
    {
        Sanctum::actingAs($this->tenantA);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-04',
            'cutoff_date'        => '2026-10-03',
            'predicted_quantity' => 50.0,
            'lower_bound_p10'    => 40.0,
            'upper_bound_p90'    => 60.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA2->id,
            'target_date'        => '2026-10-04',
            'cutoff_date'        => '2026-10-03',
            'predicted_quantity' => 100.0,
            'lower_bound_p10'    => 80.0,
            'upper_bound_p90'    => 120.0,
            'uom'                => 'Gelas',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}?menu_id={$this->menuA2->id}");

        $response->assertStatus(200);
        $data = $response->json('data.forecasts');
        $this->assertCount(1, $data);
        $this->assertEquals($this->menuA2->id, $data[0]['menu_id']);
        $this->assertEquals('Es Teh Manis', $data[0]['menu_name']);
        $this->assertEquals(100.0, $data[0]['predicted_quantity']);
    }

    /**
     * Skenario 7: Hasil kosong dikembalikan sebagai respons valid dengan koleksi kosong (200 + forecasts: []).
     */
    public function test_empty_forecast_returns_200_with_empty_array(): void
    {
        Sanctum::actingAs($this->tenantA);

        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'outlet' => [
                        'id'   => $this->outletA1->id,
                        'name' => $this->outletA1->name,
                    ],
                    'forecasts' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'total'        => 0,
                    ],
                ],
            ]);
    }

    /**
     * Skenario 8: Format tanggal tidak valid ditolak dengan 422.
     */
    public function test_invalid_date_format_returns_422(): void
    {
        Sanctum::actingAs($this->tenantA);

        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}?start_date=04-10-2026");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_date']);
    }

    /**
     * Skenario 9: start_date setelah end_date ditolak dengan 422.
     */
    public function test_start_date_after_end_date_returns_422(): void
    {
        Sanctum::actingAs($this->tenantA);

        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}?start_date=2026-10-10&end_date=2026-10-01");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    /**
     * Skenario 10: per_page di luar batas ditolak dengan 422, dan nilai valid dipaginasikan.
     */
    public function test_per_page_validation_bounds(): void
    {
        Sanctum::actingAs($this->tenantA);

        // Test batas bawah (min: 1)
        $responseMin = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}?per_page=0");
        $responseMin->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        // Test batas atas (max: 200)
        $responseMax = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}?per_page=250");
        $responseMax->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        // Test nilai valid
        $responseValid = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}?per_page=15");
        $responseValid->assertStatus(200)
            ->assertJsonPath('data.pagination.per_page', 15);
    }

    /**
     * Skenario 11: Request hanya membaca data: jumlah dan isi record database tidak berubah.
     */
    public function test_request_is_strictly_read_only_and_does_not_mutate_database(): void
    {
        Sanctum::actingAs($this->tenantA);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-04',
            'cutoff_date'        => '2026-10-03',
            'predicted_quantity' => 42.0,
            'lower_bound_p10'    => 35.0,
            'upper_bound_p90'    => 50.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        $forecastCountBefore = MenuDemandForecast::count();
        $userCountBefore     = User::count();
        $outletCountBefore   = Outlet::count();
        $menuCountBefore     = Menu::count();

        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}");
        $response->assertStatus(200);

        $this->assertEquals($forecastCountBefore, MenuDemandForecast::count());
        $this->assertEquals($userCountBefore, User::count());
        $this->assertEquals($outletCountBefore, Outlet::count());
        $this->assertEquals($menuCountBefore, Menu::count());
    }

    /**
     * Skenario 12: Pastikan data outlet lain tidak bocor dalam hasil, pagination, atau metadata respons.
     */
    public function test_no_cross_tenant_or_cross_outlet_data_leakage_in_response(): void
    {
        Sanctum::actingAs($this->tenantA);

        // Data untuk Outlet A1
        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-04',
            'cutoff_date'        => '2026-10-03',
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

        // Data untuk Outlet A2 (milik Tenant A juga, tapi cabang beda)
        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA2->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-04',
            'cutoff_date'        => '2026-10-03',
            'predicted_quantity' => 88.0,
            'lower_bound_p10'    => 70.0,
            'upper_bound_p90'    => 100.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        // Data untuk Outlet B1 (milik Tenant B)
        MenuDemandForecast::create([
            'user_id'            => $this->tenantB->id,
            'outlet_id'          => $this->outletB1->id,
            'menu_id'            => $this->menuB1->id,
            'target_date'        => '2026-10-04',
            'cutoff_date'        => '2026-10-03',
            'predicted_quantity' => 999.0,
            'lower_bound_p10'    => 900.0,
            'upper_bound_p90'    => 1100.0,
            'uom'                => 'Cup',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}");

        $response->assertStatus(200);
        $forecasts = $response->json('data.forecasts');
        $pagination = $response->json('data.pagination');

        $this->assertCount(1, $forecasts);
        $this->assertEquals(1, $pagination['total']);
        $this->assertEquals(15.0, $forecasts[0]['predicted_quantity']);
    }

    /**
     * Skenario 13: Alias endpoint /api/demand-forecast/outlets/{outletId} juga berfungsi identik.
     */
    public function test_alias_route_without_v1_prefix_also_works(): void
    {
        Sanctum::actingAs($this->tenantA);

        $response = $this->getJson("/api/demand-forecast/outlets/{$this->outletA1->id}");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    /**
     * Skenario 14: Rentang tanggal melebihi 90 hari ditolak dengan 422.
     */
    public function test_date_span_exceeding_90_days_returns_422(): void
    {
        Sanctum::actingAs($this->tenantA);

        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}?start_date=2026-01-01&end_date=2026-06-01");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    /**
     * Skenario 15: Filter hanya start_date mengembalikan forecast pada atau setelah tanggal tersebut.
     */
    public function test_query_with_only_start_date_returns_forecasts_on_or_after_date(): void
    {
        Sanctum::actingAs($this->tenantA);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-02',
            'cutoff_date'        => '2026-10-01',
            'predicted_quantity' => 10.0,
            'lower_bound_p10'    => 8.0,
            'upper_bound_p90'    => 12.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-05',
            'cutoff_date'        => '2026-10-01',
            'predicted_quantity' => 20.0,
            'lower_bound_p10'    => 15.0,
            'upper_bound_p90'    => 25.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}?start_date=2026-10-04");

        $response->assertStatus(200);
        $forecasts = $response->json('data.forecasts');
        $this->assertCount(1, $forecasts);
        $this->assertEquals('2026-10-05', $forecasts[0]['target_date']);
        $this->assertEquals(20.0, $forecasts[0]['predicted_quantity']);
    }

    /**
     * Skenario 16: Filter hanya end_date mengembalikan forecast pada atau sebelum tanggal tersebut.
     */
    public function test_query_with_only_end_date_returns_forecasts_on_or_before_date(): void
    {
        Sanctum::actingAs($this->tenantA);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-02',
            'cutoff_date'        => '2026-10-01',
            'predicted_quantity' => 10.0,
            'lower_bound_p10'    => 8.0,
            'upper_bound_p90'    => 12.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-08',
            'cutoff_date'        => '2026-10-01',
            'predicted_quantity' => 25.0,
            'lower_bound_p10'    => 20.0,
            'upper_bound_p90'    => 30.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}?end_date=2026-10-03");

        $response->assertStatus(200);
        $forecasts = $response->json('data.forecasts');
        $this->assertCount(1, $forecasts);
        $this->assertEquals('2026-10-02', $forecasts[0]['target_date']);
        $this->assertEquals(10.0, $forecasts[0]['predicted_quantity']);
    }

    /**
     * Skenario 17: Relasi menu yang terhapus (soft-deleted) menghasilkan response JSON aman (null menu_name/menu_category).
     */
    public function test_forecast_with_deleted_menu_returns_null_menu_name_safely(): void
    {
        Sanctum::actingAs($this->tenantA);

        $tempMenu = Menu::create([
            'user_id'    => $this->tenantA->id,
            'outlet_id'  => $this->outletA1->id,
            'name'       => 'Menu Dihapus',
            'category'   => 'Makanan',
            'price'      => 20000,
            'cost_price' => 10000,
            'stock'      => 50,
        ]);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $tempMenu->id,
            'target_date'        => '2026-10-05',
            'cutoff_date'        => '2026-10-01',
            'predicted_quantity' => 12.0,
            'lower_bound_p10'    => 9.0,
            'upper_bound_p90'    => 15.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        // Hapus menu (soft delete)
        $tempMenu->delete();

        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}");

        $response->assertStatus(200);
        $forecasts = $response->json('data.forecasts');
        $this->assertCount(1, $forecasts);
        $this->assertEquals($tempMenu->id, $forecasts[0]['menu_id']);
        $this->assertNull($forecasts[0]['menu_name']);
        $this->assertNull($forecasts[0]['menu_category']);
        $this->assertEquals(12.0, $forecasts[0]['predicted_quantity']);
    }

    /**
     * Skenario 18: Filter menu_id milik outlet/tenant lain tidak membocorkan data (mengembalikan empty list).
     */
    public function test_filtering_by_foreign_menu_id_returns_empty_forecast_list(): void
    {
        Sanctum::actingAs($this->tenantA);

        MenuDemandForecast::create([
            'user_id'            => $this->tenantA->id,
            'outlet_id'          => $this->outletA1->id,
            'menu_id'            => $this->menuA1->id,
            'target_date'        => '2026-10-05',
            'cutoff_date'        => '2026-10-01',
            'predicted_quantity' => 12.0,
            'lower_bound_p10'    => 9.0,
            'upper_bound_p90'    => 15.0,
            'uom'                => 'Porsi',
            'model_version'      => 'v1',
            'feature_version'    => 'f1',
            'serving_origin'     => 'CATBOOST_BATCH',
            'history_status'     => 'SUFFICIENT',
            'generated_at'       => Carbon::now(),
        ]);

        // Query Outlet A1 dengan menu_id milik Tenant B (menuB1)
        $response = $this->getJson("/api/v1/demand-forecast/outlets/{$this->outletA1->id}?menu_id={$this->menuB1->id}");

        $response->assertStatus(200);
        $forecasts = $response->json('data.forecasts');
        $this->assertCount(0, $forecasts);
        $this->assertEquals(0, $response->json('data.pagination.total'));
    }
}
