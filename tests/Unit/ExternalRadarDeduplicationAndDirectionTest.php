<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Radar\ExternalBusinessRadarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalRadarDeduplicationAndDirectionTest extends TestCase
{
    use RefreshDatabase;

    protected ExternalBusinessRadarService $service;
    protected User $user;
    protected Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ExternalBusinessRadarService::class);
        Cache::flush();

        $this->user = User::factory()->create([
            'role'              => 'owner',
            'subscription_tier' => 'ultimate',
        ]);

        $this->outlet = Outlet::factory()->create([
            'user_id' => $this->user->id,
            'name'    => 'Outlet Radar Test',
        ]);
    }

    /**
     * 1. Cross-source syndicated news with different redirect URLs but same normalized title are deduplicated.
     */
    public function test_cross_source_syndicated_news_deduplication(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Google News</title>
    <item>
      <title>Harga Cabai Rawit Naik di Pasar Tradisional - Antara News</title>
      <link>https://news.google.com/rss/articles/CBMi_ANTARA_1</link>
      <source url="https://antaranews.com">Antara News</source>
      <pubDate>Fri, 25 Sep 2026 03:15:00 GMT</pubDate>
      <description>Pasokan menipis akibat curah hujan tinggi.</description>
    </item>
    <item>
      <title>Harga Cabai Rawit Naik di Pasar Tradisional - Detikcom</title>
      <link>https://news.google.com/rss/articles/CBMi_DETIK_2</link>
      <source url="https://detik.com">Detikcom</source>
      <pubDate>Fri, 25 Sep 2026 03:20:00 GMT</pubDate>
      <description>Pasokan menipis akibat curah hujan tinggi di sentra produksi.</description>
    </item>
  </channel>
</rss>
XML;

        Http::fake([
            'https://news.google.com/*' => Http::response($xml, 200),
            'https://data.bmkg.go.id/*' => Http::response([], 200),
        ]);

        $res = $this->service->fetchSignals('COMMODITY');
        $this->assertCount(1, $res['signals'], 'Berita sindikasi dengan judul yang sama dari sumber berbeda harus dideduplikasi.');
        $this->assertStringContainsString('Harga Cabai Rawit Naik', $res['signals'][0]['headline']);
    }

    /**
     * 2. Distinct news items with different headlines are preserved.
     */
    public function test_distinct_news_items_are_both_preserved(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Google News</title>
    <item>
      <title>Harga Beras Premium Melonjak Tinggi - Antara News</title>
      <link>https://news.google.com/rss/articles/beras_1</link>
      <source url="https://antaranews.com">Antara News</source>
      <pubDate>Fri, 25 Sep 2026 03:15:00 GMT</pubDate>
      <description>Lonjakan harga beras akibat el nino.</description>
    </item>
    <item>
      <title>Pasokan Minyak Goreng Curah Melimpah dan Murah - Kompas</title>
      <link>https://news.google.com/rss/articles/minyak_2</link>
      <source url="https://kompas.com">Kompas</source>
      <pubDate>Fri, 25 Sep 2026 04:00:00 GMT</pubDate>
      <description>Harga minyak goreng curah turun.</description>
    </item>
  </channel>
</rss>
XML;

        Http::fake([
            'https://news.google.com/*' => Http::response($xml, 200),
            'https://data.bmkg.go.id/*' => Http::response([], 200),
        ]);

        $res = $this->service->fetchSignals('COMMODITY');
        $this->assertCount(2, $res['signals']);
    }

    /**
     * 3. Price direction classification (UP, DOWN, STABLE, UNKNOWN).
     */
    public function test_price_direction_classification(): void
    {
        // 1. UP
        $upRes = $this->service->determinePriceDirection('Harga Cabai Rawit Mengalami Kenaikan dan Lonjakan Signifikan');
        $this->assertSame('UP', $upRes['direction']);
        $this->assertSame('Indikasi Kenaikan', $upRes['label']);

        // 2. DOWN
        $downRes = $this->service->determinePriceDirection('Harga Bawang Merah Anjlok dan Murah Merosot Drastis');
        $this->assertSame('DOWN', $downRes['direction']);
        $this->assertSame('Indikasi Penurunan', $downRes['label']);

        // 3. STABLE
        $stableRes = $this->service->determinePriceDirection('Pasokan Beras di Pasar Induk Tetap Normal dan Terkendali Stabil');
        $this->assertSame('STABLE', $stableRes['direction']);
        $this->assertSame('Indikasi Stabil', $stableRes['label']);

        // 4. UNKNOWN / AMBIGUOUS
        $unkRes = $this->service->determinePriceDirection('Pemerintah Gelar Rapat Koordinasi Distribusi Pangan Nasional');
        $this->assertSame('UNKNOWN', $unkRes['direction']);
        $this->assertSame('Belum Dapat Dipastikan', $unkRes['label']);
    }

    /**
     * 4. inspect() bounds signals to max 5 items and prioritizes merchant matched items.
     */
    public function test_inspect_bounds_signals_and_prioritizes_matched_items(): void
    {
        // Create 2 merchant items: Cabai and Beras
        Item::create([
            'outlet_id'  => $this->outlet->id,
            'name'       => 'Cabai Rawit Merah',
            'sku'        => 'CBI-01',
            'cost_price' => 45000,
            'base_uom'   => 'GRAM',
            'is_active'  => true,
        ]);

        Item::create([
            'outlet_id'  => $this->outlet->id,
            'name'       => 'Beras Pandan Wangi',
            'sku'        => 'BRS-01',
            'cost_price' => 15000,
            'base_uom'   => 'GRAM',
            'is_active'  => true,
        ]);

        // Generate 8 RSS items: 2 matching, 6 unmatching
        $itemsXml = '';
        $itemsXml .= '<item><title>Harga Cabai Rawit Naik di Pasar - Media A</title><link>https://n.g/c1</link><description>Cabai mahal</description></item>';
        $itemsXml .= '<item><title>Harga Beras Mengalami Lonjakan - Media B</title><link>https://n.g/b1</link><description>Beras naik</description></item>';
        for ($i = 1; $i <= 6; $i++) {
            $itemsXml .= "<item><title>Harga Logam Tembaga Komoditas Tambang {$i} Berfluktuasi - Media {$i}</title><link>https://n.g/x{$i}</link><description>Tembaga dunia</description></item>";
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss version=\"2.0\"><channel>{$itemsXml}</channel></rss>";

        Http::fake([
            'https://news.google.com/*' => Http::response($xml, 200),
            'https://data.bmkg.go.id/*' => Http::response([], 200),
        ]);

        $inspect = $this->service->inspect($this->user->id, $this->outlet->id, 'COMMODITY');

        $this->assertSame('SUCCESS', $inspect['status']);
        $this->assertSame(8, $inspect['raw_signals_count']);
        $this->assertSame(2, $inspect['merchant_context_summary']['matched_items_count']);
        $this->assertSame(2, $inspect['merchant_context_summary']['relevant_signals_count']);

        // Assert signals array is bounded to max 5 items
        $this->assertLessThanOrEqual(5, count($inspect['signals']));

        // Assert the first 2 returned signals are the matched merchant items (Cabai and Beras)
        $this->assertNotEmpty($inspect['signals'][0]['matches']);
        $this->assertNotEmpty($inspect['signals'][1]['matches']);
        $this->assertSame('UP', $inspect['signals'][0]['price_direction']);
        $this->assertSame('UP', $inspect['signals'][1]['price_direction']);
    }
}
