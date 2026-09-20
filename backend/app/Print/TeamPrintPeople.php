<?php

declare(strict_types=1);

namespace App\Print;

use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use App\Models\Team;

final class TeamPrintPeople
{
    public function __construct(
        private readonly DrahtController $drahtController,
    ) {}

    /**
     * @return array<int, array{coach_names: string, first_phone: ?string, coaches: ?int, players: ?int}>
     */
    public static function byTeamIdForEvent(Event $event): array
    {
        return app(self::class)->compute($event);
    }

    /**
     * @return array<int, array{coach_names: string, first_phone: ?string, coaches: ?int, players: ?int}>
     */
    private function compute(Event $event): array
    {
        $event->loadMissing('programs');
        $teams = Team::query()->where('event', $event->id)->get();
        $empty = [
            'coach_names' => '',
            'first_phone' => null,
            'coaches' => null,
            'players' => null,
        ];
        $result = array_fill_keys($teams->pluck('id')->all(), $empty);

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

                $result[$team->id] = self::fromTeamData($teamData);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $teamData
     * @return array{coach_names: string, first_phone: ?string, coaches: ?int, players: ?int}
     */
    private static function fromTeamData(array $teamData): array
    {
        $coaches = is_array($teamData['coaches'] ?? null) ? $teamData['coaches'] : [];
        $players = is_array($teamData['players'] ?? null) ? $teamData['players'] : [];
        $names = [];
        $firstPhone = null;
        foreach ($coaches as $coach) {
            if (is_array($coach)) {
                $name = trim((string) ($coach['firstname'] ?? '').' '.(string) ($coach['name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
                if ($firstPhone === null) {
                    $phone = trim((string) ($coach['phone'] ?? ''));
                    if ($phone !== '') {
                        $firstPhone = $phone;
                    }
                }
            } elseif (is_string($coach) && trim($coach) !== '') {
                $names[] = trim($coach);
            }
        }

        return [
            'coach_names' => implode(', ', $names),
            'first_phone' => $firstPhone,
            'coaches' => count($coaches),
            'players' => count($players),
        ];
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
