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
     *     histogram: list<array{teams: int|string, explore: int, challenge: int, future8: int}>,
     *     dual: list<array<string, mixed>>
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

        $dual = [];

        $events = Event::query()
            ->where('season', $seasonId)
            ->orderBy('date')
            ->orderBy('name')
            ->get();

        foreach ($events as $event) {
            $payload = $this->draht->fetchScheduleData($event)['data'] ?? [];
            $byProgram = $this->indexPrograms($payload['programs'] ?? []);

            $exploreEnrolled = $this->enrolledWithDraht($byProgram, FirstProgram::EXPLORE->value)
                + $this->enrolledWithDraht($byProgram, FirstProgram::DISCOVER->value);
            $challengeEnrolled = $this->enrolledWithDraht($byProgram, FirstProgram::CHALLENGE->value);
            $future8Enrolled = $this->enrolledWithDraht($byProgram, FirstProgram::FUTURE_8->value);

            if ($this->hasDrahtId($byProgram, FirstProgram::EXPLORE->value)
                || $this->hasDrahtId($byProgram, FirstProgram::DISCOVER->value)) {
                $this->bump($explore, $exploreEnrolled);
            }
            if ($this->hasDrahtId($byProgram, FirstProgram::CHALLENGE->value)) {
                $this->bump($challenge, $challengeEnrolled);
            }
            if ($this->hasDrahtId($byProgram, FirstProgram::FUTURE_8->value)) {
                $this->bump($future8, $future8Enrolled);
            }

            $attached = $event->programs
                ->pluck('first_program')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (in_array(FirstProgram::CHALLENGE->value, $attached, true)
                && in_array(FirstProgram::FUTURE_8->value, $attached, true)) {
                $dual[] = [
                    'event_id' => (int) $event->id,
                    'event_name' => (string) $event->name,
                    'event_date' => $event->date,
                    'regional_partner_id' => $event->regional_partner ? (int) $event->regional_partner : null,
                    'challenge' => $this->capacityRow($byProgram, FirstProgram::CHALLENGE->value),
                    'future8' => $this->capacityRow($byProgram, FirstProgram::FUTURE_8->value),
                ];
            }
        }

        $histogram = [];
        for ($n = 1; $n <= 25; $n++) {
            $histogram[] = [
                'teams' => $n,
                'explore' => $explore[$n],
                'challenge' => $challenge[$n],
                'future8' => $future8[$n],
            ];
        }
        $histogram[] = [
            'teams' => '26+',
            'explore' => $explore[26],
            'challenge' => $challenge[26],
            'future8' => $future8[26],
        ];

        return [
            'season_id' => $seasonId,
            'season_name' => (string) ($season->name ?? ''),
            'season_year' => $season->year ?? null,
            'event_count' => $events->count(),
            'histogram' => $histogram,
            'dual' => $dual,
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
     */
    private function bump(array &$buckets, int $enrolled): void
    {
        if ($enrolled < 1) {
            return;
        }
        if ($enrolled > 25) {
            $buckets[26]++;

            return;
        }
        $buckets[$enrolled]++;
    }
}
