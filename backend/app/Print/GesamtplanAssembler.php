<?php

declare(strict_types=1);

namespace App\Print;

use App\Services\ActivityFetcherService;
use App\Services\EventTitleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class GesamtplanAssembler
{
    /** @var list<string> */
    private const SLOT_CODES = ['e_slot_block', 'c_slot_block', 'f8_slot_block', 'g_slot_block'];

    public function __construct(
        private ActivityFetcherService $fetcher,
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
        $rows = $this->fetcher->fetchActivities(
            $planId,
            [],
            true,
            true,
            true,
            true,
            true,
            true,
        );
        $teamLookup = $this->teamLookup($this->assignedTeams($planId));

        $seen = [];
        $items = [];
        foreach ($rows as $row) {
            $activityId = (int) ($row->activity_id ?? 0);
            if ($activityId > 0) {
                if (isset($seen[$activityId])) {
                    continue;
                }
                $seen[$activityId] = true;
            }
            $items[] = $this->withSlotTeam($row, $teamLookup);
        }

        usort($items, function (object $a, object $b): int {
            return strcmp((string) ($a->start_time ?? ''), (string) ($b->start_time ?? ''));
        });

        $ablauf = [];
        $extra = [];
        $hints = [];
        foreach ($items as $row) {
            $printed = $this->row($row);
            if (($row->extra_block_type ?? null) === 'free') {
                $extra[] = $printed;
            } else {
                $ablauf[] = $printed;
            }
            $this->rememberHint($hints, $row);
        }

        $sections = [];
        if ($ablauf !== [] || $extra !== []) {
            $section = [
                'subject' => 'Gesamtplan',
                'color_hex' => EventPrintPdf::HOT_ORANGE,
                'logo_stem' => null,
                'noshow' => false,
                'ablauf' => $ablauf,
            ];
            if ($extra !== []) {
                $section['zusaetzlich'] = $extra;
            }
            if ($hints !== []) {
                $section['hinweise'] = array_values($hints);
            }
            $sections[] = $section;
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
            $programs[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'display_name' => $display !== '' ? $display : (string) $row->name,
                'sequence' => (int) $row->sequence,
                'logo_stem' => $row->logo_stem !== null && $row->logo_stem !== '' ? (string) $row->logo_stem : null,
            ];
        }

        return $programs;
    }

    /**
     * @return Collection<int, object>
     */
    private function assignedTeams(int $planId): Collection
    {
        return DB::table('team_plan')
            ->join('team', 'team.id', '=', 'team_plan.team')
            ->where('team_plan.plan', $planId)
            ->select([
                'team_plan.team_number_plan',
                'team_plan.noshow',
                'team.name',
                'team.team_number_hot',
                'team.first_program',
            ])
            ->get();
    }

    /**
     * @param  Collection<int, object>  $teams
     * @return array<int, array<int, object>>
     */
    private function teamLookup(Collection $teams): array
    {
        $map = [];
        foreach ($teams as $team) {
            $program = (int) ($team->first_program ?? 0);
            $number = (int) ($team->team_number_plan ?? 0);
            $map[$program][$number] = $team;
        }

        return $map;
    }

    /**
     * @param  array<int, array<int, object>>  $teamLookup
     */
    private function withSlotTeam(object $row, array $teamLookup): object
    {
        $code = (string) ($row->activity_type_code ?? '');
        if (! in_array($code, self::SLOT_CODES, true)) {
            return $row;
        }
        $slot = isset($row->slot_team) && $row->slot_team !== null && $row->slot_team !== ''
            ? (int) $row->slot_team
            : 0;
        $program = isset($row->activity_first_program_id) && $row->activity_first_program_id !== null
            && $row->activity_first_program_id !== ''
            ? (int) $row->activity_first_program_id
            : 0;
        $hit = $teamLookup[$program][$slot] ?? null;
        if ($hit === null && $slot > 0) {
            foreach ($teamLookup as $byNumber) {
                if (isset($byNumber[$slot])) {
                    $hit = $byNumber[$slot];
                    break;
                }
            }
        }
        $row->slot_team_name = $hit->name ?? '';

        return $row;
    }

    /**
     * @return array{start:string,end:string,room:string,action:string,strike:list<string>,italic:list<string>}
     */
    private function row(object $row): array
    {
        $action = trim((string) ($row->activity_atd_name ?? ''));
        $names = [];
        foreach (['jury_team_name', 'table_1_team_name', 'table_2_team_name', 'slot_team_name'] as $field) {
            $name = trim((string) ($row->{$field} ?? ''));
            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        if (count($names) === 1) {
            $action = $action === '' ? $names[0] : $action.' — '.$names[0];
        }

        return [
            'start' => self::clock($row->start_time ?? null),
            'end' => self::clock($row->end_time ?? null),
            'room' => trim((string) ($row->room_name ?? '')),
            'action' => $action,
            'strike' => [],
            'italic' => [],
        ];
    }

    /**
     * @param  array<string, array{room:string,hint:string,inaccessible:bool}>  $hints
     */
    private function rememberHint(array &$hints, object $row): void
    {
        $name = trim((string) ($row->room_name ?? ''));
        $hint = trim((string) ($row->room_navigation ?? ''));
        $accessible = $row->room_is_accessible ?? true;
        $isAccessible = $accessible != false;
        if ($name === '' || ($hint === '' && $isAccessible)) {
            return;
        }
        $hints[$name] = ['room' => $name, 'hint' => $hint, 'inaccessible' => ! $isAccessible];
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

    private static function clock(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }
        if (preg_match('/(\d{1,2}:\d{2})/', $value, $match)) {
            $parts = explode(':', $match[1]);

            return sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
        }

        return '';
    }
}
