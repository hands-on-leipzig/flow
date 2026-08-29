<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

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
        $data = [
            'event_id' => $event->id,
            'level' => $level,
            'date' => $event->date,
            'days' => $event->days,
            'enddate' => $event->enddate,
            'address' => $drahtData['address'] ?? null,
            'contact' => $drahtData['contact'] ?? [],
            'teams' => [
                'lanes' => self::buildTeamLanes($event, $drahtData, $level),
            ],
        ];

        if ($level >= 3 && $plan !== null) {
            $data['plan'] = $plan;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $drahtData
     * @return list<array{program_id: int, name: string, sequence: int, color_hex: string|null, teams: list<array{ref: string|null, name: string}>}>
     */
    private static function buildTeamLanes(Event $event, array $drahtData, int $level): array
    {
        $event->loadMissing('programs');

        $programIds = $event->programs
            ->pluck('first_program')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        if ($programIds === []) {
            return [];
        }

        $drahtByProgram = [];
        foreach ($drahtData['programs'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $firstProgram = (int) ($row['first_program'] ?? 0);
            if ($firstProgram > 0) {
                $drahtByProgram[$firstProgram] = $row;
            }
        }

        $catalogRows = DB::table('m_first_program')
            ->whereIn('id', $programIds)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['id', 'name', 'display_name', 'sequence', 'color_hex']);

        $lanes = [];
        foreach ($catalogRows as $catalog) {
            $programId = (int) $catalog->id;
            $rawTeams = $drahtByProgram[$programId]['teams'] ?? [];
            if (! is_array($rawTeams)) {
                continue;
            }

            $teams = $level >= 1 ? self::normalizeTeams($rawTeams) : [];
            if ($teams === []) {
                continue;
            }

            $lanes[] = [
                'program_id' => $programId,
                'name' => (string) ($catalog->display_name ?: $catalog->name),
                'sequence' => (int) $catalog->sequence,
                'color_hex' => $catalog->color_hex !== null
                    ? (string) $catalog->color_hex
                    : ProgramCatalog::colorHex((string) $catalog->name),
                'teams' => $teams,
            ];
        }

        usort(
            $lanes,
            fn (array $a, array $b) => ($a['sequence'] <=> $b['sequence']) ?: ($a['program_id'] <=> $b['program_id'])
        );

        return $lanes;
    }

    /**
     * @return list<array{ref: string|null, name: string}>
     */
    private static function normalizeTeams(array $rawTeams): array
    {
        $teams = [];
        foreach (array_values($rawTeams) as $team) {
            if (! is_array($team)) {
                continue;
            }
            $name = trim((string) ($team['name'] ?? ''));
            $ref = $team['ref'] ?? $team['number'] ?? null;
            $ref = $ref === null || $ref === '' ? null : (string) $ref;
            if ($ref === null && $name === '') {
                continue;
            }
            $teams[] = [
                'ref' => $ref,
                'name' => $name,
            ];
        }

        return $teams;
    }
}
