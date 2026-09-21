<?php

declare(strict_types=1);

namespace App\Print;

use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use App\Models\Team;
use App\Support\ProgramCatalog;

final class DrahtTeamPeople
{
    public function __construct(
        private readonly DrahtController $drahtController,
    ) {}

    /**
     * Full DRAHT team payloads keyed by team.id.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function byTeamId(Event $event): array
    {
        return app(self::class)->compute($event);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function compute(Event $event): array
    {
        $event->loadMissing('programs');
        $teams = Team::query()->where('event', $event->id)->get();
        $result = [];

        foreach ($event->programs as $program) {
            $firstProgramId = (int) ($program->first_program ?? 0);
            if ($firstProgramId < 1) {
                continue;
            }

            $drahtId = ProgramCatalog::drahtId($event, $firstProgramId);
            if ($drahtId === null || $drahtId < 1) {
                continue;
            }

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

                $result[(int) $team->id] = $teamData;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPeople(int $drahtEventId): ?array
    {
        try {
            $response = $this->drahtController->getPeople($drahtEventId);
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = $response->getData(true);

            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
