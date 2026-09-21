<?php

declare(strict_types=1);

namespace App\Print;

use App\Models\Event;
use App\Models\Team;

final class TeamPrintPeople
{
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
        $teams = Team::query()->where('event', $event->id)->get();
        $empty = [
            'coach_names' => '',
            'first_phone' => null,
            'coaches' => null,
            'players' => null,
        ];
        $result = array_fill_keys($teams->pluck('id')->all(), $empty);
        $payloads = DrahtTeamPeople::byTeamId($event);

        foreach ($teams as $team) {
            $teamData = $payloads[(int) $team->id] ?? null;
            if (! is_array($teamData)) {
                continue;
            }

            $result[$team->id] = self::fromTeamData($teamData);
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
}
