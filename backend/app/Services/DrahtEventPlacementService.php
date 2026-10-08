<?php

namespace App\Services;

/**
 * Decides where DRAHT programs sit after a date change, without writing.
 *
 * A partner is regrouped only when a linked program's DRAHT date differs from
 * the FLOW event that currently holds it. Programs whose date still matches
 * stay on that event id. A new id is used only when no existing event can
 * take the program.
 */
class DrahtEventPlacementService
{
    /**
     * @param  list<array<string, mixed>>  $feedRows  one regional partner
     * @param  list<array<string, mixed>>  $events  season events that may hold these programs or share the partner
     */
    public function place(int $seasonId, array $feedRows, array $events): DrahtEventPlacement
    {
        $feed = $this->normalizeFeed($feedRows);
        if ($feed === []) {
            return DrahtEventPlacement::passthrough();
        }

        $partnerId = $feed[0]['regional_partner'];
        $feedByDraht = [];
        foreach ($feed as $row) {
            $feedByDraht[$row['draht_id']] = $row;
        }

        $state = $this->buildState($seasonId, $partnerId, $events, $feedByDraht);
        $originOf = $this->originIndex($state, $feedByDraht);

        $changed = false;
        $pinned = [];
        $displacedByOrigin = [];
        $free = [];

        foreach ($feed as $row) {
            $originId = $originOf[$row['draht_id']] ?? null;
            if ($originId === null) {
                $free[] = $row;

                continue;
            }

            $row['origin_id'] = $originId;
            if ($row['date'] === $state[$originId]['originalDate']) {
                $pinned[] = $row;

                continue;
            }

            $changed = true;
            $displacedByOrigin[$originId][] = $row;
        }

        if (! $changed) {
            return DrahtEventPlacement::passthrough();
        }

        foreach ($pinned as $row) {
            $this->assign($state, $row['origin_id'], $row, false);
        }

        $cohorts = [];
        foreach ($displacedByOrigin as $originId => $rows) {
            if ($this->hasStayers($state, $originId)) {
                foreach ($rows as $row) {
                    $free[] = $row;
                }

                continue;
            }

            $groups = $this->groupByDate($rows);
            if (count($groups) === 1) {
                $date = array_key_first($groups);
                $cohorts[] = [
                    'event_id' => $originId,
                    'date' => $date,
                    'rows' => $groups[$date],
                ];

                continue;
            }

            $keeperDate = $this->keeperDate($groups);
            $this->assignGroup($state, $originId, $groups[$keeperDate], $keeperDate);
            unset($groups[$keeperDate]);
            foreach ($groups as $leaving) {
                foreach ($leaving as $row) {
                    $free[] = $row;
                }
            }
        }

        foreach ($this->cohortsByDate($cohorts) as $date => $group) {
            $date = (string) $date;
            $candidateIds = $this->sitterIds($state, $partnerId, $date);
            foreach ($group as $cohort) {
                $candidateIds[] = (int) $cohort['event_id'];
            }
            $candidateIds = array_values(array_unique($candidateIds));
            $survivorId = $this->pickSurvivor($state, $candidateIds, $date);

            $pending = [];
            foreach ($group as $cohort) {
                if ((int) $cohort['event_id'] === $survivorId) {
                    $this->assignGroup($state, $survivorId, $cohort['rows'], $date);

                    continue;
                }
                foreach ($cohort['rows'] as $row) {
                    $pending[] = $row;
                }
            }

            foreach ($candidateIds as $eventId) {
                if ($eventId === $survivorId) {
                    continue;
                }
                $this->transferAssigned($state, $eventId, $survivorId);
            }

            foreach ($pending as $row) {
                if (isset($state[$survivorId]['occupied'][$row['first_program']])) {
                    $free[] = $row;

                    continue;
                }
                $writeHeader = $state[$survivorId]['resultDate'] !== $state[$survivorId]['originalDate'];
                $this->assign($state, $survivorId, $row, $writeHeader);
            }
        }

        usort($free, function (array $a, array $b) {
            return [$a['date'], $a['sequence'], $a['draht_id']]
                <=> [$b['date'], $b['sequence'], $b['draht_id']];
        });

        $creates = [];
        $nextKey = 1;
        foreach ($free as $row) {
            $targetId = $this->findHome($state, $partnerId, $row['date'], $row['first_program']);
            if ($targetId !== null) {
                $this->assign($state, $targetId, $row, false);

                continue;
            }

            $originId = $row['origin_id'] ?? null;
            if ($originId !== null && $this->canReuse($state, $originId)) {
                $state[$originId]['partner'] = $partnerId;
                $state[$originId]['resultDate'] = $row['date'];
                $this->assign($state, $originId, $row, true);

                continue;
            }

            $placed = false;
            foreach ($creates as $key => $create) {
                if ($create['date'] !== $row['date'] || isset($create['occupied'][$row['first_program']])) {
                    continue;
                }
                $creates[$key]['rows'][] = $row;
                $creates[$key]['occupied'][$row['first_program']] = true;
                $placed = true;
                break;
            }
            if ($placed) {
                continue;
            }

            $key = 'new-'.$nextKey;
            $nextKey++;
            $creates[$key] = [
                'key' => $key,
                'date' => $row['date'],
                'regional_partner' => $partnerId,
                'rows' => [$row],
                'occupied' => [$row['first_program'] => true],
            ];
        }

        return $this->toResult($state, $partnerId, $creates);
    }

    /**
     * @param  list<array<string, mixed>>  $feedRows
     * @return list<array{draht_id: int, first_program: int, sequence: int, date: string, name: ?string, level: int|null, regional_partner: int|null, origin_id: ?int}>
     */
    private function normalizeFeed(array $feedRows): array
    {
        $feed = [];
        foreach ($feedRows as $row) {
            if (! is_array($row) || ! isset($row['draht_id'], $row['first_program'])) {
                continue;
            }

            $drahtId = (int) $row['draht_id'];
            if ($drahtId < 1) {
                continue;
            }

            $feed[$drahtId] = [
                'draht_id' => $drahtId,
                'first_program' => (int) $row['first_program'],
                'sequence' => (int) ($row['sequence'] ?? $row['first_program']),
                'date' => $this->normalizeDate($row['date'] ?? null),
                'name' => isset($row['name']) && is_string($row['name']) && $row['name'] !== '' ? $row['name'] : null,
                'level' => isset($row['level']) && $row['level'] !== '' && $row['level'] !== null ? (int) $row['level'] : null,
                'regional_partner' => isset($row['regional_partner']) && $row['regional_partner'] !== null && $row['regional_partner'] !== ''
                    ? (int) $row['regional_partner']
                    : null,
                'origin_id' => null,
            ];
        }

        return array_values($feed);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<int, array<string, mixed>>  $feedByDraht
     * @return array<int, array<string, mixed>>
     */
    private function buildState(int $seasonId, ?int $partnerId, array $events, array $feedByDraht): array
    {
        $state = [];
        foreach ($events as $event) {
            if (! is_array($event) || ! isset($event['id'])) {
                continue;
            }
            if (array_key_exists('season', $event) && (int) $event['season'] !== $seasonId) {
                continue;
            }

            $eventPartner = isset($event['regional_partner']) && $event['regional_partner'] !== null && $event['regional_partner'] !== ''
                ? (int) $event['regional_partner']
                : null;

            $programs = is_array($event['programs'] ?? null) ? $event['programs'] : [];
            $holdsFeed = false;
            foreach ($programs as $program) {
                if (! is_array($program) || empty($program['draht_id'])) {
                    continue;
                }
                if (isset($feedByDraht[(int) $program['draht_id']])) {
                    $holdsFeed = true;
                    break;
                }
            }

            if ($eventPartner !== $partnerId && ! $holdsFeed) {
                continue;
            }

            $others = [];
            $occupied = [];
            $links = [];
            foreach ($programs as $program) {
                if (! is_array($program) || ! isset($program['first_program'])) {
                    continue;
                }
                $drahtId = ! empty($program['draht_id']) ? (int) $program['draht_id'] : null;
                $firstProgram = (int) $program['first_program'];
                if ($drahtId !== null && isset($feedByDraht[$drahtId])) {
                    $links[$drahtId] = $firstProgram;

                    continue;
                }
                $others[] = $drahtId;
                $occupied[$firstProgram] = true;
            }

            $id = (int) $event['id'];
            $date = $this->normalizeDate($event['date'] ?? null);
            $level = isset($event['level']) && $event['level'] !== null && $event['level'] !== ''
                ? (int) $event['level']
                : null;
            $state[$id] = [
                'id' => $id,
                'partner' => $eventPartner,
                'level' => $level,
                'originalDate' => $date,
                'resultDate' => $date,
                'originalProgramCount' => count($programs),
                'links' => $links,
                'others' => $others,
                'otherPrograms' => $occupied,
                'occupied' => $occupied,
                'assigned' => [],
                'writeHeader' => false,
            ];
        }

        return $state;
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     * @param  array<int, array<string, mixed>>  $feedByDraht
     * @return array<int, int>
     */
    private function originIndex(array $state, array $feedByDraht): array
    {
        $originOf = [];
        foreach ($state as $eventId => $event) {
            foreach (array_keys($event['links']) as $drahtId) {
                if (isset($feedByDraht[(int) $drahtId])) {
                    $originOf[(int) $drahtId] = (int) $eventId;
                }
            }
        }

        return $originOf;
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     * @param  array{draht_id: int, first_program: int, sequence: int, date: string, name: ?string, level: int|null, regional_partner: int|null, origin_id: ?int}  $row
     */
    private function assign(array &$state, int $eventId, array $row, bool $writeHeader): void
    {
        $state[$eventId]['assigned'][$row['draht_id']] = $row;
        $state[$eventId]['occupied'][$row['first_program']] = true;
        if ($writeHeader) {
            $state[$eventId]['writeHeader'] = true;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     * @param  list<array<string, mixed>>  $rows
     */
    private function assignGroup(array &$state, int $eventId, array $rows, string $date): void
    {
        $state[$eventId]['resultDate'] = $date;
        foreach ($rows as $row) {
            $this->assign($state, $eventId, $row, true);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     */
    private function hasStayers(array $state, int $eventId): bool
    {
        return $state[$eventId]['assigned'] !== [] || $state[$eventId]['others'] !== [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByDate(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['date']][] = $row;
        }

        return $groups;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $groups
     */
    private function keeperDate(array $groups): string
    {
        $bestDate = null;
        $bestRank = null;
        foreach ($groups as $date => $rows) {
            $rank = $this->groupRank($rows);
            if ($bestRank === null || $rank < $bestRank) {
                $bestRank = $rank;
                $bestDate = (string) $date;
            }
        }

        return (string) $bestDate;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: int, 1: int}
     */
    private function groupRank(array $rows): array
    {
        $best = null;
        foreach ($rows as $row) {
            $rank = [$row['sequence'], $row['draht_id']];
            if ($best === null || $rank < $best) {
                $best = $rank;
            }
        }

        return $best ?? [PHP_INT_MAX, PHP_INT_MAX];
    }

    /**
     * @param  list<array{event_id: int, date: string, rows: list<array<string, mixed>>}>  $cohorts
     * @return array<string, list<array{event_id: int, date: string, rows: list<array<string, mixed>>}>>
     */
    private function cohortsByDate(array $cohorts): array
    {
        $byDate = [];
        foreach ($cohorts as $cohort) {
            $byDate[$cohort['date']][] = $cohort;
        }

        return $byDate;
    }

    /**
     * Events that already have programs on this date and this partner.
     *
     * @param  array<int, array<string, mixed>>  $state
     * @return list<int>
     */
    private function sitterIds(array $state, ?int $partnerId, string $date): array
    {
        $ids = [];
        foreach ($state as $eventId => $event) {
            if ($event['partner'] !== $partnerId || $event['resultDate'] !== $date) {
                continue;
            }
            if ($event['assigned'] === [] && $event['others'] === []) {
                continue;
            }
            $ids[] = (int) $eventId;
        }

        sort($ids);

        return $ids;
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     */
    private function findHome(array $state, ?int $partnerId, string $date, int $firstProgram): ?int
    {
        $candidates = [];
        foreach ($state as $eventId => $event) {
            if ($event['partner'] !== $partnerId || $event['resultDate'] !== $date) {
                continue;
            }
            if ($event['assigned'] === [] && $event['others'] === []) {
                continue;
            }
            if (isset($event['occupied'][$firstProgram])) {
                continue;
            }
            $candidates[] = (int) $eventId;
        }

        if ($candidates === []) {
            return null;
        }

        return $this->pickSurvivor($state, $candidates, $date);
    }

    /**
     * Highest level, then the event already on this date, then the lower id.
     *
     * @param  array<int, array<string, mixed>>  $state
     * @param  list<int>  $ids
     */
    private function pickSurvivor(array $state, array $ids, string $date): int
    {
        usort($ids, function (int $a, int $b) use ($state, $date) {
            $level = $this->levelRank($state[$b]) <=> $this->levelRank($state[$a]);
            if ($level !== 0) {
                return $level;
            }
            $sitting = ($this->alreadyOnDate($state[$a], $date) ? 0 : 1)
                <=> ($this->alreadyOnDate($state[$b], $date) ? 0 : 1);
            if ($sitting !== 0) {
                return $sitting;
            }

            return $a <=> $b;
        });

        return $ids[0];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function levelRank(array $event): int
    {
        $level = $event['level'] ?? null;

        return $level === null ? 0 : (int) $level;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function alreadyOnDate(array $event, string $date): bool
    {
        return $event['originalDate'] === $date
            && ($event['assigned'] !== [] || $event['others'] !== []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     */
    private function transferAssigned(array &$state, int $fromId, int $toId): void
    {
        foreach ($state[$fromId]['assigned'] as $drahtId => $row) {
            if (isset($state[$toId]['occupied'][$row['first_program']])) {
                continue;
            }
            unset($state[$fromId]['assigned'][$drahtId]);
            $this->releaseOccupied($state, $fromId, (int) $row['first_program']);
            $this->assign($state, $toId, $row, false);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     */
    private function releaseOccupied(array &$state, int $eventId, int $firstProgram): void
    {
        foreach ($state[$eventId]['assigned'] as $row) {
            if ((int) $row['first_program'] === $firstProgram) {
                return;
            }
        }
        if (isset($state[$eventId]['otherPrograms'][$firstProgram])) {
            return;
        }
        unset($state[$eventId]['occupied'][$firstProgram]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     */
    private function canReuse(array $state, int $eventId): bool
    {
        if (! isset($state[$eventId])) {
            return false;
        }

        return $state[$eventId]['assigned'] === [] && $state[$eventId]['others'] === [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     * @param  array<string, array{key: string, date: string, regional_partner: int|null, rows: list<array<string, mixed>>, occupied?: array<int, bool>}>  $creates
     */
    private function toResult(array $state, ?int $partnerId, array $creates): DrahtEventPlacement
    {
        $targets = [];
        $updates = [];
        $deleteIds = [];
        $touchedIds = [];

        foreach ($state as $eventId => $event) {
            foreach ($event['assigned'] as $drahtId => $row) {
                $targets[(int) $drahtId] = (int) $eventId;
            }

            $hasPrograms = $event['assigned'] !== [] || $event['others'] !== [];
            if (! $hasPrograms && $event['originalProgramCount'] > 0) {
                $deleteIds[] = (int) $eventId;

                continue;
            }

            $originalIds = array_map('intval', array_keys($event['links']));
            $finalIds = array_map('intval', array_keys($event['assigned']));
            sort($originalIds);
            sort($finalIds);
            $dateChanged = $event['resultDate'] !== $event['originalDate'];
            $setChanged = $originalIds !== $finalIds;

            if (! $dateChanged && ! $setChanged) {
                continue;
            }

            $touchedIds[] = (int) $eventId;
            if ($event['writeHeader'] && $dateChanged) {
                $own = array_filter(
                    $event['assigned'],
                    fn (array $row) => ($row['origin_id'] ?? null) === (int) $eventId
                );
                $header = $this->headerRow($own !== [] ? $own : $event['assigned']);
                $updates[] = [
                    'id' => (int) $eventId,
                    'date' => $event['resultDate'],
                    'name' => $header['name'] ?? null,
                    'level' => $this->keptLevel($event, $header),
                    'regional_partner' => $partnerId,
                ];
            }
        }

        $createRows = [];
        foreach ($creates as $create) {
            $header = $this->headerRow($create['rows']);
            $drahtIds = [];
            foreach ($create['rows'] as $row) {
                $drahtIds[] = (int) $row['draht_id'];
                $targets[(int) $row['draht_id']] = $create['key'];
            }
            sort($drahtIds);
            $createRows[] = [
                'key' => $create['key'],
                'date' => $create['date'],
                'name' => $header['name'] ?? null,
                'level' => $header['level'] ?? null,
                'regional_partner' => $partnerId,
                'draht_ids' => $drahtIds,
            ];
        }

        sort($deleteIds);
        sort($touchedIds);
        ksort($targets);

        return new DrahtEventPlacement(true, $targets, $updates, $deleteIds, $createRows, $touchedIds);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function headerRow(array $rows): array
    {
        $best = null;
        $bestRank = null;
        foreach ($rows as $row) {
            $rank = [$row['sequence'], $row['draht_id']];
            if ($bestRank === null || $rank < $bestRank) {
                $bestRank = $rank;
                $best = $row;
            }
        }

        return $best ?? [];
    }

    /**
     * A joining program must not replace the event's level with a lower one.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $header
     */
    private function keptLevel(array $event, array $header): ?int
    {
        $incoming = isset($header['level']) && $header['level'] !== null && $header['level'] !== ''
            ? (int) $header['level']
            : null;
        $stored = $event['level'] ?? null;
        $stored = $stored !== null ? (int) $stored : null;
        if ($stored === null) {
            return $incoming;
        }
        if ($incoming === null || $incoming < $stored) {
            return $stored;
        }

        return $incoming;
    }

    private function normalizeDate(mixed $date): string
    {
        if (! is_string($date) && ! is_numeric($date)) {
            return '1970-01-01';
        }

        $value = trim((string) $date);
        if ($value === '') {
            return '1970-01-01';
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match) === 1) {
            return $match[1];
        }

        return '1970-01-01';
    }
}
