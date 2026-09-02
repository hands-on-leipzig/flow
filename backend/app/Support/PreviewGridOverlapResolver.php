<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Resolve per-column time overlaps in Rollen/Teams preview grids (preview-only).
 */
final class PreviewGridOverlapResolver
{
    private const SLOT_MINUTES = 5;

    /**
     * @param  list<array{column_key: string, start: Carbon, end: Carbon, text: string, rowspan: int, style_column: string}>  $placed
     * @return array{events: list<array>, has_overlaps: bool}
     */
    public static function resolve(array $placed): array
    {
        if ($placed === []) {
            return ['events' => [], 'has_overlaps' => false];
        }

        $indexed = [];
        foreach ($placed as $i => $event) {
            $indexed[] = array_merge($event, ['_idx' => $i]);
        }

        $byColumn = [];
        foreach ($indexed as $event) {
            $byColumn[$event['column_key']][] = $event;
        }

        $hasOverlaps = false;
        $resolved = [];

        foreach ($byColumn as $columnEvents) {
            usort($columnEvents, static function (array $a, array $b): int {
                $cmp = $a['start']->timestamp <=> $b['start']->timestamp;
                if ($cmp !== 0) {
                    return $cmp;
                }

                return $a['_idx'] <=> $b['_idx'];
            });

            $columnResolved = [];

            foreach ($columnEvents as $event) {
                $b = self::copyEvent($event);
                $earlier = $columnResolved;

                $sameStartPartner = null;
                $containedPartner = null;
                $partialPartner = null;

                foreach ($earlier as $a) {
                    if (! self::intervalsOverlap($a, $b)) {
                        continue;
                    }

                    $hasOverlaps = true;

                    if ($b['start']->equalTo($a['start'])) {
                        $sameStartPartner ??= $a;
                    } elseif ($a['start']->lt($b['start']) && $b['end']->lte($a['end'])) {
                        $containedPartner ??= $a;
                    } else {
                        $partialPartner ??= $a;
                    }
                }

                if ($sameStartPartner !== null) {
                    $b['start'] = $sameStartPartner['end']->copy();
                    $b['overlap_adjusted'] = true;
                    self::recalculateRowspan($b);

                    if ($b['start']->gte($b['end'])) {
                        $b['rowspan'] = 1;
                    }

                    $columnResolved[] = $b;

                    continue;
                }

                if ($containedPartner !== null) {
                    self::markContainer($columnResolved, $containedPartner);
                    $columnResolved[] = $b;

                    continue;
                }

                if ($partialPartner !== null) {
                    $b['start'] = $partialPartner['end']->copy();
                    $b['overlap_adjusted'] = true;
                    self::recalculateRowspan($b);

                    if ($b['start']->gte($b['end'])) {
                        continue;
                    }

                    $columnResolved[] = $b;

                    continue;
                }

                $columnResolved[] = $b;
            }

            foreach ($columnResolved as $event) {
                $resolved[] = $event;
            }
        }

        usort($resolved, static fn (array $a, array $b): int => $a['_idx'] <=> $b['_idx']);

        $clean = [];
        foreach ($resolved as $event) {
            unset($event['_idx']);
            $clean[] = $event;
        }

        return [
            'events' => $clean,
            'has_overlaps' => $hasOverlaps,
        ];
    }

    /**
     * @param  array{start: Carbon, end: Carbon}  $a
     * @param  array{start: Carbon, end: Carbon}  $b
     */
    private static function intervalsOverlap(array $a, array $b): bool
    {
        return $a['start']->lt($b['end']) && $b['start']->lt($a['end']);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private static function copyEvent(array $event): array
    {
        $copy = $event;
        $copy['start'] = $event['start']->copy();
        $copy['end'] = $event['end']->copy();
        unset($copy['overlap_adjusted'], $copy['overlap_container']);

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function recalculateRowspan(array &$event): void
    {
        $minutes = max(self::SLOT_MINUTES, (int) $event['start']->diffInMinutes($event['end']));
        $event['rowspan'] = max(1, (int) ceil($minutes / self::SLOT_MINUTES));
    }

    /**
     * @param  list<array<string, mixed>>  $columnResolved
     * @param  array<string, mixed>  $partner
     */
    private static function markContainer(array &$columnResolved, array $partner): void
    {
        foreach ($columnResolved as &$resolved) {
            if ($resolved['_idx'] === $partner['_idx']) {
                $resolved['overlap_container'] = true;
                break;
            }
        }
        unset($resolved);
    }
}
