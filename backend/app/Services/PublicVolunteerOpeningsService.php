<?php

namespace App\Services;

use App\Models\Event;
use App\Support\PublicHelperSearchPayload;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Public catalog of current-season events for Hero, with volunteer needs when published.
 */
class PublicVolunteerOpeningsService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $seasonId = SeasonService::currentSeasonId();
        $today = Carbon::today();

        $events = Event::query()
            ->with(['regionalPartner', 'programs'])
            ->where('season', $seasonId)
            ->orderBy('date')
            ->orderBy('name')
            ->get();

        if ($events->isEmpty()) {
            return [];
        }

        $publicationLevelByEvent = $this->latestPublicationLevels($events->pluck('id')->all());

        $out = [];
        foreach ($events as $event) {
            if (! $this->isUpcomingOrCurrent($event, $today)) {
                continue;
            }

            $level = $publicationLevelByEvent[(int) $event->id] ?? 1;
            $helperSearch = null;
            if ((bool) $event->public_helper_search && $level < 4) {
                $helperSearch = PublicHelperSearchPayload::forEvent($event);
            }

            $out[] = $this->serialize($event, $helperSearch);
        }

        return $out;
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, int>
     */
    private function latestPublicationLevels(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $rows = DB::table('publication')
            ->whereIn('event', $eventIds)
            ->orderByDesc('last_change')
            ->orderByDesc('id')
            ->get(['id', 'event', 'level', 'last_change']);

        $levels = [];
        foreach ($rows as $row) {
            $eventId = (int) $row->event;
            if (isset($levels[$eventId])) {
                continue;
            }
            $levels[$eventId] = (int) $row->level;
        }

        return $levels;
    }

    private function isUpcomingOrCurrent(Event $event, Carbon $today): bool
    {
        if (! $event->date) {
            return false;
        }
        $start = Carbon::parse($event->date)->startOfDay();
        if (! $start->isValid()) {
            return false;
        }
        $days = max((int) ($event->days ?: 1), 1);
        $end = $start->copy()->addDays($days - 1)->endOfDay();

        return ! $end->lt($today);
    }

    /**
     * @param  array{scopes?: list<array{roles?: list<string>}>}  $helperSearch
     */
    private function hasOpenRoles(array $helperSearch): bool
    {
        foreach ($helperSearch['scopes'] ?? [] as $scope) {
            if (! empty($scope['roles'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{scopes: list<mixed>}|null  $helperSearch
     * @return array<string, mixed>
     */
    private function serialize(Event $event, ?array $helperSearch): array
    {
        $slug = trim((string) $event->slug);
        $base = rtrim((string) config('app.public_url', 'https://handson.tools'), '/');
        $publicUrl = trim((string) $event->link);
        if ($publicUrl === '') {
            $publicUrl = $slug !== '' ? $base.'/'.$slug : $base;
        }

        return [
            'id' => (int) $event->id,
            'name' => $event->name,
            'slug' => $slug !== '' ? $slug : null,
            'date' => $event->date,
            'days' => max((int) ($event->days ?: 1), 1),
            'partner' => $event->regionalPartner?->name,
            'region' => $event->regionalPartner?->region,
            'public_url' => $publicUrl,
            'seeking' => $helperSearch !== null && $this->hasOpenRoles($helperSearch),
            'helper_search' => $helperSearch,
        ];
    }
}
