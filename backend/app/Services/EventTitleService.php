<?php

namespace App\Services;

use App\Support\ProgramCatalog;

class EventTitleService
{
    /**
     * First matching rule wins. When-keys that are omitted are unconstrained.
     *
     * @var list<array{
     *     when: array{level?: int, explore?: bool, challenge?: bool, future?: bool},
     *     type: string,
     *     type_short: string
     * }>
     */
    private const RULES = [
        [
            'when' => ['level' => 3],
            'type' => 'Finale',
            'type_short' => 'Finale',
        ],
        [
            'when' => ['level' => 2],
            'type' => 'Qualifikationswettbewerb',
            'type_short' => 'Quali',
        ],
        [
            'when' => ['future' => true, 'explore' => false, 'challenge' => false],
            'type' => 'Future Wettbewerb',
            'type_short' => 'Future Wettbewerb',
        ],
        [
            'when' => ['future' => true],
            'type' => 'Mixed Wettbewerb',
            'type_short' => 'Mixed Wettbewerb',
        ],
        [
            'when' => ['level' => 1, 'explore' => true, 'challenge' => true],
            'type' => 'Ausstellung und Regionalwettbewerb',
            'type_short' => 'Ausstellung und Regio',
        ],
        [
            'when' => ['level' => 1, 'explore' => true, 'challenge' => false],
            'type' => 'Ausstellung',
            'type_short' => 'Ausstellung',
        ],
        [
            'when' => ['level' => 1, 'challenge' => true, 'explore' => false],
            'type' => 'Regionalwettbewerb',
            'type_short' => 'Regio',
        ],
        [
            'when' => [],
            'type' => 'Wettbewerb',
            'type_short' => 'Wettbewerb',
        ],
    ];

    /**
     * @return array{
     *     title_long: string,
     *     title_short: string,
     *     title_type: string,
     *     title_type_short: string,
     *     title_place: string
     * }
     */
    public function titles(object $event): array
    {
        $matched = $this->matchRule($event);
        $place = $this->cleanEventName($event);
        $type = $matched['type'];
        $typeShort = $matched['type_short'];

        return [
            'title_long' => trim('FIRST LEGO League '.$type.' '.$place),
            'title_short' => trim($typeShort.' '.$place),
            'title_type' => $type,
            'title_type_short' => $typeShort,
            'title_place' => $place,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withTitles(array $payload, object $event): array
    {
        return array_merge($payload, $this->titles($event));
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

    /**
     * @return array{type: string, type_short: string}
     */
    private function matchRule(object $event): array
    {
        $flags = $this->programFlags($event);
        $level = (int) ($event->level ?? 0);

        foreach (self::RULES as $rule) {
            if ($this->whenMatches($rule['when'], $level, $flags)) {
                return [
                    'type' => $rule['type'],
                    'type_short' => $rule['type_short'],
                ];
            }
        }

        return ['type' => 'Wettbewerb', 'type_short' => 'Wettbewerb'];
    }

    /**
     * @param  array{level?: int, explore?: bool, challenge?: bool, future?: bool}  $when
     * @param  array{explore: bool, challenge: bool, future: bool}  $flags
     */
    private function whenMatches(array $when, int $level, array $flags): bool
    {
        if (array_key_exists('level', $when) && $when['level'] !== $level) {
            return false;
        }
        foreach (['explore', 'challenge', 'future'] as $family) {
            if (array_key_exists($family, $when) && $when[$family] !== $flags[$family]) {
                return false;
            }
        }

        return true;
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
