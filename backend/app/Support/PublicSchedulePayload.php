<?php

namespace App\Support;

use App\Models\Event;

/**
 * Public scheduleInformation JSON from already-fetched DRAHT data and optional plan times.
 * Level gating matches PublishController::scheduleInformation.
 */
final class PublicSchedulePayload
{
    /**
     * @param  array<string, mixed>  $drahtData
     * @param  array<string, mixed>|null  $plan  importantTimes JSON when level >= 3
     * @return array<string, mixed>
     */
    public static function from(Event $event, array $drahtData, int $level, ?array $plan = null): array
    {
        $exploreColor = ProgramCatalog::colorHex('EXPLORE', '00A651');
        $challengeColor = ProgramCatalog::colorHex('CHALLENGE', 'ED1C24');

        $data = [
            'event_id' => $event->id,
            'level' => $level,
            'date' => $event->date,
            'days' => $event->days,
            'enddate' => $event->enddate,
            'address' => $drahtData['address'] ?? null,
            'contact' => $drahtData['contact'] ?? [],
            'teams' => [
                'explore' => [
                    'capacity' => $drahtData['capacity_explore'] ?? 0,
                    'registered' => count($drahtData['teams_explore'] ?? []),
                    'color_hex' => $exploreColor,
                    'list' => $level >= 1 ? array_map(function ($team) {
                        return [
                            'team_number_hot' => $team['ref'] ?? null,
                            'name' => $team['name'] ?? '',
                            'organization' => $team['organization'] ?? '',
                            'location' => $team['location'] ?? '',
                        ];
                    }, array_values($drahtData['teams_explore'] ?? [])) : [],
                ],
                'challenge' => [
                    'capacity' => $drahtData['capacity_challenge'] ?? 0,
                    'registered' => count($drahtData['teams_challenge'] ?? []),
                    'color_hex' => $challengeColor,
                    'list' => $level >= 1 ? array_map(function ($team) {
                        return [
                            'team_number_hot' => $team['ref'] ?? null,
                            'name' => $team['name'] ?? '',
                            'organization' => $team['organization'] ?? '',
                            'location' => $team['location'] ?? '',
                        ];
                    }, array_values($drahtData['teams_challenge'] ?? [])) : [],
                ],
            ],
        ];

        if ($level >= 3 && $plan !== null) {
            $data['plan'] = $plan;
        }

        return $data;
    }
}
