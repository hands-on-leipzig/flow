<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\MSupportedPlan;
use App\Models\PlanParamValue;
use App\Models\Team;
use App\Models\TeamPlan;
use App\Models\FirstProgram;
use App\Services\PreviewMatrix;
use App\Services\GeneratePlan;
use App\Jobs\GeneratePlanJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PlanController extends Controller
{


    public function getOrCreatePlanForEvent($eventId): JsonResponse
    {
        // Plan suchen
        $plan = DB::table('plan')
            ->where('event', $eventId)
            ->select('id', 'name')
            ->first();

        // Wenn gefunden → zurückgeben
        if ($plan) {
            return response()->json($plan);
        }

        // Sonst anlegen
        $newId = DB::table('plan')->insertGetId([
            'name' => 'Zeitplan',
            'event' => $eventId,
            'created' => Carbon::now(),
            'last_change' => Carbon::now(),
            'public' => false
        ]);

        // Get DRAHT team counts for this event
        $event = \App\Models\Event::find($eventId);
        $e_teams = 0;
        $c_teams = 0;
        
        if ($event) {
            // Fetch DRAHT data for this event
            $drahtController = new \App\Http\Controllers\Api\DrahtController();
            $drahtData = $drahtController->show($event);
            $data = $drahtData->getData(true);
            
            // Count teams from DRAHT
            $e_teams = count($data->teams_explore ?? []);
            $c_teams = count($data->teams_challenge ?? []);
        }
        
        // Fallback to minimum values if no DRAHT data
        if ($e_teams === 0) $e_teams = 1;
        if ($c_teams === 0) $c_teams = 1;

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 6],   // e_teams
            ['set_value' => $e_teams]
        );
        

        // Minimale parameter für einen gültigen Plan

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 22],    // c_teams
            ['set_value' => $c_teams]
        );

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 23],    // j_lanes
            ['set_value' => MSupportedPlan::where('first_program', 3)->where('teams', $c_teams)->value('lanes')]
        );
        
        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 24],  // r_tables
            ['set_value' => MSupportedPlan::where('first_program', 3)->where('teams', $c_teams)->value('tables')]
        );

        // Populate team_plan table with all teams for this event
        $this->populateTeamPlanForNewPlan($newId, $eventId);

        return response()->json([
            'id' => $newId,
            'name' => 'Zeitplan'
        ]);
    }

    public function generate($planId, $async = false): JsonResponse
    {
        
        $plan = Plan::find($planId);
        if (!$plan) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $plan->generator_status = 'running';
        $plan->save();

        // Note the start
        DB::table('s_generator')->insertGetId([
            'plan' => $planId,
            'start' => \Carbon\Carbon::now(),
            'mode' => $async ? 'job' : 'direct',
        ]);


        if ($async) {
        
            log::info("Plan {$planId}: Generation dispatched");
            
            GeneratePlanJob::dispatch($planId);

            return response()->json(['message' => 'Generation dispatched']);

        } else {

            log::info("Plan {$planId}: Generation started");

            GeneratePlan::run($plan->id);
            
            $plan->generator_status = 'done';
            $plan->save();

            return response()->json(['message' => 'Generation done']);    
        }    

    }

    public function status($planId): JsonResponse
    {
        $plan = Plan::find($planId);
        if (!$plan) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        return response()->json(['status' => $plan->generator_status]);
    }



    //
    // Preview in frontend
    // 

    public function previewRoles(int $plan, PreviewMatrix $builder)
    {
        $activities = $this->fetchActivities($plan, includeRooms: false);

        if ($activities->isEmpty()) {
            // Return stable headers so the frontend can render an empty grid
            return [ ['key' => 'time', 'title' => 'Zeit'],];
        }

        $matrix = $builder->buildRolesMatrix($activities);
        return response()->json($matrix);
    }

    public function previewTeams(int $plan, PreviewMatrix $builder)
    {
        $activities = $this->fetchActivities($plan, includeRooms: false);

        if ($activities->isEmpty()) {
            // Return stable headers so the frontend can render an empty grid
            return [ ['key' => 'time', 'title' => 'Zeit'],];
        }

        $matrix = $builder->buildTeamsMatrix($activities);
        return response()->json($matrix);
    }

    public function previewRooms(int $plan, PreviewMatrix $builder)
    {
        $activities = $this->fetchActivities($plan, includeRooms: true);

        if ($activities->isEmpty()) {
            // Return stable headers so the frontend can render an empty grid
            return [ ['key' => 'time', 'title' => 'Zeit'],];
        }

        $matrix = $builder->buildRoomsMatrix($activities);
        return response()->json($matrix);
    }




    private function fetchActivities(
        int $plan,
        bool $includeRooms = false,
        bool $includeGroupMeta = false,
        bool $includeActivityMeta = false,
        bool $includeTeamNames = false
    ) {
        $freeIds = array_values(array_filter(array_map(function ($c) {
            if (is_string($c) && defined($c)) return (int) constant($c);
            if (is_numeric($c)) return (int) $c;
            return null;
        }, (array) config('atd.free')), fn ($v) => $v !== null));

        $q = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            // Activity-ATD
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->leftJoin('m_first_program as fp', 'atd.first_program', '=', 'fp.id')
            // Group-ATD (optional)
            ->when($includeGroupMeta, function ($qq) {
                $qq->leftJoin('m_activity_type_detail as ag_atd', 'ag_atd.id', '=', 'ag.activity_type_detail')
                ->leftJoin('m_first_program as ag_fp', 'ag_fp.id', '=', 'ag_atd.first_program');
            })
            ->leftJoin('extra_block as peb', 'a.extra_block', '=', 'peb.id')
            ->join('plan as p', 'p.id', '=', 'ag.plan')
            ->where('ag.plan', $plan);

        if (!empty($freeIds)) {
            $q->whereNotIn('atd.id', $freeIds);
        }

        // Räume (optional)
        if ($includeRooms) {
            $q->leftJoin('m_room_type as rt', 'a.room_type', '=', 'rt.id')
            ->leftJoin('room_type_room as rtr', function ($j) {
                $j->on('rtr.room_type', '=', 'a.room_type')
                    ->on('rtr.event', '=', 'p.event');
            })
            ->leftJoin('room as r', function ($j) {
                $j->on('r.id', '=', 'rtr.room')
                    ->on('r.event', '=', 'p.event');
            });
        }

        // Team-Namen (optional): team_plan → team
        if ($includeTeamNames) {
            // Jury-Team
            $q->leftJoin('team_plan as tp_j', function($j) {
                $j->on('tp_j.plan', '=', 'p.id')
                    ->on('tp_j.team_number_plan', '=', 'a.jury_team');
            })
            ->leftJoin('team as t_j', function($j) {
                $j->on('t_j.id', '=', 'tp_j.team')
                    ->on('t_j.event', '=', 'p.event')
                    ->on('t_j.first_program', '=', 'atd.first_program');
            });

            // Table 1
            $q->leftJoin('team_plan as tp_t1', function($j) {
                $j->on('tp_t1.plan', '=', 'p.id')
                    ->on('tp_t1.team_number_plan', '=', 'a.table_1_team');
            })
            ->leftJoin('team as t_t1', function($j) {
                $j->on('t_t1.id', '=', 'tp_t1.team')
                    ->on('t_t1.event', '=', 'p.event')
                    ->on('t_t1.first_program', '=', 'atd.first_program');
            });

            // Table 2
            $q->leftJoin('team_plan as tp_t2', function($j) {
                $j->on('tp_t2.plan', '=', 'p.id')
                    ->on('tp_t2.team_number_plan', '=', 'a.table_2_team');
            })
            ->leftJoin('team as t_t2', function($j) {
                $j->on('t_t2.id', '=', 'tp_t2.team')
                    ->on('t_t2.event', '=', 'p.event')
                    ->on('t_t2.first_program', '=', 'atd.first_program');
            });
        }

        // Basisselektion
        $select = '
            a.id as activity_id,
            ag.id as activity_group_id,
            a.start as start_time,
            a.`end` as end_time,
            COALESCE(peb.name, atd.name_preview) as activity_name,
            atd.id as activity_type_detail_id,
            fp.name as program_name,
            a.jury_lane as lane,
            a.jury_team as team,
            a.table_1 as table_1,
            a.table_1_team as table_1_team,
            a.table_2 as table_2,
            a.table_2_team as table_2_team
        ';

        if ($includeRooms) {
            $select .= ',
                p.event as event_id,
                a.room_type as room_type_id,
                rt.name as room_type_name,
                rt.sequence as room_type_sequence,
                r.id as room_id,
                r.name as room_name
            ';
        }

        if ($includeActivityMeta) {
            $select .= ',
                atd.name          as activity_atd_name,
                atd.first_program as activity_first_program_id,
                fp.name           as activity_first_program_name,
                atd.description   as activity_description
            ';
        }

        if ($includeGroupMeta) {
            $select .= ',
                ag_atd.name          as group_atd_name,
                ag_atd.first_program as group_first_program_id,
                ag_fp.name           as group_first_program_name,
                ag_atd.description   as group_description
            ';
        }

        if ($includeTeamNames) {
            $select .= ',
                t_j.name  as jury_team_name,
                t_t1.name as table_1_team_name,
                t_t2.name as table_2_team_name
            ';
        }

        if ($includeRooms) {
            $q->orderBy('rt.sequence')->orderBy('r.name');
        }

        return $q->orderBy('a.start')->selectRaw($select)->get();
    }

    //
    // Detailed activities list
    //

    public function activities(int $planId): \Illuminate\Http\JsonResponse
    {
        // Aktivitäten laden (ohne Räume)
        $rows = $this->fetchActivities($planId, false);

        // Nach Activity-Group gruppieren
        $groups = [];
        foreach ($rows as $row) {
            $gid = $row->activity_group_id ?? null;

            if (!isset($groups[$gid])) {
                $groups[$gid] = [
                    'activity_group_id' => $gid,
                    'activities' => [],
                ];
            }

            $groups[$gid]['activities'][] = [
                'activity_id'      => $row->activity_id,
                'start_time'       => $row->start_time,   // ISO/DB-Format; Frontend formatiert
                'end_time'         => $row->end_time,
                'program'          => $row->program_name, // z.B. CHALLENGE / EXPLORE (falls befüllt)
                'activity_name'    => $row->activity_name,
                'lane'             => $row->lane,
                'team'             => $row->team,
                'table_1'          => $row->table_1,
                'table_1_team'     => $row->table_1_team,
                'table_2'          => $row->table_2,
                'table_2_team'     => $row->table_2_team,
            ];
        }

        // Indexe bereinigen
        $groups = array_values($groups);

        return response()->json([
            'plan_id' => $planId,
            'groups'  => $groups,
        ]);
    }

  

    public function actionNow(int $planId, Request $req): JsonResponse
    {
        $pivot = $this->resolvePivotTime($req); // UTC

        $rows = $this->fetchActivities(
            $planId,
            includeRooms: false,
            includeGroupMeta: true,
            includeActivityMeta: true
        );

        // Sichtbarkeit: nur ATDs, die für Rolle 14 (Publikum) erlaubt sind
        $allowedAtdIds = DB::table('m_visibility')
            ->where('role', 14)
            ->pluck('activity_type_detail')
            ->unique()
            ->all();

        $rows = $rows->filter(function ($r) use ($allowedAtdIds) {
            return in_array((int)$r->activity_type_detail_id, $allowedAtdIds, true);
        });

        // Zeitfilter: start <= pivot AND end > pivot
        $rows = $rows->filter(function ($r) use ($pivot) {
            return \Carbon\Carbon::parse($r->start_time, 'UTC') <= $pivot
                && \Carbon\Carbon::parse($r->end_time, 'UTC')   >  $pivot;
        });

        return response()->json($this->groupActivitiesForApi($planId, $rows));
    }

    public function actionNext(int $planId, Request $req): JsonResponse
    {
        $pivot = $this->resolvePivotTime($req); // UTC
        $interval = (int) $req->query('interval', 30);
        $from = $pivot->copy();
        $to   = $pivot->copy()->addMinutes($interval);

        $rows = $this->fetchActivities(
            $planId,
            includeRooms: false,
            includeGroupMeta: true,
            includeActivityMeta: true
        );

        $allowedAtdIds = DB::table('m_visibility')
            ->where('role', 14)
            ->pluck('activity_type_detail')
            ->unique()
            ->all();

        $rows = $rows->filter(fn($r) => in_array((int)$r->activity_type_detail_id, $allowedAtdIds, true));

        // Zeitfenster: Start innerhalb [from, to)
        $rows = $rows->filter(function ($r) use ($from, $to) {
            $s = \Carbon\Carbon::parse($r->start_time, 'UTC');
            return $s >= $from && $s < $to;
        });

        return response()->json([
            'plan_id'    => $planId,
            'pivot_time_utc' => $pivot->toIso8601String(),
            'window_utc' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'groups'     => $this->groupActivitiesForApi($planId, $rows)['groups'],
        ]);
    }


private function resolvePivotTime(Request $req): \Carbon\Carbon
{
    $pit = trim((string)$req->query('point_in_time', ''));
    if ($pit !== '') {
        // Explizit deutsche Zeitzone interpretieren (inkl. Sommer/Winterzeit)
        return \Carbon\Carbon::parse($pit, 'Europe/Berlin')->utc();
    }
    return \Carbon\Carbon::now('UTC');
}

/**
 * Gemeinsame Gruppierung + Ausgabeform für now/next.
 */
private function groupActivitiesForApi(int $planId, $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $gid = $row->activity_group_id ?? null;

        if (!isset($groups[$gid])) {
            $groups[$gid] = [
                'activity_group_id' => $gid,
                'group_meta' => [
                    'name'                   => $row->group_atd_name ?? null,
                    'first_program_id'       => $row->group_first_program_id ?? null,
                    'first_program_name'     => $row->group_first_program_name ?? null,
                    'description'            => $row->group_description ?? null,
                ],
                'activities' => [],
            ];
        }

        $groups[$gid]['activities'][] = [
            'activity_id'      => $row->activity_id,
            'start_time'       => $row->start_time,
            'end_time'         => $row->end_time,
            'activity_name'    => $row->activity_name,
            // Activity-ATD-Meta:
            'meta' => [
                'name'               => $row->activity_atd_name ?? null,
                'first_program_id'   => $row->activity_first_program_id ?? null,
                'first_program_name' => $row->activity_first_program_name ?? null,
                'description'        => $row->activity_description ?? null,
            ],
            'program'          => $row->program_name, // bleibt zur Abwärtskompatibilität
            'lane'             => $row->lane,
            'team'             => $row->team,
            'table_1'          => $row->table_1,
            'table_1_team'     => $row->table_1_team,
            'table_2'          => $row->table_2,
            'table_2_team'     => $row->table_2_team,
        ];
    }

    return [
        'plan_id' => $planId,
        'groups'  => array_values($groups),
    ];
}

    /**
     * Populate team_plan table for a newly created plan
     * Ensures every team for the event has an entry in team_plan
     */
    private function populateTeamPlanForNewPlan($planId, $eventId)
    {
        // Get all teams for this event
        $teams = Team::where('event', $eventId)->get();
        
        if ($teams->isEmpty()) {
            return; // No teams to add
        }

        // Group teams by program and assign order
        $exploreTeams = $teams->where('first_program', 2)->values(); // Explore = 2
        $challengeTeams = $teams->where('first_program', 3)->values(); // Challenge = 3

        $teamPlanEntries = [];

        // Add explore teams with order
        foreach ($exploreTeams as $index => $team) {
            $teamPlanEntries[] = [
                'team' => $team->id,
                'plan' => $planId,
                'team_number_plan' => $index + 1,
                'room' => null
            ];
        }

        // Add challenge teams with order (continuing from explore teams)
        $challengeStartOrder = $exploreTeams->count() + 1;
        foreach ($challengeTeams as $index => $team) {
            $teamPlanEntries[] = [
                'team' => $team->id,
                'plan' => $planId,
                'team_number_plan' => $challengeStartOrder + $index,
                'room' => null
            ];
        }

        // Insert all team_plan entries
        if (!empty($teamPlanEntries)) {
            TeamPlan::insert($teamPlanEntries);
        }
    }

    /**
     * Ensure all teams for an event have entries in team_plan for existing plans
     * This handles cases where teams were added after plan creation
     */
    public function syncTeamPlanForEvent($eventId)
    {
        $plans = Plan::where('event', $eventId)->get();
        
        if ($plans->isEmpty()) {
            return; // No plans to sync
        }

        foreach ($plans as $plan) {
            $this->syncTeamPlanForPlan($plan->id, $eventId);
        }
    }

    /**
     * Sync team_plan entries for a specific plan
     */
    private function syncTeamPlanForPlan($planId, $eventId)
    {
        // Get all teams for this event
        $teams = Team::where('event', $eventId)->get();
        
        if ($teams->isEmpty()) {
            return;
        }

        // Get existing team_plan entries for this plan
        $existingTeamIds = TeamPlan::where('plan', $planId)
            ->pluck('team')
            ->toArray();

        // Find teams that don't have team_plan entries
        $missingTeams = $teams->whereNotIn('id', $existingTeamIds);

        if ($missingTeams->isEmpty()) {
            return; // All teams already have entries
        }

        // Get the highest current team_number_plan for this plan
        $maxOrder = TeamPlan::where('plan', $planId)
            ->max('team_number_plan') ?? 0;

        // Add missing teams with sequential order
        $teamPlanEntries = [];
        foreach ($missingTeams as $index => $team) {
            $teamPlanEntries[] = [
                'team' => $team->id,
                'plan' => $planId,
                'team_number_plan' => $maxOrder + $index + 1,
                'room' => null
            ];
        }

        // Insert missing team_plan entries
        if (!empty($teamPlanEntries)) {
            TeamPlan::insert($teamPlanEntries);
        }
    }

}
