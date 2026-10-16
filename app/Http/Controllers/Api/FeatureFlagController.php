<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * FeatureFlagController
 *
 * Restore 2026-07-13: file hilang tapi masih di-route (routes/api.php:219-220),
 * bikin `php artisan route:list` crash total (ReflectionException) + error log
 * production. Tabel `feature_flags` (kolom: key, enabled, description) tetap ada.
 *
 * Android (FeatureFlags.kt) memanggil GET /api/feature-flags dan mengharap
 * response berupa JSON map { "flag_key": true/false } (Retrofit: Map<String, Boolean>).
 * Jangan bungkus dengan resource/wrapper — shape harus flat map boolean.
 */
class FeatureFlagController extends Controller
{
    // GET /api/feature-flags  ->  { "discount_per_item": true, "multi_printer": false, ... }
    public function index()
    {
        $flags = DB::table('feature_flags')
            ->pluck('enabled', 'key')
            ->map(fn ($v) => (bool) $v);

        return response()->json($flags);
    }

    // GET /api/feature-flags/{key}  ->  { "key": "...", "enabled": bool, "description": "..." }
    public function show(string $key)
    {
        $flag = DB::table('feature_flags')->where('key', $key)->first();

        if (! $flag) {
            return response()->json(['message' => 'Feature flag not found'], 404);
        }

        return response()->json([
            'key' => $flag->key,
            'enabled' => (bool) $flag->enabled,
            'description' => $flag->description ?? null,
        ]);
    }
}
