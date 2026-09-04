<?php

namespace App\Services;

use App\Enums\FirstProgram;
use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

class EnrollmentsService
{
    public function __construct(
        private readonly DrahtController $draht,
    ) {}

    /**
     * Live DRAHT enrollments for one season.
     *
     * @return array{
     *     season_id: int,
     *     season_name: string,
     *     season_year: mixed,
     *     event_count: int,
     *     histogram: list<array{teams: int|string, explore: int, challenge: int, future8: int, explore_events: list<string>, challenge_events: list<string>, future8_events: list<string>}>,
     *     dual: list<array<string, mixed>>,
     *     future_standalone: list<array<string, mixed>>
     * }
     */
    public function forSeason(?int $seasonId = null): array
    {
        $seasonId = $seasonId ?: SeasonService::currentSeasonId();
        $season = DB::table('m_season')->where('id', $seasonId)->first();

        $explore = array_fill(1, 25, 0);
        $challenge = array_fill(1, 25, 0);
        $future8 = array_fill(1, 25, 0);
        $explore[26] = 0;
        $challenge[26] = 0;
        $future8[26] = 0;
        $exploreNames = [];
        $challengeNames = [];
        $future8Names = [];
        for ($i = 1; $i <= 26; $i++) {
            $exploreNames[$i] = [];
            $challengeNames[$i] = [];
            $future8Names[$i] = [];
        }

        $dual = [];
        $futureStandalone = [];

        $events = Event::query()
            ->where('season', $seasonId)
            ->orderBy('name')
            ->orderBy('date')
            ->get();

        foreach ($events as $event) {
            $payload = $this->draht->fetchScheduleData($event)['data'] ?? [];
            $byProgram = $this->indexPrograms($payload['programs'] ?? []);
            $eventName = (string) $event->name;

            $exploreEnrolled = $this->enrolledWithDraht($byProgram, FirstProgram::EXPLORE->value)
                + $this->enrolledWithDraht($byProgram, FirstProgram::DISCOVER->value);
            $challengeEnrolled = $this->enrolledWithDraht($byProgram, FirstProgram::CHALLENGE->value);
            $future8Enrolled = $this->enrolledWithDraht($byProgram, FirstProgram::FUTURE_8->value);

            if ($this->hasDrahtId($byProgram, FirstProgram::EXPLORE->value)
                || $this->hasDrahtId($byProgram, FirstProgram::DISCOVER->value)) {
                $this->bump($explore, $exploreNames, $exploreEnrolled, $eventName);
            }
            if ($this->hasDrahtId($byProgram, FirstProgram::CHALLENGE->value)) {
                $this->bump($challenge, $challengeNames, $challengeEnrolled, $eventName);
            }
            if ($this->hasDrahtId($byProgram, FirstProgram::FUTURE_8->value)) {
                $this->bump($future8, $future8Names, $future8Enrolled, $eventName);
            }

            $attached = $event->programs
                ->pluck('first_program')
                ->map(fn ($id) => (int) $id)
                ->all();
            $hasChallenge = in_array(FirstProgram::CHALLENGE->value, $attached, true);
            $hasFuture8 = in_array(FirstProgram::FUTURE_8->value, $attached, true);

            if ($hasChallenge && $hasFuture8) {
                $dual[] = $this->eventCapacityRow($event, $byProgram, withChallenge: true);
            } elseif ($hasFuture8 && ! $hasChallenge) {
                $futureStandalone[] = $this->eventCapacityRow($event, $byProgram, withChallenge: false);
            }
        }

        $histogram = [];
        for ($n = 1; $n <= 25; $n++) {
            $histogram[] = $this->histogramRow($n, $explore, $challenge, $future8, $exploreNames, $challengeNames, $future8Names);
        }
        $histogram[] = $this->histogramRow('26+', $explore, $challenge, $future8, $exploreNames, $challengeNames, $future8Names, 26);

        return [
            'season_id' => $seasonId,
            'season_name' => (string) ($season->name ?? ''),
            'season_year' => $season->year ?? null,
            'event_count' => $events->count(),
            'histogram' => $histogram,
            'dual' => $this->sortEventRows($dual),
            'future_standalone' => $this->sortEventRows($futureStandalone),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $programs
     * @return array<int, array{draht_id: mixed, enrolled: int, capacity: int}>
     */
    private function indexPrograms(array $programs): array
    {
        $byProgram = [];
        foreach ($programs as $program) {
            $id = (int) ($program['first_program'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $teams = $program['teams'] ?? [];
            $byProgram[$id] = [
                'draht_id' => $program['draht_id'] ?? null,
                'enrolled' => is_array($teams) ? count($teams) : 0,
                'capacity' => (int) ($program['capacity'] ?? 0),
            ];
        }

        return $byProgram;
    }

    /**
     * @param  array<int, array{draht_id: mixed, enrolled: int, capacity: int}>  $byProgram
     */
    private function hasDrahtId(array $byProgram, int $programId): bool
    {
        $id = $byProgram[$programId]['draht_id'] ?? null;

        return $id !== null && $id !== '' && (int) $id > 0;
    }

    /**
     * @param  array<int, array{draht_id: mixed, enrolled: int, capacity: int}>  $byProgram
     */
    private function enrolledWithDraht(array $byProgram, int $programId): int
    {
        if (! $this->hasDrahtId($byProgram, $programId)) {
            return 0;
        }

        return (int) ($byProgram[$programId]['enrolled'] ?? 0);
    }

    /**
     * @param  array<int, array{draht_id: mixed, enrolled: int, capacity: int}>  $byProgram
     * @return array{enrolled: int, capacity: int, draht_id: int|null}
     */
    private function capacityRow(array $byProgram, int $programId): array
    {
        $row = $byProgram[$programId] ?? null;
        $drahtId = $row['draht_id'] ?? null;

        return [
            'enrolled' => (int) ($row['enrolled'] ?? 0),
            'capacity' => (int) ($row['capacity'] ?? 0),
            'draht_id' => ($drahtId !== null && $drahtId !== '' && (int) $drahtId > 0) ? (int) $drahtId : null,
        ];
    }

    /**
     * @param  array<int, int>  $buckets
     * @param  array<int, list<string>>  $names
     */
    private function bump(array &$buckets, array &$names, int $enrolled, string $eventName): void
    {
        if ($enrolled < 1) {
            return;
        }
        $key = $enrolled > 25 ? 26 : $enrolled;
        $buckets[$key]++;
        $names[$key][] = $eventName;
    }

    /**
     * @param  array<int, int>  $explore
     * @param  array<int, int>  $challenge
     * @param  array<int, int>  $future8
     * @param  array<int, list<string>>  $exploreNames
     * @param  array<int, list<string>>  $challengeNames
     * @param  array<int, list<string>>  $future8Names
     * @return array{teams: int|string, explore: int, challenge: int, future8: int, explore_events: list<string>, challenge_events: list<string>, future8_events: list<string>}
     */
    private function histogramRow(
        int|string $teams,
        array $explore,
        array $challenge,
        array $future8,
        array $exploreNames,
        array $challengeNames,
        array $future8Names,
        ?int $bucket = null,
    ): array {
        $key = $bucket ?? (int) $teams;

        return [
            'teams' => $teams,
            'explore' => $explore[$key],
            'challenge' => $challenge[$key],
            'future8' => $future8[$key],
            'explore_events' => $this->sortEventNames($exploreNames[$key]),
            'challenge_events' => $this->sortEventNames($challengeNames[$key]),
            'future8_events' => $this->sortEventNames($future8Names[$key]),
        ];
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function sortEventNames(array $names): array
    {
        usort($names, fn (string $a, string $b) => strnatcasecmp($a, $b));

        return array_values($names);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortEventRows(array $rows): array
    {
        usort(
            $rows,
            fn (array $a, array $b) => strnatcasecmp((string) $a['event_name'], (string) $b['event_name']),
        );

        return array_values($rows);
    }

    /**
     * @param  array<int, array{draht_id: mixed, enrolled: int, capacity: int}>  $byProgram
     * @return array<string, mixed>
     */
    private function eventCapacityRow(Event $event, array $byProgram, bool $withChallenge): array
    {
        $row = [
            'event_id' => (int) $event->id,
            'event_name' => (string) $event->name,
            'event_date' => $event->date,
            'regional_partner_id' => $event->regional_partner ? (int) $event->regional_partner : null,
            'future8' => $this->capacityRow($byProgram, FirstProgram::FUTURE_8->value),
        ];
        if ($withChallenge) {
            $row['challenge'] = $this->capacityRow($byProgram, FirstProgram::CHALLENGE->value);
        }

        return $row;
    }
}
