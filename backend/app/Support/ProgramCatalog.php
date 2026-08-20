<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventProgram;
use App\Models\FirstProgram;
use Illuminate\Support\Collection;

class ProgramCatalog
{
    public const DISCOVER = 'DISCOVER';

    public const EXPLORE = 'EXPLORE';

    public const CHALLENGE = 'CHALLENGE';

    public static function isFuture(?string $name): bool
    {
        return is_string($name) && str_starts_with(strtoupper($name), 'FUTURE_');
    }

    public static function isDiscover(?string $name): bool
    {
        return strtoupper((string) $name) === self::DISCOVER;
    }

    /**
     * SQL fragment: catalog sequence for a first_program id column.
     * $column must be a trusted identifier (not user input).
     */
    public static function sequenceOrderSql(string $column = 'first_program'): string
    {
        return '(SELECT sequence FROM m_first_program WHERE m_first_program.id = '.$column.')';
    }

    /**
     * Programs that can be attached to an event (Discover is history-only).
     */
    public static function attachable(): Collection
    {
        return FirstProgram::query()
            ->where('name', '!=', self::DISCOVER)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    public static function hasName(Event $event, string $name): bool
    {
        $event->loadMissing('programs.firstProgram');

        return $event->programs->contains(
            fn (EventProgram $row) => strcasecmp((string) $row->name, $name) === 0
        );
    }

    public static function hasFuture(Event $event): bool
    {
        $event->loadMissing('programs.firstProgram');

        return $event->programs->contains(
            fn (EventProgram $row) => self::isFuture($row->name)
        );
    }

    public static function hasExplore(Event $event): bool
    {
        return self::hasName($event, self::EXPLORE);
    }

    public static function hasChallenge(Event $event): bool
    {
        return self::hasName($event, self::CHALLENGE);
    }

    /**
     * Resolve a catalog program from an HTTP id or name (explore, FUTURE_8, future8).
     */
    public static function resolve(int|string|null $param): ?FirstProgram
    {
        if ($param === null || $param === '') {
            return null;
        }

        if (is_numeric($param)) {
            return FirstProgram::find((int) $param);
        }

        $normalized = strtoupper(str_replace('-', '_', (string) $param));
        $compact = str_replace('_', '', $normalized);

        return FirstProgram::query()
            ->whereRaw('UPPER(REPLACE(name, "_", "")) = ?', [$compact])
            ->first();
    }

    public static function drahtId(Event $event, int $firstProgramId): ?int
    {
        $event->loadMissing('programs');

        $row = $event->programs->firstWhere('first_program', $firstProgramId);

        return $row?->draht_id ? (int) $row->draht_id : null;
    }

    public static function contaoId(Event $event, ?int $firstProgramId = null): ?int
    {
        $event->loadMissing('programs');

        if ($firstProgramId !== null) {
            $row = $event->programs->firstWhere('first_program', $firstProgramId);

            return $row?->contao_id ? (int) $row->contao_id : null;
        }

        $withContao = $event->programs->first(fn (EventProgram $row) => $row->contao_id);

        return $withContao?->contao_id ? (int) $withContao->contao_id : null;
    }

    /**
     * @param  array<int, array{first_program?: int, id?: int, draht_id?: int|null, contao_id?: int|null}>  $items
     */
    public static function sync(Event $event, array $items): void
    {
        $keep = [];

        foreach ($items as $item) {
            $programId = (int) ($item['first_program'] ?? $item['id'] ?? 0);
            if ($programId < 1) {
                continue;
            }

            $program = FirstProgram::find($programId);
            if (! $program || self::isDiscover($program->name)) {
                continue;
            }

            $keep[] = $programId;

            EventProgram::updateOrCreate(
                [
                    'event' => $event->id,
                    'first_program' => $programId,
                ],
                [
                    'draht_id' => $item['draht_id'] ?? null,
                    'contao_id' => $item['contao_id'] ?? null,
                ]
            );
        }

        EventProgram::where('event', $event->id)
            ->when($keep !== [], fn ($q) => $q->whereNotIn('first_program', $keep), fn ($q) => $q)
            ->delete();

        $event->unsetRelation('programs');
    }

    public static function upsertDrahtProgram(Event $event, int $firstProgramId, int $drahtId, ?int $contaoId = null): EventProgram
    {
        return EventProgram::updateOrCreate(
            [
                'event' => $event->id,
                'first_program' => $firstProgramId,
            ],
            [
                'draht_id' => $drahtId,
                'contao_id' => $contaoId,
            ]
        );
    }

    /**
     * m_first_program.color_hex keyed by catalog name (EXPLORE, CHALLENGE, …).
     *
     * @return array<string, string|null>
     */
    public static function colorHexByName(): array
    {
        static $map = null;
        if ($map === null) {
            $map = FirstProgram::query()
                ->get(['name', 'color_hex'])
                ->mapWithKeys(fn (FirstProgram $row) => [
                    strtoupper((string) $row->name) => $row->color_hex
                        ? ltrim((string) $row->color_hex, '#')
                        : null,
                ])
                ->all();
        }

        return $map;
    }

    /** Catalog color without #, e.g. ED1C24. */
    public static function colorHex(?string $name, string $fallback = '888888'): string
    {
        $key = strtoupper((string) $name);
        $hex = self::colorHexByName()[$key] ?? null;

        return $hex ?: ltrim($fallback, '#');
    }

    /** CSS hex from the catalog, e.g. #ED1C24. */
    public static function colorCss(?string $name, string $fallback = '888888'): string
    {
        return '#'.self::colorHex($name, $fallback);
    }
}
