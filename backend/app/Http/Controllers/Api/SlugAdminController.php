<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSlug;
use App\Models\MLevel;
use App\Models\RegionalPartner;
use App\Services\SeasonService;
use App\Services\SlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SlugAdminController extends Controller
{
    public function __construct(private SlugService $slugService) {}

    /**
     * Übersicht aller Regionalpartner mit Events und Slugs für die aktuelle Saison.
     */
    public function overview(): JsonResponse
    {
        $seasonId = SeasonService::currentSeasonId();

        $partners = RegionalPartner::with([
            'events' => function ($q) use ($seasonId) {
                $q->where('season', $seasonId)->with('levelRel')->orderBy('date');
            },
        ])->orderBy('name')->get();

        $levels = MLevel::pluck('name', 'id')->toArray();

        $result = [];
        foreach ($partners as $partner) {
            if ($partner->events->isEmpty()) {
                continue;
            }

            $partnerData = [
                'id'         => $partner->id,
                'name'       => $partner->name,
                'slug_long'  => $partner->slug_long,
                'slug_short' => $partner->slug_short,
                'events'     => [],
            ];

            foreach ($partner->events as $event) {
                $slugs = EventSlug::where('event_id', $event->id)
                    ->where('season_id', $seasonId)
                    ->orderByDesc('is_primary')
                    ->orderBy('variant')
                    ->orderBy('program')
                    ->get()
                    ->toArray();

                $programs = [];
                if (!empty($event->event_explore))   $programs[] = 'explore';
                if (!empty($event->event_challenge)) $programs[] = 'challenge';

                $partnerData['events'][] = [
                    'id'              => $event->id,
                    'name'            => $event->name,
                    'level'           => $event->level,
                    'level_name'      => $levels[$event->level] ?? "Level {$event->level}",
                    'date'            => $event->date,
                    'event_explore'   => $event->event_explore,
                    'event_challenge' => $event->event_challenge,
                    'programs'        => $programs,
                    'is_joint'        => count($programs) > 1,
                    'has_slugs'       => !empty($slugs),
                    'slugs'           => $slugs,
                ];
            }

            $result[] = $partnerData;
        }

        return response()->json([
            'season_id' => $seasonId,
            'partners'  => $result,
        ]);
    }

    /**
     * Slug-Präfixe eines Regionalpartners aktualisieren.
     */
    public function updatePartnerPrefixes(int $partnerId, Request $request): JsonResponse
    {
        $partner = RegionalPartner::findOrFail($partnerId);

        $validated = $request->validate([
            'slug_long'  => 'required|string|max:100',
            'slug_short' => 'nullable|string|max:20',
        ]);

        $partner->slug_long  = $this->slugService->sanitize($validated['slug_long']);
        $partner->slug_short = $validated['slug_short']
            ? $this->slugService->sanitize($validated['slug_short'])
            : null;
        $partner->save();

        return response()->json([
            'success'    => true,
            'slug_long'  => $partner->slug_long,
            'slug_short' => $partner->slug_short,
        ]);
    }

    /**
     * Einen einzelnen Slug aktualisieren (manuelles Override).
     */
    public function updateSlug(int $slugId, Request $request): JsonResponse
    {
        $record = EventSlug::findOrFail($slugId);

        $validated = $request->validate([
            'slug'       => 'required|string|max:255',
            'is_primary' => 'nullable|boolean',
        ]);

        $newSlug = $this->slugService->sanitize($validated['slug']);

        // Eindeutigkeitscheck innerhalb der Saison
        $conflict = EventSlug::where('slug', $newSlug)
            ->where('season_id', $record->season_id)
            ->where('id', '!=', $slugId)
            ->first();

        if ($conflict) {
            return response()->json([
                'error' => "Slug \"{$newSlug}\" wird bereits fuer ein anderes Event verwendet (Event-ID {$conflict->event_id}).",
            ], 422);
        }

        $record->slug = $newSlug;

        if (isset($validated['is_primary'])) {
            $record->is_primary = (bool) $validated['is_primary'];
        }

        $record->save();

        return response()->json(['success' => true, 'slug' => $record->toArray()]);
    }

    /**
     * Alle Slugs eines Events neu generieren (löscht vorherige und erzeugt neue).
     */
    public function regenerateSlugsForEvent(int $eventId): JsonResponse
    {
        $seasonId = SeasonService::currentSeasonId();

        $event = Event::where('id', $eventId)
            ->where('season', $seasonId)
            ->firstOrFail();

        // Link/QR-Code und Slugs zurücksetzen
        DB::table('event')
            ->where('id', $event->id)
            ->update(['slug' => null, 'link' => null, 'qrcode' => null]);

        $event->refresh();

        try {
            $publishController = app(PublishController::class);
            $publishController->linkAndQRcode($event->id);
        } catch (\Exception $e) {
            Log::error("SlugAdminController: Fehler beim Regenerieren für Event {$event->id}", [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $slugs = $this->slugService->getSlugsForEvent($event->id, $seasonId);

        return response()->json([
            'success'  => true,
            'event_id' => $event->id,
            'slugs'    => $slugs,
        ]);
    }

    /**
     * Slugs für alle Events der aktuellen Saison synchronisieren
     * (nur Slugs, kein QR-Code/Link-Reset).
     */
    public function syncCurrentSeason(): JsonResponse
    {
        $seasonId = SeasonService::currentSeasonId();
        $events   = Event::where('season', $seasonId)->get();

        $generated = 0;
        $failed    = 0;
        $errors    = [];

        foreach ($events as $event) {
            try {
                $this->slugService->generateForEvent($event);
                $generated++;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = "Event {$event->id} ({$event->name}): {$e->getMessage()}";
                Log::error("SlugAdminController: sync failed for event {$event->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success'   => true,
            'generated' => $generated,
            'failed'    => $failed,
            'total'     => $events->count(),
            'errors'    => $errors,
        ]);
    }
}
