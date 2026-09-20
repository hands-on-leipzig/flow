<?php

declare(strict_types=1);

namespace App\Print;

use App\Enums\FirstProgram;
use App\Services\EventTitleService;
use Illuminate\Support\Facades\DB;

final class MatchPlanScoreAssembler
{
    public function __construct(
        private EventTitleService $titles,
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

        $programs = $this->eventPrograms((int) $plan->event_id);
        $titles = $this->titles->titles($this->eventObject($plan, $programs));

        $teamPlanMap = DB::table('team_plan')
            ->join('team', 'team_plan.team', '=', 'team.id')
            ->where('team_plan.plan', $planId)
            ->where('team.first_program', FirstProgram::CHALLENGE->value)
            ->select(
                'team_plan.team_number_plan',
                'team.id as team_id',
                'team.name as team_name',
                'team.team_number_hot',
                'team_plan.noshow'
            )
            ->get()
            ->keyBy('team_number_plan');

        $rounds = [];
        for ($round = 1; $round <= 3; $round++) {
            $matches = DB::table('match')
                ->where('match.plan', $planId)
                ->where('match.round', $round)
                ->orderBy('match.match_no')
                ->get();

            $matchData = [];
            foreach ($matches as $match) {
                $matchData[] = [
                    'match_no' => $match->match_no,
                    'team_1' => self::teamFromMap($teamPlanMap, (int) $match->table_1_team),
                    'team_2' => self::teamFromMap($teamPlanMap, (int) $match->table_2_team),
                ];
            }
            $rounds[] = [
                'label' => 'Vorrunde '.$round,
                'matches' => $matchData,
            ];
        }

        return [
            'title_short' => $titles['title_short'],
            'title_long' => $titles['title_long'],
            'sections' => [[
                'subject' => 'Match-Plan SCORE',
                'rounds' => $rounds,
            ]],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, object>  $teamPlanMap
     * @return array{name:string,hot_number:mixed,noshow:bool}|null
     */
    private static function teamFromMap($teamPlanMap, int $slot): ?array
    {
        if ($slot <= 0 || ! isset($teamPlanMap[$slot])) {
            return null;
        }
        $team = $teamPlanMap[$slot];

        return [
            'name' => (string) $team->team_name,
            'hot_number' => $team->team_number_hot,
            'noshow' => (bool) ($team->noshow ?? false),
        ];
    }

    /**
     * @return list<array{id:int,display_name:string,name:string}>
     */
    private function eventPrograms(int $eventId): array
    {
        $rows = DB::table('event_program as ep')
            ->join('m_first_program as fp', 'fp.id', '=', 'ep.first_program')
            ->where('ep.event', $eventId)
            ->orderBy('fp.sequence')
            ->orderBy('fp.id')
            ->get(['fp.id', 'fp.name', 'fp.display_name']);

        $programs = [];
        foreach ($rows as $row) {
            $display = trim((string) ($row->display_name ?? ''));
            $programs[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'display_name' => $display !== '' ? $display : (string) $row->name,
            ];
        }

        return $programs;
    }

    /**
     * @param  list<array{id:int,display_name:string,name:string}>  $programs
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
