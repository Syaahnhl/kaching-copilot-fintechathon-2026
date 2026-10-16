<?php

namespace Tests\Unit;

use App\Http\Middleware\SetLocale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class WebLocalizationTest extends TestCase
{
    /**
     * 7. Test catalog keys match identically across both locales (100% key parity).
     */
    public function test_catalog_keys_match_identically_across_both_locales(): void
    {
        $idPath = base_path('lang/id/backoffice.php');
        $enPath = base_path('lang/en/backoffice.php');

        $this->assertFileExists($idPath);
        $this->assertFileExists($enPath);

        $idCatalog = require $idPath;
        $enCatalog = require $enPath;

        $this->assertIsArray($idCatalog);
        $this->assertIsArray($enCatalog);

        $idFlat = $this->flattenArrayKeys($idCatalog);
        $enFlat = $this->flattenArrayKeys($enCatalog);

        sort($idFlat);
        sort($enFlat);

        $this->assertSame($idFlat, $enFlat, 'Catalog keys between id and en must match 100% identically.');
        $this->assertGreaterThanOrEqual(40, count($idFlat), 'Catalog must contain all agreed navigation and layout keys.');
    }

    /**
     * 1. Test that /lang/{locale} accepts id and en and saves to session.
     */
    public function test_locale_switch_route_accepts_id_and_en(): void
    {
        $responseEn = $this->get('/lang/en');
        $responseEn->assertStatus(302);
        $responseEn->assertSessionHas('locale', 'en');

        $responseId = $this->get('/lang/id');
        $responseId->assertStatus(302);
        $responseId->assertSessionHas('locale', 'id');
    }

    /**
     * 2. Test that unsupported locale values are rejected or safely normalized to default id.
     */
    public function test_unsupported_locale_values_are_safely_ignored_or_normalized(): void
    {
        // 1. Calling /lang with unsupported locale does not set invalid locale in session
        $response = $this->withSession(['locale' => 'id'])->get('/lang/unsupported_lang');
        $response->assertStatus(302);
        $this->assertNotEquals('unsupported_lang', session('locale'));

        // 2. Middleware directly normalizes corrupted session locale to default 'id'
        $request = Request::create('/test-probe', 'GET');
        $request->setLaravelSession(session()->driver());
        session()->put('locale', 'invalid_locale_xyz');

        $middleware = new SetLocale();
        $middleware->handle($request, function ($req) {
            $this->assertSame('id', App::getLocale());
            return response('OK');
        });

        $this->assertSame('id', session('locale'));
    }

    /**
     * 3. Test that session preference persists and sets App and Carbon locale.
     */
    public function test_session_preference_persists_and_sets_app_and_carbon_locale(): void
    {
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(session()->driver());
        session()->put('locale', 'en');

        $middleware = new SetLocale();
        $middleware->handle($request, function ($req) {
            $this->assertSame('en', App::getLocale());
            $this->assertSame('en', Carbon::getLocale());
            return response('OK');
        });
    }

    /**
     * Test that current locale is reflected in rendered translations.
     */
    public function test_current_locale_is_reflected_in_rendered_strings(): void
    {
        App::setLocale('id');
        $this->assertSame('Kelola Menu', __('backoffice.nav.manage_menu'));
        $this->assertSame('Operasional', __('backoffice.nav_sections.operational'));
        $this->assertSame('Dashboard', __('backoffice.nav.dashboard'));

        App::setLocale('en');
        $this->assertSame('Menu Management', __('backoffice.nav.manage_menu'));
        $this->assertSame('Operations', __('backoffice.nav_sections.operational'));
        $this->assertSame('Dashboard', __('backoffice.nav.dashboard'));
    }

    /**
     * 4. Test that language switching preserves safe internal navigation.
     */
    public function test_language_switching_preserves_safe_internal_navigation(): void
    {
        $internalUrl = url('/dashboard');

        $response = $this->from($internalUrl)->get('/lang/en');
        $response->assertStatus(302);
        $response->assertRedirect($internalUrl);
    }

    /**
     * 5. Test that external and protocol-relative redirect attempts are rejected and fallback to safe root.
     */
    public function test_external_redirect_attempts_are_rejected(): void
    {
        // 1. Malicious full URL
        $response = $this->withHeaders(['Referer' => 'https://evil.com/phishing'])
            ->get('/lang/en');

        $response->assertStatus(302);
        $location = $response->headers->get('Location');
        $this->assertNotSame('https://evil.com/phishing', $location);
        $this->assertSame(url('/'), $location);

        // 2. Protocol-relative URL
        $responseProtocolRelative = $this->withHeaders(['Referer' => '//evil.com/phishing'])
            ->get('/lang/en');

        $responseProtocolRelative->assertStatus(302);
        $locationPr = $responseProtocolRelative->headers->get('Location');
        $this->assertNotSame('//evil.com/phishing', $locationPr);
        $this->assertSame(url('/'), $locationPr);
    }

    /**
     * 6. Test that language switcher appears in backoffice layout and uses existing routes.
     */
    public function test_language_switcher_is_rendered_in_view_with_existing_routes(): void
    {
        $renderedHtml = view('backoffice.master')->render();

        $this->assertStringContainsString(route('lang.switch', 'id'), $renderedHtml);
        $this->assertStringContainsString(route('lang.switch', 'en'), $renderedHtml);
        $this->assertStringContainsString('Bahasa Indonesia', $renderedHtml);
        $this->assertStringContainsString('English', $renderedHtml);
    }

    /**
     * 8. Test existing authorization and tenant behavior remain unaffected.
     */
    public function test_existing_authorization_and_tenant_behavior_remain_unaffected(): void
    {
        $middleware = new SetLocale();

        $request = Request::create('/lang/en', 'GET');
        $request->setLaravelSession(session()->driver());
        session()->put('locale', 'en');

        $response = $middleware->handle($request, function ($req) {
            // Assert that no auth guard or database tenant bindings were touched
            $this->assertNull(auth()->user());
            return response('OK');
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('en', App::getLocale());
    }

    /**
     * 9. Test that middleware causes no regression on standard requests without locale parameter.
     */
    public function test_middleware_causes_no_regression_on_standard_requests_without_locale_parameter(): void
    {
        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession(session()->driver());
        // No session locale provided

        $middleware = new SetLocale();
        $response = $middleware->handle($request, function ($req) {
            $this->assertSame('id', App::getLocale());
            $this->assertSame('id', Carbon::getLocale());
            return response('DASHBOARD_OK');
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('DASHBOARD_OK', $response->getContent());
    }

    /**
     * 10. Test pergantian siklus ID -> EN -> ID dan persistensi session.
     */
    public function test_language_toggle_cycle_id_to_en_to_id_with_session_persistence(): void
    {
        // 1. Initial State: Switch to English
        $resEn = $this->get('/lang/en');
        $resEn->assertStatus(302);
        $resEn->assertSessionHas('locale', 'en');

        // Middleware verification for English
        $reqEn = Request::create('/dashboard', 'GET');
        $reqEn->setLaravelSession(session()->driver());
        session()->put('locale', 'en');

        $middleware = new SetLocale();
        $middleware->handle($reqEn, function () {
            $this->assertSame('en', App::getLocale());
            $this->assertSame('en', Carbon::getLocale());
            return response('OK');
        });

        // 2. Cycle Switch: Back to Indonesian
        $resId = $this->get('/lang/id');
        $resId->assertStatus(302);
        $resId->assertSessionHas('locale', 'id');

        // Middleware verification for Indonesian
        $reqId = Request::create('/dashboard', 'GET');
        $reqId->setLaravelSession(session()->driver());
        session()->put('locale', 'id');

        $middleware->handle($reqId, function () {
            $this->assertSame('id', App::getLocale());
            $this->assertSame('id', Carbon::getLocale());
            return response('OK');
        });

        // 3. Cycle Switch: Back to English again
        $resEnAgain = $this->get('/lang/en');
        $resEnAgain->assertStatus(302);
        $resEnAgain->assertSessionHas('locale', 'en');
    }

    /**
     * 11. Test persistensi locale setelah navigasi multi-halaman dan simulated reload.
     */
    public function test_locale_persistence_across_simulated_navigation_and_reload(): void
    {
        $middleware = new SetLocale();

        // User sets preference to 'en'
        session()->put('locale', 'en');

        // Navigasi ke Halaman 1: Dashboard
        $req1 = Request::create('/dashboard', 'GET');
        $req1->setLaravelSession(session()->driver());
        $middleware->handle($req1, function () {
            $this->assertSame('en', App::getLocale());
            return response('PAGE_1');
        });

        // Navigasi ke Halaman 2: Copilot
        $req2 = Request::create('/copilot', 'GET');
        $req2->setLaravelSession(session()->driver());
        $middleware->handle($req2, function () {
            $this->assertSame('en', App::getLocale());
            return response('PAGE_2');
        });

        // Navigasi ke Halaman 3: Inventory
        $req3 = Request::create('/inventory', 'GET');
        $req3->setLaravelSession(session()->driver());
        $middleware->handle($req3, function () {
            $this->assertSame('en', App::getLocale());
            return response('PAGE_3');
        });

        // Simulated Page Reload (F5 pada halaman yang sama)
        $reqReload = Request::create('/inventory', 'GET');
        $reqReload->setLaravelSession(session()->driver());
        $middleware->handle($reqReload, function () {
            $this->assertSame('en', App::getLocale());
            $this->assertSame('en', Carbon::getLocale());
            return response('RELOAD_OK');
        });

        // Pastikan session tetap mempertahankan 'en'
        $this->assertSame('en', session('locale'));
    }

    /**
     * 12. Test halaman Copilot dan Command Center merender string terjemahan sesuai locale.
     */
    public function test_copilot_page_and_command_center_render_localized_strings(): void
    {
        // Indonesian Locale Verification
        App::setLocale('id');
        $this->assertSame('Operasional', __('backoffice.nav_sections.operational'));
        $this->assertSame('Kelola Menu', __('backoffice.nav.manage_menu'));
        $this->assertSame('Penjualan Hari Ini', __('copilot.widget.quick_sales_title'));
        $this->assertSame('Performa Menu', __('copilot.widget.quick_menu_title'));
        $this->assertSame('Berapa omzet saya hari ini?', __('copilot.widget.quick_sales'));

        // English Locale Verification
        App::setLocale('en');
        $this->assertSame('Operations', __('backoffice.nav_sections.operational'));
        $this->assertSame('Menu Management', __('backoffice.nav.manage_menu'));
        $this->assertSame("Today's Sales", __('copilot.widget.quick_sales_title'));
        $this->assertSame('Menu Performance', __('copilot.widget.quick_menu_title'));
        $this->assertSame('What is my revenue today?', __('copilot.widget.quick_sales'));
    }

    /**
     * 13. Test fallback aman jika preferensi tidak valid atau resource key tidak ditemukan.
     */
    public function test_fallback_mechanism_for_invalid_locale_or_missing_keys(): void
    {
        $middleware = new SetLocale();

        // 1. Session berisi corrupted/unsupported locale
        session()->put('locale', 'xx_unsupported');
        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession(session()->driver());

        $middleware->handle($request, function () {
            $this->assertSame('id', App::getLocale(), 'Harus fallback ke id saat locale tidak didukung');
            return response('OK');
        });
        $this->assertSame('id', session('locale'));

        // 2. Key terjemahan yang tidak eksis di katalog mengembalikan raw key secara aman
        $missingKey = __('backoffice.non_existent_key_12345');
        $this->assertSame('backoffice.non_existent_key_12345', $missingKey);
    }

    /**
     * 14. Test toggle bahasa tidak mengubah database atau request API bisnis.
     */
    public function test_language_toggle_does_not_mutate_api_requests_or_business_data(): void
    {
        // 1. Switch language via GET /lang/{locale}
        $response = $this->get('/lang/en');
        $response->assertStatus(302);

        // Verifikasi session hanya berisi state navigasi/locale
        $this->assertSame('en', session('locale'));

        // 2. Verifikasi tidak ada perubahan auth, token, atau tenant bindings
        $this->assertNull(auth()->user());
        $this->assertNull(session('user_id'));
        $this->assertNull(session('outlet_id'));
    }

    /**
     * 15. Test that header language toggle pill is rendered with ID | EN adjacent to the date badge.
     */
    public function test_header_language_toggle_pill_rendered_with_id_en_adjacent_to_date_badge(): void
    {
        $renderedHtml = view('backoffice.master')->render();

        // 1. Verifikasi elemen toggle pill berada di header
        $this->assertStringContainsString('rounded-full', $renderedHtml);
        $this->assertStringContainsString('text-blue-600', $renderedHtml);
        $this->assertStringContainsString('ID', $renderedHtml);
        $this->assertStringContainsString('EN', $renderedHtml);

        // 2. Verifikasi urutan: Toggle ID | EN muncul sebelum tanggal Carbon
        $togglePos = strpos($renderedHtml, route('lang.switch', 'id'));
        $datePos = strpos($renderedHtml, Carbon::now()->translatedFormat('d F Y'));

        $this->assertNotFalse($togglePos, 'Toggle link for ID must exist in rendered view.');
        $this->assertNotFalse($datePos, 'Dynamic date badge must exist in rendered view.');
        $this->assertLessThan($datePos, $togglePos, 'Toggle ID | EN must precede the date badge in DOM order.');
    }

    /**
     * 16. Test dashboard view renders Indonesian text when locale is 'id'.
     */
    public function test_dashboard_view_renders_id_strings_when_locale_is_id(): void
    {
        App::setLocale('id');

        $html = view('backoffice.dashboard', [
            'title' => __('backoffice.dashboard.filters.today'),
            'outletFilter' => 'all',
            'shiftFilter' => 'all',
            'todayOmset' => 1500000,
            'todayCount' => 25,
            'cashSales' => 1000000,
            'digitalSales' => 500000,
            'totalPiutang' => 0,
            'chartSubtitle' => 'Omset per jam — Hari Ini',
            'chartLabels' => ['08:00', '09:00'],
            'chartData' => [100000, 200000],
            'chartOmset7Hari' => ['labels' => [], 'data' => []],
            'chartPaymentMethod' => ['labels' => [], 'data' => []],
            'todaySchedules' => collect(),
            'lowStockMenus' => collect(),
            'activeShift' => null,
            'recentShifts' => collect(),
            'outlets' => collect(),
        ])->render();

        $this->assertStringContainsString('Overview Hari Ini', $html);
        $this->assertStringContainsString('Pantau omset, stok, dan tim', $html);
        $this->assertStringContainsString('Total Omset', $html);
        $this->assertStringContainsString('Total Transaksi', $html);
        $this->assertStringContainsString('Nota', $html);
        $this->assertStringContainsString('Uang Tunai', $html);
        $this->assertStringContainsString('Piutang', $html);
        $this->assertStringContainsString('Shift Aktif', $html);
        $this->assertStringContainsString('Riwayat Shift', $html);
        $this->assertStringContainsString('Tren Omset 7 Hari Terakhir', $html);
        $this->assertStringContainsString('Distribusi Pembayaran', $html);
        $this->assertStringContainsString('Grafik Penjualan', $html);
        $this->assertStringContainsString('Tim Bertugas Hari Ini', $html);
        $this->assertStringContainsString('Stok Menipis', $html);
    }

    /**
     * 17. Test dashboard view renders English text when locale is 'en'.
     */
    public function test_dashboard_view_renders_en_strings_when_locale_is_en(): void
    {
        App::setLocale('en');

        $html = view('backoffice.dashboard', [
            'title' => __('backoffice.dashboard.filters.today'),
            'outletFilter' => 'all',
            'shiftFilter' => 'all',
            'todayOmset' => 1500000,
            'todayCount' => 25,
            'cashSales' => 1000000,
            'digitalSales' => 500000,
            'totalPiutang' => 0,
            'chartSubtitle' => 'Hourly revenue — Today',
            'chartLabels' => ['08:00', '09:00'],
            'chartData' => [100000, 200000],
            'chartOmset7Hari' => ['labels' => [], 'data' => []],
            'chartPaymentMethod' => ['labels' => [], 'data' => []],
            'todaySchedules' => collect(),
            'lowStockMenus' => collect(),
            'activeShift' => null,
            'recentShifts' => collect(),
            'outlets' => collect(),
        ])->render();

        $this->assertStringContainsString('Overview Today', $html);
        $this->assertStringContainsString('Monitor revenue, inventory, and team', $html);
        $this->assertStringContainsString('Total Revenue', $html);
        $this->assertStringContainsString('Total Transactions', $html);
        $this->assertStringContainsString('Receipts', $html);
        $this->assertStringContainsString('Cash', $html);
        $this->assertStringContainsString('Receivables', $html);
        $this->assertStringContainsString('Active Shift', $html);
        $this->assertStringContainsString('Shift History', $html);
        $this->assertStringContainsString('Revenue Trend — Last 7 Days', $html);
        $this->assertStringContainsString('Payment Distribution', $html);
        $this->assertStringContainsString('Sales Chart', $html);
        $this->assertStringContainsString('Team on Duty Today', $html);
        $this->assertStringContainsString('Low Stock', $html);
    }

    /**
     * 18. Test dashboard subtitle renders dynamic store parameter correctly in both locales.
     */
    public function test_dashboard_subtitle_renders_dynamic_store_parameter(): void
    {
        App::setLocale('id');
        $subtitleId = __('backoffice.dashboard.subtitle', ['store' => 'Kopi Mantap']);
        $this->assertSame('Pantau omset, stok, dan tim Kopi Mantap.', $subtitleId);

        App::setLocale('en');
        $subtitleEn = __('backoffice.dashboard.subtitle', ['store' => 'Kopi Mantap']);
        $this->assertSame('Monitor revenue, inventory, and team of Kopi Mantap.', $subtitleEn);
    }

    /**
     * 19. Test AJAX dashboard response payload contains localized title and units.
     */
    public function test_dashboard_ajax_response_contains_localized_title_and_units(): void
    {
        // Simulasi controller output untuk locale ID
        App::setLocale('id');
        $titleId = __('backoffice.dashboard.overview') . ' ' . __('backoffice.dashboard.filters.today');
        $unitId = __('backoffice.dashboard.metrics.receipts_unit');
        $subtitleId = __('backoffice.dashboard.charts.hourly_subtitle', ['date' => 'Hari Ini']);

        $this->assertSame('Overview Hari Ini', $titleId);
        $this->assertSame('Nota', $unitId);
        $this->assertSame('Omset per jam — Hari Ini', $subtitleId);

        // Simulasi controller output untuk locale EN
        App::setLocale('en');
        $titleEn = __('backoffice.dashboard.overview') . ' ' . __('backoffice.dashboard.filters.today');
        $unitEn = __('backoffice.dashboard.metrics.receipts_unit');
        $subtitleEn = __('backoffice.dashboard.charts.hourly_subtitle', ['date' => 'Today']);

        $this->assertSame('Overview Today', $titleEn);
        $this->assertSame('Receipts', $unitEn);
        $this->assertSame('Hourly revenue — Today', $subtitleEn);
    }

    /**
     * 20. Test dashboard DOM IDs and filter links are preserved intact.
     */
    public function test_dashboard_dom_ids_and_filter_links_preserved(): void
    {
        $html = view('backoffice.dashboard', [
            'title' => 'Today',
            'outletFilter' => 'all',
            'shiftFilter' => 'all',
            'todayOmset' => 0,
            'todayCount' => 0,
            'cashSales' => 0,
            'digitalSales' => 0,
            'totalPiutang' => 0,
            'chartSubtitle' => 'Subtitle',
            'chartLabels' => [],
            'chartData' => [],
            'chartOmset7Hari' => ['labels' => [], 'data' => []],
            'chartPaymentMethod' => ['labels' => [], 'data' => []],
            'todaySchedules' => collect(),
            'lowStockMenus' => collect(),
            'activeShift' => null,
            'recentShifts' => collect(),
            'outlets' => collect(),
        ])->render();

        $this->assertStringContainsString('id="ui-title"', $html);
        $this->assertStringContainsString('id="ui-omset"', $html);
        $this->assertStringContainsString('id="ui-nota"', $html);
        $this->assertStringContainsString('id="ui-cash"', $html);
        $this->assertStringContainsString('id="ui-digital"', $html);
        $this->assertStringContainsString('id="ui-piutang"', $html);
        $this->assertStringContainsString('id="ui-chart-subtitle"', $html);
        $this->assertStringContainsString('id="customDateFilter"', $html);
        $this->assertStringContainsString('id="customDateBtn"', $html);
        $this->assertStringContainsString('filter=today', $html);
        $this->assertStringContainsString('filter=7days', $html);
        $this->assertStringContainsString('filter=30days', $html);
        $this->assertStringContainsString('filter=year', $html);
        $this->assertStringContainsString('filter=all', $html);
    }

    /**
     * 21. Test sidebar renders active locale navigation labels in master layout.
     */
    public function test_sidebar_renders_active_locale_labels_in_master_layout(): void
    {
        App::setLocale('id');
        $htmlId = view('backoffice.master')->render();
        $this->assertStringContainsString('Riwayat Transaksi', $htmlId);
        $this->assertStringContainsString('Reservasi Meja', $htmlId);
        $this->assertStringContainsString('Tim &amp; Karyawan', $htmlId);
        $this->assertStringContainsString('Inventaris &amp; Restock', $htmlId);

        App::setLocale('en');
        $htmlEn = view('backoffice.master')->render();
        $this->assertStringContainsString('Transaction History', $htmlEn);
        $this->assertStringContainsString('Table Reservations', $htmlEn);
        $this->assertStringContainsString('Team &amp; Staff', $htmlEn);
        $this->assertStringContainsString('Inventory &amp; Restock', $htmlEn);
    }

    /**
     * Recursively flatten array keys into dot-separated strings.
     */
    private function flattenArrayKeys(array $array, string $prefix = ''): array
    {
        $keys = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenArrayKeys($value, $fullKey));
            } else {
                $keys[] = $fullKey;
            }
        }
        return $keys;
    }
}
