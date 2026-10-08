<?php

namespace App\Services;

/**
 * Decision for one regional partner. No event ids are written here.
 */
final class DrahtEventPlacement
{
    /**
     * @param  array<int, int|string>  $targets  DRAHT id => existing event id or create key
     * @param  list<array{id: int, date: string, name: ?string, level: int|null, regional_partner: int|null}>  $updates
     * @param  list<int>  $deleteIds
     * @param  list<array{key: string, date: string, name: ?string, level: int|null, regional_partner: int|null, draht_ids: list<int>}>  $creates
     * @param  list<int>  $touchedIds  existing ids whose date or program set changes
     */
    public function __construct(
        public readonly bool $regroup,
        public readonly array $targets,
        public readonly array $updates,
        public readonly array $deleteIds,
        public readonly array $creates,
        public readonly array $touchedIds,
    ) {}

    public static function passthrough(): self
    {
        return new self(false, [], [], [], [], []);
    }
}
