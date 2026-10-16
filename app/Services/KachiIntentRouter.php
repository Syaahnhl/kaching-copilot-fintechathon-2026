<?php

namespace App\Services;

class KachiIntentRouter
{
    /**
     * Parses the user message to determine if it can be handled by deterministic rules
     * bypassing the AI.
     *
     * Returns an array:
     * [
     *     'routing_type' => 'rule_based_handled' | 'ai_called',
     *     'action_data'  => [...], // if rule_based
     *     'reply'        => string, // if rule_based
     *     'confidence'   => float
     * ]
     */
    public static function normalizeMessage(string $message): string
    {
        $msg = mb_strtolower(trim($message));
        
        // 1. Map common Indonesian word numbers to digits
        $wordNumbers = [
            'satu' => '1',
            'dua' => '2',
            'tiga' => '3',
            'empat' => '4',
            'lima' => '5',
            'enam' => '6',
            'tujuh' => '7',
            'delapan' => '8',
            'sembilan' => '9',
            'sepuluh' => '10'
        ];
        foreach ($wordNumbers as $word => $digit) {
            $msg = preg_replace('/\b' . preg_quote($word, '/') . '\b/u', $digit, $msg);
        }

        // 2. Map common Indonesian food slang/abbreviations to DB equivalents
        $slangMap = [
            'nasgor' => 'nasi goreng',
            'esteh' => 'es teh manis',
            'es teh' => 'es teh manis',
            'teh manis' => 'es teh manis',
            'mie' => 'mi',
            'juice' => 'jus',
            'co' => 'checkout',
        ];
        foreach ($slangMap as $slang => $std) {
            $msg = preg_replace('/\b' . preg_quote($slang, '/') . '\b/u', $std, $msg);
            // Also handle without word boundaries for things like "esteh"
            if (!str_contains($slang, ' ')) {
                $msg = str_replace($slang, $std, $msg);
            }
        }

        return $msg;
    }

    public static function parse(string $message, $menus)
    {
        $msg = self::normalizeMessage($message);

        // 1. Check if conversational (must bypass to AI)
        $convCheck = self::checkConversationalMessage($msg);
        if ($convCheck['is_conv']) {
            $route = self::routeToAi();
            $route['routing_reason'] = 'conversational_keyword_detected';
            $route['matched_keywords'] = $convCheck['matched_keywords'];
            return $route;
        }



        // Priority 2: Remove Item
        if (self::matchesPattern($msg, ['hapus', 'apus', 'ga jadi', 'coret', 'cancel', 'buang', 'ilangin'])) {
            $extractedItems = self::extractItemsWithQty($msg, $menus, true); // true = extract for removal (ignore qty)
            if (!empty($extractedItems)) {
                return self::routeRuleBased('remove_from_cart', $extractedItems, 'Siap, item sudah aku hapus dari pesanan ya.');
            }
        }

        // Priority 3: Update Qty / Add Item
        if (self::matchesPattern($msg, ['tambah', 'tambahin', 'pesen', 'orderin', 'pesan', 'mau', 'jadiin', 'kurangin'])) {
            $extractedItems = self::extractItemsWithQty($msg, $menus);
            if (!empty($extractedItems)) {
                // Determine if it's add or update. The frontend handles add and update similarly 
                // if we send it as extracted_order (add) or action_data->update_quantity.
                // Let's output it as add_to_cart for simplicity if it's 'tambah/pesen', or update_qty if 'jadi/kurangin'.
                $actionType = self::matchesPattern($msg, ['kurangin', 'jadiin']) ? 'update_quantity' : 'add_to_cart';
                $reply = $actionType === 'add_to_cart' 
                    ? 'Oke, sudah aku tambahkan ke pesanan kamu!' 
                    : 'Siap, jumlah pesanannya sudah aku update.';
                    
                return self::routeRuleBased($actionType, $extractedItems, $reply);
            }
        }



        // Priority 5: View Cart
        if (self::matchesPattern($msg, ['lihat pesanan', 'keranjangku', 'cek pesanan', 'lihat keranjang', 'pesananku apa aja'])) {
            return self::routeRuleBased('view_cart', [], 'Ini pesanan kamu saat ini. Ada yang mau ditambah atau diubah?');
        }

        // Fallback: AI Required
        return self::routeToAi();
    }

    private static function routeToAi()
    {
        return [
            'routing_type' => 'ai_called',
            'action_data'  => null,
            'reply'        => null,
            'confidence'   => 0.0
        ];
    }

    private static function routeRuleBased($action, $items, $reply)
    {
        return [
            'routing_type' => 'rule_based_handled',
            'action_data'  => [
                'action' => $action,
                'items' => $items,
                'action_id' => 'act_' . substr(md5(uniqid()), 0, 8),
                'requires_confirmation' => in_array($action, ['clear_cart', 'checkout']),
                'intent_confidence' => 1.0,
                'action_source' => 'rule_engine'
            ],
            'reply' => $reply,
            'confidence' => 1.0
        ];
    }

    private static function checkConversationalMessage(string $msg): array
    {
        $convKeywords = [
            'diet', 'vegan', 'rendah kalori', 'healthy', 'anabul', 'platter', 'mix platter',
            'rekomendasi', 'cocok', 'enaknya', 'pengen', 'mood', 'best seller', 'favorit',
            'manis', 'creamy', 'pedas', 'bingung', 'enak', 'bedanya', 'alergi', 'saran',
            'lapar', 'bantu', 'tanya', 'promo'
        ];
        
        $matched = [];
        foreach ($convKeywords as $kw) {
            if (str_contains($msg, $kw)) {
                $matched[] = $kw;
            }
        }

        return [
            'is_conv' => count($matched) > 0,
            'matched_keywords' => $matched
        ];
    }

    private static function matchesPattern(string $msg, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            // Use word boundary if possible, or simple str_contains
            if (preg_match('/\b' . preg_quote($pattern, '/') . '\b/i', $msg)) {
                return true;
            }
            if (str_contains($msg, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse quantity and item name. Example: "tambah 2 kopi aren"
     */
    private static function extractItemsWithQty(string $msg, $menus, bool $ignoreQty = false): array
    {
        $extracted = [];
        
        // Find numbers in the string
        preg_match_all('/\b(\d+)\b/', $msg, $matches, PREG_OFFSET_CAPTURE);
        
        // We will do a basic string matching against all menu names.
        // Sort menus by length descending to match longest names first (e.g., "Kopi Aren Latte" before "Kopi").
        $sortedMenus = $menus->sortByDesc(function ($m) {
            return strlen($m->name);
        });

        $matchedMenu = null;
        $matchedQty = 1;

        foreach ($sortedMenus as $menu) {
            $menuName = mb_strtolower($menu->name);
            // Quick check if menu is in the message
            if (str_contains($msg, $menuName)) {
                $matchedMenu = $menu;
                break;
            }
            // Check aliases/words (simplified Levenshtein or token matching)
            $tokens = explode(' ', $menuName);
            $matchCount = 0;
            foreach ($tokens as $token) {
                if (strlen($token) > 2 && str_contains($msg, $token)) {
                    $matchCount++;
                }
            }
            if ($matchCount > 0 && $matchCount >= count($tokens) * 0.5) {
                 $matchedMenu = $menu;
                 break;
            }
        }

        if ($matchedMenu) {
            if (!$ignoreQty && !empty($matches[1])) {
                // Just take the first number found as qty for simplicity in rule-based
                $matchedQty = intval($matches[1][0][0]);
            }
            $extracted[] = [
                'menu_name' => $matchedMenu->name,
                'qty' => max(1, min(50, $matchedQty)),
                'notes' => ''
            ];
        }

        return $extracted;
    }

    // ==========================================
    // PHASE 2 & 3: MENU RETRIEVAL ENGINE
    // ==========================================

    /**
     * Filters menu database using keyword scoring to save LLM tokens.
     * Returns top 10-15 relevant menus.
     */
    public static function getRelevantMenus(string $message, $menus)
    {
        $normalizedMsg = self::normalizeMessage($message);
        $msgTokens = array_filter(explode(' ', mb_strtolower(preg_replace('/[^a-z0-9\s]/', '', $normalizedMsg))));
        if (empty($msgTokens)) {
            // Fallback: Best sellers / Popular
            return $menus->take(12);
        }

        $scoredMenus = [];

        foreach ($menus as $menu) {
            $score = 0;
            $menuName = mb_strtolower($menu->name);
            $category = mb_strtolower($menu->category ?? '');
            $tags = is_array($menu->allergen_tags) ? implode(' ', $menu->allergen_tags) : mb_strtolower($menu->allergen_tags ?? '');

            foreach ($msgTokens as $token) {
                if (strlen($token) < 3) continue;

                // Exact match in name: +50
                if (str_contains($menuName, $token)) {
                    $score += 50;
                }
                
                // Category match: +30
                if (str_contains($category, $token)) {
                    $score += 30;
                }

                // Tag match: +20
                if (str_contains($tags, $token)) {
                    $score += 20;
                }
            }

            // Popularity/Best seller boost (dummy logic for now: assume early IDs are older/popular, or random)
            // In a real app, you'd use order counts.
            $score += rand(1, 5); // small random noise to rotate fallbacks if scores tie

            $scoredMenus[] = [
                'menu' => $menu,
                'score' => $score
            ];
        }

        // Sort by score descending
        usort($scoredMenus, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Filter out 0 scores if there are high scores, otherwise return top N as fallback
        $topMenus = collect($scoredMenus)->take(12)->map(function($item) {
            return $item['menu'];
        });

        return $topMenus;
    }
}
