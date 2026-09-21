<?php

declare(strict_types=1);

namespace App\Print;

use App\Enums\FirstProgram;
use App\Models\Event;
use App\Services\EventTitleService;
use App\Services\TeamJuryAssignmentService;
use App\Support\ProgramCatalog;
use Illuminate\Support\Facades\DB;

final class TeamlisteAssembler
{
    public function __construct(
        private EventTitleService $titles,
        private TeamJuryAssignmentService $jury,
    ) {}

    /**
     * @return array{
     *     title_short: string,
     *     title_long: string,
     *     sections: list<array<string, mixed>>
     * }|null
     */
    public function assemble(int $planId): ?array
    {
        $plan = DB::table('plan')
            ->join('event', 'event.id', '=', 'plan.event')
            ->where('plan.id', $planId)
            ->select(
                'plan.id as plan_id',
                'plan.event as event_id',
                'event.name as event_name',
                'event.level as event_level',
            )
            ->first();
        if (! $plan) {
            return null;
        }

        $eventId = (int) $plan->event_id;
        $programs = $this->eventPrograms($eventId);
        $titles = $this->titles->titles($this->eventObject($plan, $programs));
        $event = Event::find($eventId);
        $people = $event ? TeamPrintPeople::byTeamIdForEvent($event) : [];

        $teams = DB::table('team_plan')
            ->join('team', 'team.id', '=', 'team_plan.team')
            ->leftJoin('room', 'team_plan.room', '=', 'room.id')
            ->where('team_plan.plan', $planId)
            ->orderBy('team.name')
            ->select([
                'team.id as team_id',
                'team.name as team_name',
                'team.team_number_hot',
                'team.first_program',
                'team_plan.team_number_plan',
                'team_plan.noshow',
                'room.name as room_name',
            ])
            ->get();

        $byProgram = [];
        foreach ($teams as $team) {
            $byProgram[(int) $team->first_program][] = $team;
        }

        $sections = [];
        foreach ($programs as $program) {
            $rows = $byProgram[$program['id']] ?? [];
            if ($rows === []) {
                continue;
            }
            $assignments = $this->jury->assignmentsForProgram($planId, $program['id']);
            $kind = FirstProgram::tryFrom($program['id']);
            $groupHeader = ($kind?->isExplore() ?? false)
                ? 'Gutachter:innen-Gruppe'
                : 'Jury-Gruppe';

            $printed = [];
            foreach ($rows as $team) {
                $teamId = (int) $team->team_id;
                $peopleRow = $people[$teamId] ?? [
                    'coach_names' => '',
                    'first_phone' => null,
                    'coaches' => null,
                    'players' => null,
                ];
                $planNo = (int) ($team->team_number_plan ?? 0);
                $group = ($planNo > 0 && isset($assignments[$planNo])) ? (string) $assignments[$planNo] : '';
                $room = trim((string) ($team->room_name ?? ''));
                $printed[] = [
                    'number' => $team->team_number_hot !== null && $team->team_number_hot !== ''
                        ? (string) $team->team_number_hot
                        : '',
                    'name' => (string) ($team->team_name ?? ''),
                    'noshow' => (bool) ($team->noshow ?? false),
                    'room' => $room !== '' ? $room : '–',
                    'group' => $group,
                    'coaches' => (string) ($peopleRow['coach_names'] ?? ''),
                    'phone' => (string) ($peopleRow['first_phone'] ?? ''),
                    'coach_count' => $peopleRow['coaches'] === null ? '' : (string) $peopleRow['coaches'],
                    'player_count' => $peopleRow['players'] === null ? '' : (string) $peopleRow['players'],
                ];
            }

            $sections[] = [
                'subject' => 'Teamliste',
                'color_hex' => EventPrintPdf::HOT_ORANGE,
                'display_name' => $program['display_name'],
                'official_name' => $program['official_name'] ?? $program['display_name'],
                'logo_stem' => $program['logo_stem'],
                'group_header' => $groupHeader,
                'rows' => $printed,
            ];
        }

        return [
            'title_short' => $titles['title_short'],
            'title_long' => $titles['title_long'],
            'sections' => $sections,
        ];
    }

    /**
     * @return list<array{id:int,display_name:string,name:string,logo_stem:?string,sequence:int}>
     */
    private function eventPrograms(int $eventId): array
    {
        $rows = DB::table('event_program as ep')
            ->join('m_first_program as fp', 'fp.id', '=', 'ep.first_program')
            ->where('ep.event', $eventId)
            ->orderBy('fp.sequence')
            ->orderBy('fp.id')
            ->get([
                'fp.id',
                'fp.name',
                'fp.display_name',
                'fp.sequence',
                'fp.logo_stem',
            ]);

        $programs = [];
        foreach ($rows as $row) {
            $display = trim((string) ($row->display_name ?? ''));
            $display = $display !== '' ? $display : (string) $row->name;
            $programs[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'display_name' => $display,
                'official_name' => ProgramCatalog::officialNameHtml((int) $row->id, $display),
                'sequence' => (int) $row->sequence,
                'logo_stem' => $row->logo_stem !== null && $row->logo_stem !== '' ? (string) $row->logo_stem : null,
            ];
        }

        return $programs;
    }

    /**
     * @param  list<array{id:int,display_name:string,name:string,logo_stem:?string,sequence:int}>  $programs
     */
    private function eventObject(object $plan, array $programs): object
    {
        $named = [];
        foreach ($programs as $program) {
            $named[] = (object) ['name' => $program['name']];
        }

        return (object) [
            'name' => $plan->event_name ?? '',
            'level' => $plan->event_level ?? 0,
            'programs' => $named,
        ];
    }
}
