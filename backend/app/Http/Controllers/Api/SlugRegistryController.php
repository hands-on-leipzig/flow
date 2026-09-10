<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MSeason;
use App\Services\EventSlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view of the slug registry for one season: which event owns which
 * one-link, which slugs it used before, and where the stored link no longer matches
 * what the registry would build today.
 */
class SlugRegistryController extends Controller
{
    public function __construct(private readonly EventSlugService $slugs) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'season' => 'sometimes|integer|min:1',
        ]);

        $seasonId = (int) ($validated['season'] ?? $this->slugs->currentSeasonId() ?? 0);
        if ($seasonId <= 0) {
            return response()->json(['error' => 'No season requested and no current season found'], 422);
        }

        $season = MSeason::find($seasonId);
        if (! $season) {
            return response()->json(['error' => 'Season not found'], 404);
        }

        $events = Event::with(['programs', 'regionalPartner'])
            ->where('season', $seasonId)
            ->orderBy('date')
            ->orderBy('name')
            ->get();

        $history = DB::table('event_slug_history')
            ->where('season', $seasonId)
            ->orderByDesc('replaced_at')
            ->get()
            ->groupBy('event');

        $rows = $events->map(fn (Event $event) => $this->row(
            $event,
            $history->get((int) $event->id, collect())
        ));

        return response()->json([
            'season' => [
                'id' => $seasonId,
                'name' => $season->name,
                'year' => (int) $season->year,
                'current' => $this->slugs->isCurrentSeason($seasonId),
            ],
            'base' => $this->slugs->base(),
            'reserved' => $this->slugs->reserved(),
            'events' => $rows->all(),
            'summary' => [
                'total' => $rows->count(),
                'without_slug' => $rows->whereNull('slug')->count(),
                'manual' => $rows->where('manual', true)->count(),
                'off_suggestion' => $rows->where('manual', false)->where('follows_suggestion', false)->count(),
                'stale_link' => $rows->whereNotNull('slug')->where('link_current', false)->count(),
                'history' => $rows->sum(fn (array $row) => count($row['history'])),
            ],
        ]);
    }

    /**
     * @param  Collection<int, object>  $history
     * @return array<string, mixed>
     */
    private function row(Event $event, Collection $history): array
    {
        $described = $this->slugs->describe($event);
        $suggestion = $this->slugs->suggest($event);
        $storedLink = empty($event->link) ? null : (string) $event->link;

        return array_merge($described, [
            'name' => (string) $event->name,
            'date' => $event->date === null ? null : (string) $event->date,
            'level' => (int) $event->level,
            'regional_partner_id' => $event->regional_partner === null ? null : (int) $event->regional_partner,
            'regional_partner' => $event->regionalPartner?->name,
            'programs' => $event->programs->pluck('name')->filter()->values()->all(),
            'suggestion' => $suggestion,
            'follows_suggestion' => $described['slug'] === $suggestion,
            'stored_link' => $storedLink,
            // A link written before a rename still points at the old slug; the QR code
            // printed from it does too, which is what makes this worth showing.
            'link_current' => $storedLink !== null && $storedLink === $described['url'],
            'has_qrcode' => ! empty($event->qrcode),
            'history' => $history
                ->map(fn (object $entry) => [
                    'slug' => (string) $entry->slug,
                    'url' => $this->historyUrl($described, (string) $entry->slug),
                    'replaced_at' => $entry->replaced_at,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $described
     */
    private function historyUrl(array $described, string $slug): string
    {
        $prefix = $described['current_season'] || $described['season_year'] === null
            ? ''
            : '/'.$described['season_year'];

        return $this->slugs->base().$prefix.'/'.$slug;
    }
}
