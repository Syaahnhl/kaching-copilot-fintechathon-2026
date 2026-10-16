<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class KachiFallbackService
{
    /**
     * Generate a fallback response with dynamic recommendations.
     * 
     * @param string $reason The reason for fallback (timeout, circuit_breaker, error)
     * @param \Illuminate\Support\Collection|null $menus List of active menus
     */
    public static function getResponse(string $reason, $menus = null): array
    {
        // Try to get cached warm-up recommendations
        $recommendedNames = Cache::get('kachi_bestseller_cache', []);
        
        // If not cached, pick 3 random items if menus are provided
        if (empty($recommendedNames) && $menus && $menus->count() > 0) {
            $recommendedNames = $menus->random(min(3, $menus->count()))->pluck('name')->toArray();
        }

        $reply = "Waduh, koneksi Kachi agak tersendat nih 😅\n\n";
        $reply .= "Tapi tenang, Kachi rekomendasiin menu paling laku hari ini aja ya:\n";
        
        if (!empty($recommendedNames)) {
            foreach ($recommendedNames as $name) {
                $reply .= "- $name\n";
            }
            $reply .= "\nBisa langsung diklik buat dipesan!";
        } else {
            $reply .= "Silakan langsung pilih dari daftar menu di atas ya!";
        }

        return [
            'status' => 'success',
            'reply'  => $reply,
            'action_meta' => [
                'safe_mode' => true,
                'fallback_reason' => $reason
            ],
            'extracted_order' => [],
            'checkout_data' => null,
            'action_data' => null
        ];
    }
}
