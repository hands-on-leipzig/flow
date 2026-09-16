<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MSeason;
use App\Services\EventSlugService;
use App\Services\PublicEventLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public links of a season for callers that only know DRAHT ids, JOIN above all.
 *
 * Nothing here is secret — the links are meant to be shared — so the endpoint needs no
 * key. It is cached for a few minutes because a public venue list asks for it on every
 * visit while slugs change rarely.
 */
class PublicEventLinkController extends Controller
{
    public function __construct(
        private readonly PublicEventLinkService $links,
        private readonly EventSlugService $slugs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'season' => 'sometimes|integer|min:1',
            'year' => 'sometimes|integer|min:2000|max:2100',
        ]);

        if (isset($validated['season'])) {
            $seasonId = (int) $validated['season'];
        } elseif (isset($validated['year'])) {
            $seasonId = $this->slugs->seasonIdForYear((int) $validated['year']);
        } else {
            $seasonId = $this->slugs->currentSeasonId();

            if ($seasonId === null) {
                return response()->json(['error' => 'No season requested and no current season found'], 422);
            }
        }

        $season = $seasonId === null ? null : MSeason::find($seasonId);
        if (! $season) {
            return response()->json(['error' => 'Season not found'], 404);
        }

        $data = $this->links->list($seasonId);

        return response()
            ->json([
                'data' => $data,
                'meta' => [
                    'season' => [
                        'id' => $seasonId,
                        'name' => $season->name,
                        'year' => $this->slugs->seasonYear($seasonId),
                        'current' => $this->slugs->isCurrentSeason($seasonId),
                    ],
                    'base' => $this->slugs->base(),
                    'count' => count($data),
                ],
            ])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
