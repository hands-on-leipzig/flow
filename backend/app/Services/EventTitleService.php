<?php

namespace App\Services;

use App\Support\ProgramCatalog;

class EventTitleService
{
    private const FINALE_TYPE = 'Future Edition 8+ und Challenge Finale';

    /**
     * @return array{
     *     title_long: string,
     *     title_short: string,
     *     title_type: string,
     *     title_place: string
     * }
     */
    public function titles(object $event): array
    {
        $place = $this->cleanEventName($event);
        $type = $this->typeFor($event);
        $short = trim($type.' '.$place);

        return [
            // title_long is reserved for later (branded / HTML). Equal to title_short for now.
            'title_long' => $short,
            'title_short' => $short,
            // Leftover for parked PlanExport PdfLayoutService only. Do not put on the API.
            'title_type' => $type,
            'title_place' => $place,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withTitles(array $payload, object $event): array
    {
        $titles = $this->titles($event);

        return array_merge($payload, [
            'title_long' => $titles['title_long'],
            'title_short' => $titles['title_short'],
            'title_place' => $titles['title_place'],
        ]);
    }

    public function getEventTitleLong(object $event): string
    {
        return $this->titles($event)['title_long'];
    }

    public function getEventTitleShort(object $event): string
    {
        return $this->titles($event)['title_short'];
    }

    public function getCompetitionTypeText(object $event): string
    {
        return $this->titles($event)['title_type'];
    }

    public function cleanEventName(object $event): string
    {
        $eventName = (string) ($event->name ?? '');
        $level = (int) ($event->level ?? 0);

        if ($level === 2) {
            $eventName = preg_replace('/^Qualifikation\s+/i', '', $eventName) ?? $eventName;
        }

        if ($level === 3) {
            $eventName = preg_replace('/^Finale\s+/i', '', $eventName) ?? $eventName;
        }

        return trim($eventName);
    }

    private function typeFor(object $event): string
    {
        $level = (int) ($event->level ?? 0);
        if ($level === 3) {
            return self::FINALE_TYPE;
        }

        $flags = $this->programFlags($event);
        $parts = [];
        if ($flags['explore']) {
            $parts[] = 'Explore';
        }
        if ($flags['challenge']) {
            $parts[] = $level === 2 ? 'Challenge (Qualifikation)' : 'Challenge';
        }
        if ($flags['future']) {
            $parts[] = 'Future Edition 8+';
        }

        if ($parts === []) {
            return 'Event';
        }

        return $this->joinGerman($parts).' Event';
    }

    /**
     * @param  list<string>  $parts
     */
    private function joinGerman(array $parts): string
    {
        $n = count($parts);
        if ($n === 1) {
            return $parts[0];
        }
        if ($n === 2) {
            return $parts[0].' und '.$parts[1];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' und '.$last;
    }

    /**
     * @return array{explore: bool, challenge: bool, future: bool}
     */
    private function programFlags(object $event): array
    {
        if ($event instanceof \App\Models\Event) {
            return [
                'explore' => ProgramCatalog::hasExplore($event),
                'challenge' => ProgramCatalog::hasChallenge($event),
                'future' => ProgramCatalog::hasFuture($event),
            ];
        }

        $programs = collect($event->programs ?? []);
        $names = $programs->map(function ($row) {
            if (is_array($row)) {
                return strtoupper((string) ($row['name'] ?? ''));
            }

            return strtoupper((string) ($row->name ?? ''));
        });

        return [
            'explore' => $names->contains(ProgramCatalog::EXPLORE),
            'challenge' => $names->contains(ProgramCatalog::CHALLENGE),
            'future' => $names->contains(fn ($name) => ProgramCatalog::isFuture($name)),
        ];
    }
}
