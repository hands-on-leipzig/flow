<?php

namespace App\Services;

use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use Illuminate\Support\Facades\Log;

/**
 * Copy of the public link inside DRAHT, so pages built from DRAHT data can offer it
 * without asking FLOW.
 *
 * FLOW stays the owner: the link is built from the registry on every push and never read
 * from `event.link`. Explore and Challenge are separate events in DRAHT, so one FLOW
 * event is pushed to each of its DRAHT ids.
 *
 * Only production writes to DRAHT. Everywhere else a push is reported as skipped, so no
 * dev or test instance can overwrite the links of the live system.
 */
class DrahtLinkSyncService
{
    public function __construct(private readonly EventSlugService $slugs) {}

    /**
     * Push the link of one event to one DRAHT event.
     *
     * @return string `pushed`, `failed` or `skipped: <reason>`
     */
    public function push(Event $event, int $drahtId, bool $dryRun = false): string
    {
        $url = $this->slugs->url($event);
        if ($url === null) {
            Log::warning("No public link to push to DRAHT for event {$event->id}");

            return 'skipped: no slug';
        }

        if ($dryRun) {
            return 'skipped: dry run';
        }

        if (! $this->writesToDraht()) {
            Log::info("Skipping DRAHT link update for event {$event->id} (environment: ".app()->environment().')');

            return 'skipped: environment '.app()->environment();
        }

        try {
            return app(DrahtController::class)->updateEventLink($drahtId, $url) ? 'pushed' : 'failed';
        } catch (\Throwable $e) {
            // One DRAHT event must not stop the rest of a season.
            Log::error("Failed to update link in DRAHT for event {$event->id}", [
                'draht_id' => $drahtId,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * Push the link of one event to every DRAHT event behind it.
     *
     * @return array{url: string|null, pushed: list<int>, failed: list<int>, skipped: string|null}
     */
    public function pushEvent(Event $event, bool $dryRun = false): array
    {
        $result = [
            'url' => $this->slugs->url($event),
            'pushed' => [],
            'failed' => [],
            'skipped' => null,
        ];

        $drahtIds = $event->programs
            ->pluck('draht_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        // Without a DRAHT id there is nothing to address.
        if ($drahtIds === []) {
            $result['skipped'] = 'no draht id';

            return $result;
        }

        foreach ($drahtIds as $drahtId) {
            $status = $this->push($event, $drahtId, $dryRun);

            match (true) {
                $status === 'pushed' => $result['pushed'][] = $drahtId,
                $status === 'failed' => $result['failed'][] = $drahtId,
                default => $result['skipped'] = substr($status, strlen('skipped: ')),
            };
        }

        return $result;
    }

    /**
     * Push every event of a season, so DRAHT does not depend on someone opening a page
     * in FLOW.
     *
     * @return list<array<string, mixed>>
     */
    public function pushSeason(int $seasonId, bool $dryRun = false): array
    {
        $events = Event::query()
            ->with('programs')
            ->where('season', $seasonId)
            ->orderBy('date')
            ->orderBy('name')
            ->get();

        $out = [];

        foreach ($events as $event) {
            $out[] = ['event_id' => (int) $event->id, 'name' => (string) $event->name]
                + $this->pushEvent($event, $dryRun);
        }

        return $out;
    }

    public function writesToDraht(): bool
    {
        return app()->environment('production');
    }
}
