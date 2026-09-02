<?php

namespace App\Support;

use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use App\Models\Team;
use Illuminate\Support\Collection;

final class TeamCoachLookup
{
    public function __construct(
        private readonly DrahtController $drahtController,
    ) {}

    /**
     * @return Collection<int, Team>
     */
    public static function teamsForEmail(Event $event, string $email): Collection
    {
        return app(self::class)->resolve($event, $email);
    }

    /**
     * @return Collection<int, Team>
     */
    private function resolve(Event $event, string $email): Collection
    {
        $needle = strtolower(trim($email));
        if ($needle === '') {
            return collect();
        }

        $event->loadMissing('programs');
        $teams = Team::query()->where('event', $event->id)->get();
        $matched = collect();

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

                if ($this->teamHasCoachEmail($teamData, $needle)) {
                    $matched->push($team);
                }
            }
        }

        return $matched->unique('id')->values();
    }

    /**
     * @param  array<string, mixed>  $teamData
     */
    private function teamHasCoachEmail(array $teamData, string $needleEmail): bool
    {
        $coaches = $teamData['coaches'] ?? null;
        if (! is_array($coaches)) {
            return false;
        }

        foreach ($coaches as $coach) {
            if (! is_array($coach)) {
                continue;
            }
            $email = strtolower(trim((string) ($coach['email'] ?? '')));
            if ($email !== '' && $email === $needleEmail) {
                return true;
            }
        }

        return false;
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
