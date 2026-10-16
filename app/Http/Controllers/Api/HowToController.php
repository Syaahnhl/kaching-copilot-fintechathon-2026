<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HowToArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HowToController extends Controller
{
    /**
     * GET /api/how-to
     *
     * Query params:
     *   - category (opsional): filter by category (kasir, shift, stok, kds, offline, umum)
     *   - search (opsional): search in title + content
     *   - limit (opsional, default 50, max 100)
     *
     * Response:
     *   {
     *     "status": "success",
     *     "data": [
     *       {
     *         "id": 1,
     *         "title": "Lupa PIN Kasir?",
     *         "slug": "lupa-pin-kasir",
     *         "category": "kasir",
     *         "icon": "lock_reset",
     *         "content": "**Cara reset PIN...**",
     *         "sort_order": 10,
     *         "view_count": 0,
     *         "created_at": "2026-06-18T12:00:00.000000Z"
     *       }
     *     ]
     *   }
     */
    public function index(Request $request): JsonResponse
    {
        $query = HowToArticle::active()->ordered();

        if ($category = $request->query('category')) {
            $query->byCategory($category);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                  ->orWhere('content', 'like', '%' . $search . '%');
            });
        }

        $limit = min((int) $request->query('limit', 50), 100);
        $articles = $query->limit($limit)->get();

        return response()->json([
            'status' => 'success',
            'count' => $articles->count(),
            'data' => $articles,
        ]);
    }

    /**
     * GET /api/how-to/{slug}
     *
     * Increment view_count otomatis.
     */
    public function show(string $slug): JsonResponse
    {
        $article = HowToArticle::active()->where('slug', $slug)->first();

        if (!$article) {
            return response()->json([
                'status' => 'error',
                'message' => 'Artikel tidak ditemukan',
            ], 404);
        }

        $article->incrementViews();

        return response()->json([
            'status' => 'success',
            'data' => $article,
        ]);
    }
}
