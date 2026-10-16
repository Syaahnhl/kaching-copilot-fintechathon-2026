<?php

namespace App\Services\Radar;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\Outlet;
use App\Models\Menu;
use App\Models\Item;
use App\Models\Expense;

class ExternalBusinessRadarService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const SOURCE_COMMODITY_RSS = 'https://news.google.com/rss/search?q=harga+pangan+OR+harga+cabai+OR+harga+beras+OR+harga+minyak+goreng+OR+harga+telur+OR+harga+ayam&hl=id&gl=ID&ceid=ID:id';
    public const SOURCE_WEATHER_RSS   = 'https://news.google.com/rss/search?q=cuaca+ekstrem+banjir+OR+peringatan+BMKG&hl=id&gl=ID&ceid=ID:id';
    public const SOURCE_OPERATIONAL_RSS = 'https://news.google.com/rss/search?q=tarif+listrik+OR+harga+BBM+OR+upah+minimum+UMR&hl=id&gl=ID&ceid=ID:id';
    public const SOURCE_BMKG_JSON     = 'https://data.bmkg.go.id/DataMKG/TEWS/autogempa.json';

    public const CACHE_PREFIX = 'kaching:radar:feed:';
    public const TTL_COMMODITY = 14400; // 4 hours
    public const TTL_WEATHER   = 7200;  // 2 hours
    public const TTL_STALE     = 172800; // 48 hours

    public const HTTP_TIMEOUT = 4; // seconds

    /**
     * Commodity synonym / normalization dictionary (small & explicit).
     */
    protected array $commoditySynonyms = [
        'cabai'         => ['cabai', 'cabe', 'lombok', 'chili'],
        'beras'         => ['beras', 'padi', 'nasi'],
        'minyak goreng' => ['minyak goreng', 'minyak sawit', 'minyak kelapa', 'minyak'],
        'telur'         => ['telur', 'telor'],
        'ayam'          => ['ayam', 'daging ayam', 'broiler'],
        'bawang'        => ['bawang', 'bawang merah', 'bawang putih', 'brambang'],
        'daging'        => ['daging', 'daging sapi', 'beef'],
        'kopi'          => ['kopi', 'coffee', 'espresso'],
        'gula'          => ['gula', 'gula pasir'],
        'tepung'        => ['tepung', 'terigu'],
    ];

    /**
     * Operational expense keywords.
     */
    protected array $operationalKeywords = [
        'listrik' => ['listrik', 'tarif listrik', 'pln'],
        'bbm'     => ['bbm', 'solar', 'pertalite', 'pertamax', 'bensin', 'bahan bakar'],
        'gaji'    => ['umr', 'upah', 'gaji', 'upah minimum'],
    ];

    /**
     * Inspect external business radar signals and link to merchant context.
     *
     * @param int $userId Tenant user ID
     * @param int|null $outletId Specific outlet ID (optional)
     * @param string $category Signal category: ALL|COMMODITY|WEATHER|OPERATIONAL
     * @return array Structured radar payload
     */
    public function inspect(int $userId, ?int $outletId = null, string $category = 'ALL'): array
    {
        $category = strtoupper(trim($category));
        if (!in_array($category, ['ALL', 'COMMODITY', 'WEATHER', 'OPERATIONAL'])) {
            $category = 'ALL';
        }

        // 1. Fetch sanitized and deduplicated external signals
        $feedResult = $this->fetchSignals($category);
        $rawSignals = $feedResult['signals'] ?? [];
        $status     = $feedResult['status'] ?? 'SUCCESS';
        $quality    = $feedResult['data_quality'] ?? 'VERIFIED_PUBLIC_SOURCE';
        $freshness  = $feedResult['overall_freshness'] ?? 'FRESH';

        if (empty($rawSignals)) {
            return [
                'status'            => $status === 'SUCCESS' ? 'NO_EXTERNAL_SIGNALS' : $status,
                'signal_category'   => $category,
                'data_quality'      => $quality,
                'overall_freshness' => $freshness,
                'retrieved_at'      => Carbon::now(self::TIMEZONE)->toIso8601String(),
                'signals_count'     => 0,
                'signals'           => [],
                'merchant_context_summary' => [
                    'user_id'             => $userId,
                    'outlet_id'           => $outletId,
                    'matched_items_count' => 0,
                    'matched_menus_count' => 0,
                ],
                'potential_financial_impact_note' => 'Tidak ada sinyal eksternal aktif yang terdeteksi saat ini.',
            ];
        }

        // 2. Perform relevance matching against merchant context
        $matchedSignals = $this->matchMerchantContext($rawSignals, $userId, $outletId);

        // 3. Summarize merchant context matches
        $matchedItemNames = [];
        $matchedMenuNames = [];
        $signalsWithMatches = [];
        $signalsWithoutMatches = [];

        foreach ($matchedSignals as $sig) {
            $hasMatch = false;
            foreach ($sig['matches'] as $m) {
                $hasMatch = true;
                if ($m['match_type'] === 'RAW_MATERIAL_TIER_1') {
                    $matchedItemNames[] = $m['matched_entity'];
                }
                if (!empty($m['affected_menus'])) {
                    foreach ($m['affected_menus'] as $mn) {
                        $matchedMenuNames[] = $mn;
                    }
                }
            }
            if ($hasMatch) {
                $signalsWithMatches[] = $sig;
            } else {
                $signalsWithoutMatches[] = $sig;
            }
        }
        $matchedItemNames = array_values(array_unique($matchedItemNames));
        $matchedMenuNames = array_values(array_unique($matchedMenuNames));

        // Prioritize matched signals and bound LLM context to top 5 signals
        $prioritizedSignals = array_merge($signalsWithMatches, $signalsWithoutMatches);
        $boundedSignals = array_slice($prioritizedSignals, 0, 5);

        return [
            'status'            => $status,
            'signal_category'   => $category,
            'data_quality'      => $quality,
            'overall_freshness' => $freshness,
            'retrieved_at'      => Carbon::now(self::TIMEZONE)->toIso8601String(),
            'raw_signals_count' => count($rawSignals),
            'signals_count'     => count($rawSignals),
            'relevant_signals_count' => count($signalsWithMatches),
            'signals'           => $boundedSignals,
            'merchant_context_summary' => [
                'user_id'                => $userId,
                'outlet_id'              => $outletId,
                'raw_signals_count'      => count($rawSignals),
                'matched_items_count'    => count($matchedItemNames),
                'matched_menus_count'    => count($matchedMenuNames),
                'relevant_signals_count' => count($signalsWithMatches),
                'sample_matched_items'   => array_slice($matchedItemNames, 0, 5),
                'sample_matched_menus'   => array_slice($matchedMenuNames, 0, 5),
            ],
            'potential_financial_impact_note' => 'Radar eksternal menyajikan indikasi kualitatif dan keterpaparan bahan baku dari media publik. Perubahan harga bersifat indikatif dan belum dapat dipastikan di tingkat outlet sebelum ada konfirmasi faktur supplier aktual. Untuk mensimulasikan dampak kuantitatif terhadap margin atau laba, gunakan tool get_financial_scenario dengan skenario COST_CHANGE.',
        ];
    }

    /**
     * Fetch external signals safely using cache and fallback.
     */
    public function fetchSignals(string $category = 'ALL'): array
    {
        $cacheKey = self::CACHE_PREFIX . $category;
        $staleCacheKey = $cacheKey . ':stale';

        // 1. Fresh Cache Hit
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['signals'])) {
                return $cached;
            }
        }

        // 2. Fetch live data with safe timeout and retry
        try {
            $signals = $this->fetchFromLiveSources($category);

            if (!empty($signals)) {
                $payload = [
                    'status'            => 'SUCCESS',
                    'data_quality'      => 'VERIFIED_PUBLIC_SOURCE',
                    'overall_freshness' => 'FRESH',
                    'signals'           => $signals,
                ];

                $ttl = ($category === 'WEATHER') ? self::TTL_WEATHER : self::TTL_COMMODITY;
                Cache::put($cacheKey, $payload, $ttl);
                Cache::put($staleCacheKey, $payload, self::TTL_STALE);

                return $payload;
            }
        } catch (\Throwable $e) {
            Log::warning("ExternalBusinessRadarService: live fetch failed", [
                'category' => $category,
                'error'    => $e->getMessage(),
            ]);
        }

        // 3. Stale Fallback Cache
        if (Cache::has($staleCacheKey)) {
            $stale = Cache::get($staleCacheKey);
            $staleList = isset($stale['signals']) && is_array($stale['signals']) ? $stale['signals'] : (is_array($stale) ? $stale : []);
            if (!empty($staleList)) {
                $staleSignals = array_map(function ($sig) {
                    $sig['freshness_status'] = 'STALE_FALLBACK';
                    $sig['data_quality'] = 'STALE_FALLBACK';
                    return $sig;
                }, $staleList);

                return [
                    'status'            => 'SUCCESS',
                    'data_quality'      => 'STALE_FALLBACK',
                    'overall_freshness' => 'STALE_FALLBACK',
                    'signals'           => $staleSignals,
                ];
            }
        }

        // 4. Provider failure with no cache
        return [
            'status'            => 'PROVIDER_UNAVAILABLE',
            'data_quality'      => 'PROVIDER_UNAVAILABLE',
            'overall_freshness' => 'UNKNOWN',
            'signals'           => [],
        ];
    }

    /**
     * Dispatch live fetch based on requested category.
     */
    protected function fetchFromLiveSources(string $category): array
    {
        $signals = [];
        $seenHashes = [];

        if ($category === 'ALL' || $category === 'COMMODITY') {
            $commodityXml = $this->executeHttpGet(self::SOURCE_COMMODITY_RSS);
            if ($commodityXml) {
                $parsed = $this->parseRssFeed($commodityXml, 'COMMODITY_PRICE');
                $this->appendDeduplicated($signals, $parsed, $seenHashes);
            }
        }

        if ($category === 'ALL' || $category === 'WEATHER') {
            $weatherXml = $this->executeHttpGet(self::SOURCE_WEATHER_RSS);
            if ($weatherXml) {
                $parsed = $this->parseRssFeed($weatherXml, 'WEATHER_DISRUPTION');
                $this->appendDeduplicated($signals, $parsed, $seenHashes);
            }

            $bmkgJson = $this->executeHttpGet(self::SOURCE_BMKG_JSON);
            if ($bmkgJson) {
                $decoded = json_decode($bmkgJson, true);
                if (is_array($decoded)) {
                    $parsed = $this->parseBmkgFeed($decoded);
                    $this->appendDeduplicated($signals, $parsed, $seenHashes);
                }
            }
        }

        if ($category === 'ALL' || $category === 'OPERATIONAL') {
            $opXml = $this->executeHttpGet(self::SOURCE_OPERATIONAL_RSS);
            if ($opXml) {
                $parsed = $this->parseRssFeed($opXml, 'OPERATIONAL_COST');
                $this->appendDeduplicated($signals, $parsed, $seenHashes);
            }
        }

        return $signals;
    }

    /**
     * Execute safe HTTP GET with timeout, limited retry, and strictly fixed URL.
     */
    protected function executeHttpGet(string $url): ?string
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->retry(1, 100)
                ->get($url);

            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Throwable $e) {
            Log::info("ExternalBusinessRadarService: HTTP call error on {$url}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Parse Google News RSS feed tolerantly.
     */
    public function parseRssFeed(string $xmlContent, string $signalType = 'COMMODITY_PRICE'): array
    {
        $signals = [];

        if (trim($xmlContent) === '') {
            return [];
        }

        // Suppress XML parsing errors safely
        $prevEntityLoader = libxml_disable_entity_loader(true);
        $prevErrors = libxml_use_internal_errors(true);

        $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);
        libxml_disable_entity_loader($prevEntityLoader);

        if ($xml === false || !isset($xml->channel->item)) {
            return [];
        }

        $nowIso = Carbon::now(self::TIMEZONE)->toIso8601String();

        foreach ($xml->channel->item as $item) {
            $rawTitle = (string) ($item->title ?? '');
            $rawLink  = (string) ($item->link ?? '');

            // Drop signal if required fields are missing
            if (trim($rawTitle) === '' || trim($rawLink) === '') {
                continue;
            }

            // Extract source name and URL
            $sourceName = '';
            $sourceUrl  = '';
            if (isset($item->source)) {
                $sourceName = (string) $item->source;
                $sourceUrl  = (string) ($item->source['url'] ?? '');
            }

            // Fallback source name from title if suffix exists (e.g. "... - Antara News")
            if ($sourceName === '' && str_contains($rawTitle, ' - ')) {
                $parts = explode(' - ', $rawTitle);
                $sourceName = trim(end($parts));
            }
            if ($sourceName === '') {
                $sourceName = 'Google News RSS';
            }

            // Extract published_at date
            $rawPubDate = (string) ($item->pubDate ?? '');
            $publishedAt = null;
            if ($rawPubDate !== '') {
                try {
                    $publishedAt = Carbon::parse($rawPubDate)->toIso8601String();
                } catch (\Throwable $e) {
                    $publishedAt = null;
                }
            }

            // Extract description / summary
            $rawDesc = (string) ($item->description ?? '');

            // Sanitize untrusted content (Layer 1 defense)
            $headline = $this->sanitizeText($rawTitle, 200);
            $summary  = $this->sanitizeText($rawDesc, 400);

            // Extract clean title without media suffix (e.g. "... - Antara News") for cross-source dedup
            $cleanTitle = preg_replace('/\s*-\s*[^-]+$/u', '', $headline);
            $cleanTitle = trim($cleanTitle) ?: $headline;
            $normTitleAlpha = preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower(trim($cleanTitle)));
            $normTitleAlpha = preg_replace('/\s+/u', ' ', $normTitleAlpha);
            $titleDedupHash = hash('sha256', trim($normTitleAlpha));

            // Deduplication content hash: sha256(canonical_url + "|" + normalized_title)
            $canonicalUrl = trim($sourceUrl ?: $rawLink);
            $normalizedTitle = mb_strtolower(trim($headline));
            $contentHash = hash('sha256', $canonicalUrl . '|' . $normalizedTitle);

            // Price direction analysis (UP, DOWN, STABLE, UNKNOWN)
            $directionAnalysis = $this->determinePriceDirection($headline . ' ' . $summary);

            // Freshness classification
            $freshness = $this->calculateFreshness($publishedAt);

            // Detect prompt injection attempts (SAFE vs SUSPICIOUS)
            $securityFlag = $this->detectPromptInjection($headline . ' ' . $summary);

            $signals[] = [
                'signal_id'             => 'sig_' . substr($contentHash, 0, 12),
                'external_signal_type'  => $signalType,
                'headline'              => $headline,
                'summary'               => $summary,
                'source_name'           => $sourceName,
                'source_url'            => $canonicalUrl,
                'source_classification' => 'NEWS_AGGREGATOR',
                'published_at'          => $publishedAt,
                'retrieved_at'          => $nowIso,
                'freshness_status'      => $freshness,
                'content_hash'          => $contentHash,
                'dedup_hash'            => $titleDedupHash,
                'price_direction'       => $directionAnalysis['direction'],
                'trend_label'           => $directionAnalysis['label'],
                'content_security_flag' => $securityFlag,
                'matches'               => [],
                'potential_relevance'   => [],
                'data_quality'          => 'VERIFIED_PUBLIC_SOURCE',
                'limitations'           => [
                    'External price movements do not immediately alter merchant supplier contract prices.',
                    'Signal reflects broader public reporting, unverified against specific merchant supplier invoices.',
                ],
            ];
        }

        return $signals;
    }

    /**
     * Parse BMKG public autogempa JSON feed.
     */
    public function parseBmkgFeed(array $jsonContent): array
    {
        $signals = [];
        $gempa = $jsonContent['Infogempa']['gempa'] ?? null;

        if (is_array($gempa)) {
            $tanggal = $gempa['Tanggal'] ?? '';
            $jam     = $gempa['Jam'] ?? '';
            $wilayah = $gempa['Wilayah'] ?? '';
            $mag     = $gempa['Magnitude'] ?? '';
            $potensi = $gempa['Potensi'] ?? '';
            $kedalaman = $gempa['Kedalaman'] ?? '';
            $dirasakan = $gempa['Dirasakan'] ?? '-';

            $rawHeadline = "Peringatan Gempa BMKG: M {$mag} - {$wilayah}";
            $rawSummary  = "Kedalaman: {$kedalaman}. Potensi: {$potensi}. Dirasakan: {$dirasakan}. Waktu: {$tanggal} {$jam}.";

            $headline = $this->sanitizeText($rawHeadline, 200);
            $summary  = $this->sanitizeText($rawSummary, 400);

            $canonicalUrl = self::SOURCE_BMKG_JSON;
            $normalizedTitle = mb_strtolower(trim($headline));
            $contentHash = hash('sha256', $canonicalUrl . '|' . $normalizedTitle);

            $publishedAt = null;
            if (isset($gempa['DateTime'])) {
                try {
                    $publishedAt = Carbon::parse($gempa['DateTime'])->toIso8601String();
                } catch (\Throwable $e) {
                    $publishedAt = null;
                }
            }

            $signals[] = [
                'signal_id'             => 'sig_' . substr($contentHash, 0, 12),
                'external_signal_type'  => 'DISASTER_ALERT',
                'headline'              => $headline,
                'summary'               => $summary,
                'source_name'           => 'BMKG Open Data',
                'source_url'            => $canonicalUrl,
                'source_classification' => 'GOVERNMENT_OFFICIAL',
                'published_at'          => $publishedAt,
                'retrieved_at'          => Carbon::now(self::TIMEZONE)->toIso8601String(),
                'freshness_status'      => $this->calculateFreshness($publishedAt),
                'content_hash'          => $contentHash,
                'content_security_flag' => $this->detectPromptInjection($headline . ' ' . $summary),
                'data_quality'          => 'VERIFIED_PUBLIC_SOURCE',
                'matches'               => [],
                'potential_relevance'   => [],
                'limitations'           => [
                    'Peringatan bencana/cuaca bersumber dari BMKG pusat dan mencakup parameter seismik/regional.',
                    'Kondisi riil operasional toko bergantung pada jarak dan dampak lokal di sekitar outlet.',
                ],
            ];
        }

        return $signals;
    }

    /**
     * Relevance matching of external signals against merchant context.
     * Takes $outletId as optional integer or null for strict tenant isolation.
     */
    public function matchMerchantContext(array $signals, int $userId, $outletId = null): array
    {
        // 0. Resolve authorized outlets for tenant
        $outletQuery = Outlet::where('user_id', $userId);
        if ($outletId !== null) {
            $outletId = (int) $outletId;
            $outletQuery->where('id', $outletId);
        }
        $outlets = $outletQuery->get();

        if ($outletId !== null && $outlets->isEmpty()) {
            throw new \InvalidArgumentException("Unauthorized outlet access or outlet does not belong to user.");
        }

        $outletIds = $outlets->pluck('id')->toArray();

        // 1. Fetch merchant's active Items (Bahan Baku)
        $items = Item::whereIn('outlet_id', $outletIds)
            ->where('is_active', true)
            ->with(['menus' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }])
            ->get();

        // 2. Fetch merchant's Menus
        $menuQuery = Menu::where('user_id', $userId);
        if ($outletId !== null) {
            $menuQuery->where('outlet_id', $outletId);
        }
        $menus = $menuQuery->get();

        // 3. Fetch merchant's Expense categories
        $expenseQuery = Expense::where('user_id', $userId);
        if ($outletId !== null) {
            $expenseQuery->where('outlet_id', $outletId);
        } elseif (!empty($outletIds)) {
            $expenseQuery->whereIn('outlet_id', $outletIds);
        }
        $expenses = $expenseQuery->select('category', 'name')
            ->distinct()
            ->limit(50)
            ->get();

        // 4. Perform Heuristic Matching per signal
        $matchedSignals = [];
        foreach ($signals as $signal) {
            $headline = $signal['headline'] ?? '';
            $summary  = $signal['summary'] ?? '';
            $combinedText = mb_strtolower($headline . ' ' . $summary);
            $sigType = $signal['external_signal_type'] ?? 'COMMODITY_PRICE';

            $matches = [];
            $potentialNotes = [];

            // Tier 1: Item.name Direct Match (Raw Material - Weight 1.0)
            if ($sigType === 'COMMODITY_PRICE') {
                foreach ($items as $item) {
                    $normalizedItem = mb_strtolower($item->name);
                    $canonicalCommodity = $this->findMatchingCommodity($normalizedItem);

                    if ($canonicalCommodity && $this->textContainsCommodity($combinedText, $canonicalCommodity)) {
                        $affectedMenuNames = $item->menus->pluck('name')->toArray();
                        if (empty($affectedMenuNames)) {
                            // Check if any menu name contains the item name
                            foreach ($menus as $m) {
                                if (str_contains(mb_strtolower($m->name), $normalizedItem)) {
                                    $affectedMenuNames[] = $m->name;
                                }
                            }
                        }

                        $matches[] = [
                            'match_type'                 => 'RAW_MATERIAL_TIER_1',
                            'matched_entity'             => 'Item: ' . $item->name,
                            'heuristic_relevance_weight' => 1.0,
                            'affected_menus'             => array_values(array_unique($affectedMenuNames)),
                            'relevance_reason'           => "Bahan baku '{$item->name}' digunakan pada menu: " . (!empty($affectedMenuNames) ? implode(', ', $affectedMenuNames) : 'Inventori bahan'),
                        ];

                        $potentialNotes[] = "Sinyal harga {$canonicalCommodity} berpotensi mempengaruhi biaya bahan baku '{$item->name}' jika supplier merchant menyesuaikan harga faktur.";
                    }
                }
            }

            // Tier 2: Menu.name / Menu.category Direct Match (Fallback - Weight 0.7)
            if ($sigType === 'COMMODITY_PRICE' && empty($matches)) {
                foreach ($menus as $menu) {
                    $menuNameNorm = mb_strtolower($menu->name);
                    $categoryNorm = mb_strtolower((string) $menu->category);

                    foreach ($this->commoditySynonyms as $canonical => $synonyms) {
                        $menuMatches = false;
                        foreach ($synonyms as $syn) {
                            if (str_contains($menuNameNorm, $syn) || str_contains($categoryNorm, $syn)) {
                                $menuMatches = true;
                                break;
                            }
                        }

                        if ($menuMatches && $this->textContainsCommodity($combinedText, $canonical)) {
                            $matches[] = [
                                'match_type'                 => 'MENU_NAME_TIER_2',
                                'matched_entity'             => 'Menu: ' . $menu->name,
                                'heuristic_relevance_weight' => 0.7,
                                'affected_menus'             => [$menu->name],
                                'relevance_reason'           => "Menu '{$menu->name}' berkaitan dengan komoditas {$canonical} yang dilaporkan dalam berita.",
                            ];

                            $potentialNotes[] = "Menu '{$menu->name}' berpotensi terpapar jika harga bahan baku {$canonical} di pasar grosir berdampak ke modal porsi.";
                            break;
                        }
                    }
                }
            }

            // Tier 3: Expense Category / Operational Keywords Match (Weight 0.5)
            if ($sigType === 'OPERATIONAL_COST') {
                foreach ($this->operationalKeywords as $opType => $keywords) {
                    $signalHasOp = false;
                    foreach ($keywords as $kw) {
                        if (str_contains($combinedText, $kw)) {
                            $signalHasOp = true;
                            break;
                        }
                    }

                    if ($signalHasOp) {
                        $matchedExpenseCat = null;
                        foreach ($expenses as $exp) {
                            $expText = mb_strtolower($exp->category . ' ' . $exp->name);
                            foreach ($keywords as $kw) {
                                if (str_contains($expText, $kw) || str_contains($expText, 'operasional')) {
                                    $matchedExpenseCat = $exp->category ?: $exp->name;
                                    break 2;
                                }
                            }
                        }

                        $matches[] = [
                            'match_type'                 => 'EXPENSE_CATEGORY_TIER_3',
                            'matched_entity'             => 'Expense Category: ' . ($matchedExpenseCat ?: 'Beban Operasional ' . ucfirst($opType)),
                            'heuristic_relevance_weight' => 0.5,
                            'affected_menus'             => [],
                            'relevance_reason'           => "Perubahan regulasi/tarif {$opType} berpotensi mempengaruhi pos pengeluaran operasional toko.",
                        ];

                        $potentialNotes[] = "Sinyal tarif {$opType} berpotensi mempengaruhi pengeluaran operasional merchant.";
                        break;
                    }
                }
            }

            // Tier 4: Geographic Location Match (Weight 0.4)
            if ($sigType === 'WEATHER_DISRUPTION' || $sigType === 'DISASTER_ALERT') {
                foreach ($outlets as $outlet) {
                    $addressNorm = mb_strtolower((string) $outlet->address);
                    $outletNameNorm = mb_strtolower($outlet->name);

                    // Check if headline/summary mentions outlet city/region
                    $geoMatched = false;
                    $matchedRegion = '';

                    // Extract notable region tokens (> 3 chars)
                    $addressTokens = preg_split('/[\s,\.\-]+/u', $addressNorm);
                    foreach ($addressTokens as $tok) {
                        if (mb_strlen($tok) >= 4 && str_contains($combinedText, $tok)) {
                            $geoMatched = true;
                            $matchedRegion = $tok;
                            break;
                        }
                    }

                    if ($geoMatched) {
                        $matches[] = [
                            'match_type'                 => 'GEOGRAPHIC_TIER_4',
                            'matched_entity'             => 'Outlet: ' . $outlet->name . ' (' . ucfirst($matchedRegion) . ')',
                            'heuristic_relevance_weight' => 0.4,
                            'affected_menus'             => [],
                            'relevance_reason'           => "Peringatan cuaca/lingkungan di wilayah " . ucfirst($matchedRegion) . " terdeteksi pada area outlet '{$outlet->name}'.",
                        ];

                        $potentialNotes[] = "Cuaca ekstrem di wilayah outlet berpotensi mempengaruhi kunjungan dine-in pelanggan.";
                        break;
                    }
                }
            }

            // If no match found, output explicit note
            if (empty($matches)) {
                $potentialNotes[] = 'Tidak ada bahan baku atau menu merchant yang langsung terhubung dengan sinyal ini.';
            }

            $signal['matches'] = $matches;
            $signal['potential_relevance'] = array_values(array_unique($potentialNotes));

            $matchedSignals[] = $signal;
        }

        return $matchedSignals;
    }

    /**
     * Find canonical commodity keyword for a given text.
     */
    protected function findMatchingCommodity(string $text): ?string
    {
        foreach ($this->commoditySynonyms as $canonical => $synonyms) {
            foreach ($synonyms as $syn) {
                if (str_contains($text, $syn)) {
                    return $canonical;
                }
            }
        }
        return null;
    }

    /**
     * Check if text contains any synonym of a canonical commodity.
     */
    protected function textContainsCommodity(string $text, string $canonical): bool
    {
        $synonyms = $this->commoditySynonyms[$canonical] ?? [$canonical];
        foreach ($synonyms as $syn) {
            if (str_contains($text, $syn)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize commodity and operational keywords from text.
     */
    public function normalizeKeywords(string $text): array
    {
        $textNorm = mb_strtolower($text);
        $found = [];

        foreach ($this->commoditySynonyms as $canonical => $synonyms) {
            foreach ($synonyms as $syn) {
                if (str_contains($textNorm, $syn)) {
                    $found[] = $canonical;
                    break;
                }
            }
        }

        foreach ($this->operationalKeywords as $canonical => $synonyms) {
            foreach ($synonyms as $syn) {
                if (str_contains($textNorm, $syn)) {
                    $found[] = $canonical;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Sanitize external text: strip control chars, null bytes, HTML/script tags, and limit length.
     * Preserves semantic text without destructive replacement.
     */
    public function sanitizeText(string $text, int $maxLength = 400): string
    {
        // 1. Remove null bytes and control characters
        $clean = str_replace("\0", '', $text);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);

        // 2. Replace HTML tags with spaces to prevent words from running together, then strip tags
        $clean = preg_replace('/<[^>]*>/u', ' ', $clean);
        $clean = strip_tags($clean);

        // 3. Decode HTML entities
        $clean = htmlspecialchars_decode($clean, ENT_QUOTES | ENT_HTML5);

        // 4. Normalize multiple whitespaces
        $clean = preg_replace('/\s+/u', ' ', trim($clean));

        // 5. Cap maximum length safely
        if (mb_strlen($clean) > $maxLength) {
            $clean = mb_substr($clean, 0, $maxLength);
        }

        return $clean;
    }

    /**
     * Prompt injection detection (SAFE vs SUSPICIOUS).
     * Does NOT alter the underlying text to prevent corrupting sources.
     */
    public function detectPromptInjection(string $text): string
    {
        $suspiciousPatterns = [
            '/(ignore\s+(all\s+)?previous\s+instructions?)/i',
            '/(system\s*:|assistant\s*:|instruction\s*:|developer\s*:)/i',
            '/(you\s+are\s+now\s+in\s+developer\s+mode)/i',
            '/(bypass\s+all\s+rules)/i',
            '/(call\s+tool\s+[a-z_]+)/i',
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return 'SUSPICIOUS';
            }
        }

        return 'SAFE';
    }

    /**
     * Calculate freshness status based on publication timestamp.
     */
    public function calculateFreshness(?string $publishedAt, bool $isStaleFallback = false): string
    {
        if ($isStaleFallback) {
            return 'STALE_FALLBACK';
        }

        if ($publishedAt === null || trim($publishedAt) === '') {
            return 'RECENT';
        }

        try {
            $pub = Carbon::parse($publishedAt);
            $hours = $pub->diffInHours(Carbon::now(self::TIMEZONE));

            if ($hours < 24) {
                return 'FRESH';
            } elseif ($hours <= 72) {
                return 'RECENT';
            } else {
                return 'STALE';
            }
        } catch (\Throwable $e) {
            return 'RECENT';
        }
    }

    /**
     * Tentukan arah pergerakan harga/tren dari teks berita (UP, DOWN, STABLE, UNKNOWN).
     *
     * @param string $text
     * @return array [ 'direction' => string, 'label' => string ]
     */
    public function determinePriceDirection(string $text): array
    {
        $textLower = mb_strtolower($text);

        $upKeywords = [
            'naik', 'lonjakan', 'meroket', 'mahal', 'terkerek', 'inflasi',
            'kenaikan', 'melonjak', 'menguat', 'tinggi', 'melambung', 'terbang',
        ];

        $downKeywords = [
            'turun', 'anjlok', 'merosot', 'murah', 'deflasi', 'penurunan',
            'melemah', 'anjlok', 'stabil rendah', 'landai', 'turun drastis',
        ];

        $stableKeywords = [
            'stabil', 'normal', 'tetap', 'terkendali', 'kondusif', 'aman',
        ];

        $hasUp = false;
        foreach ($upKeywords as $kw) {
            if (str_contains($textLower, $kw)) {
                $hasUp = true;
                break;
            }
        }

        $hasDown = false;
        foreach ($downKeywords as $kw) {
            if (str_contains($textLower, $kw)) {
                $hasDown = true;
                break;
            }
        }

        $hasStable = false;
        foreach ($stableKeywords as $kw) {
            if (str_contains($textLower, $kw)) {
                $hasStable = true;
                break;
            }
        }

        if ($hasUp && !$hasDown) {
            return [
                'direction' => 'UP',
                'label'     => 'Indikasi Kenaikan',
            ];
        }

        if ($hasDown && !$hasUp) {
            return [
                'direction' => 'DOWN',
                'label'     => 'Indikasi Penurunan',
            ];
        }

        if ($hasStable && !$hasUp && !$hasDown) {
            return [
                'direction' => 'STABLE',
                'label'     => 'Indikasi Stabil',
            ];
        }

        return [
            'direction' => 'UNKNOWN',
            'label'     => 'Belum Dapat Dipastikan',
        ];
    }

    /**
     * Append unique signals by normalized title or content hash.
     */
    protected function appendDeduplicated(array &$targetList, array $newSignals, array &$seenHashes): void
    {
        foreach ($newSignals as $sig) {
            $hash = $sig['dedup_hash'] ?? $sig['content_hash'] ?? null;
            if ($hash && !isset($seenHashes[$hash])) {
                $seenHashes[$hash] = true;
                $targetList[] = $sig;
            }
        }
    }
}
