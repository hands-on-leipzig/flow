<?php

namespace App\Services;

use App\Models\Event;

/**
 * Public links of one season, addressed with DRAHT event ids.
 *
 * JOIN lists venues that come from DRAHT and knows no FLOW ids, so the DRAHT id of a
 * program is the key. Explore and Challenge are separate events in DRAHT but one event
 * here, which is why several ids can name the same link.
 *
 * The URL is always built from the registry instead of the stored `event.link`, so a
 * changed public base or season prefix is answered correctly right away.
 */
class PublicEventLinkService
{
    public function __construct(private readonly EventSlugService $slugs) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $seasonId): array
    {
        $events = Event::query()
            ->with('programs')
            ->where('season', $seasonId)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->orderBy('date')
            ->orderBy('name')
            ->get();

        $out = [];

        foreach ($events as $event) {
            $url = $this->slugs->url($event);
            $drahtIds = $this->drahtIds($event);

            // Without a DRAHT id no caller can address this event, so it stays out.
            if ($url === null || $drahtIds === []) {
                continue;
            }

            $out[] = [
                'event_id' => (int) $event->id,
                'name' => (string) $event->name,
                'date' => $event->date,
                'slug' => (string) $event->slug,
                'url' => $url,
                'draht_ids' => $drahtIds,
            ];
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function drahtIds(Event $event): array
    {
        return $event->programs
            ->pluck('draht_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
