<?php

namespace App\Support;

use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use App\Models\Team;

final class TeamPeopleCounts
{
    public function __construct(
        private readonly DrahtController $drahtController,
    ) {}

    /**
     * @return array<int, int|null> team.id => people_count
     */
    public static function countsByTeamIdForEvent(Event $event): array
    {
        return app(self::class)->compute($event);
    }

    /**
     * @return array<int, int|null>
     */
    private function compute(Event $event): array
    {
        $event->loadMissing('programs');
        $teams = Team::query()->where('event', $event->id)->get();
        $result = array_fill_keys($teams->pluck('id')->all(), null);

        foreach ($event->programs as $program) {
            $drahtId = (int) ($program->draht_id ?? 0);
            if ($drahtId <= 0) {
                continue;
            }

            $firstProgramId = (int) ($program->first_program ?? 0);
            $programTeams = $teams->where('first_program', $firstProgramId);
            if ($programTeams->isEmpty()) {
                continue;
            }

            $peopleData = $this->fetchPeople($drahtId);
            if ($peopleData === null) {
                continue;
            }

            unset($peopleData['total_players'], $peopleData['total_coaches']);

            foreach ($programTeams as $team) {
                $hot = (int) ($team->team_number_hot ?? 0);
                if ($hot <= 0) {
                    continue;
                }

                $teamData = $peopleData[(string) $hot] ?? $peopleData[$hot] ?? null;
                if (! is_array($teamData)) {
                    continue;
                }

                $coaches = is_array($teamData['coaches'] ?? null) ? count($teamData['coaches']) : 0;
                $players = is_array($teamData['players'] ?? null) ? count($teamData['players']) : 0;
                $result[$team->id] = $coaches + $players;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPeople(int $drahtEventId): ?array
    {
        $response = $this->drahtController->getPeople($drahtEventId);
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->getData(true);

        return is_array($data) ? $data : null;
    }
}
