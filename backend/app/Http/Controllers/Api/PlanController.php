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
            ->select('id')
            ->first();

        if ($plan) {
            // Prüfen, ob es mindestens eine activity_group für diesen Plan gibt
            $hasActivityGroup = DB::table('activity_group')
                ->where('plan', $plan->id)
                ->exists();

            return response()->json([
                'id' => $plan->id,
                'existing' => $hasActivityGroup,  // true nur, wenn activity_group existiert
            ]);
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

        $e_teams = 6; // Default
        $c_teams = 8; // Default

        if ($event) {
            $drahtController = new \App\Http\Controllers\Api\DrahtController();
            $drahtData = $drahtController->show($event);
            $data = $drahtData->getData(true);


            if ($data) {
                if (array_key_exists('capacity_explore', $data)) {
                    $e_teams = (int) $data['capacity_explore'];
                }
                if (array_key_exists('capacity_challenge', $data)) {
                    $c_teams = (int) $data['capacity_challenge'];
                }
            }
        }

        // Max one explore group
        $e2_teams = 0;
        $e2_lanes = 0;


        if ( $e_teams > 0 ) {

            if ( $c_teams  == 0 ) {
                // e_mode standlone morning
                $e_mode = 3;
            } else {
                // e_mode integrated morning
                $e_mode = 1;
            }

            $e1_teams = $e_teams;           
            $e1_lanes = MSupportedPlan::where('first_program', 2)->where('teams', $e_teams)->value('lanes');
           
        } else { 

            // e_mode off
            $e_mode = 0;

            $e1_teams = 0;
            $e1_lanes = 0;            
            
        }
        

        if ( $c_teams > 0 ) { 

            // c_mode on
            $c_mode = 1;

            $j_lanes = MSupportedPlan::where('first_program', 3)->where('teams', $c_teams)->value('lanes');
            $r_tables = MSupportedPlan::where('first_program', 3)->where('teams', $c_teams)->value('tables');  
            
        } else {

            // c_mode off
            $c_mode = 0;   
            $j_lanes = 0;
            $r_tables = 0; 

        }

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 7],   
            ['set_value' => $e_mode]);

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 6],   
            ['set_value' => $e_teams]);

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 111],   
            ['set_value' => $e1_teams]);

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 81],   
            ['set_value' => $e1_lanes]);

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 112],   
            ['set_value' => $e2_teams]);

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 117],   
            ['set_value' => $e2_lanes]);

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 122],   
            ['set_value' => $c_mode]);

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 22],   
            ['set_value' => $c_teams]);

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 23],   
            ['set_value' => $j_lanes]);

        PlanParamValue::updateOrCreate(
            ['plan' => $newId, 'parameter' => 24],   
            ['set_value' => $r_tables]);


            // Populate team_plan table with all teams for this event
        Log::info("Creating plan $newId for event $eventId - calling populateTeamPlanForNewPlan");
        $this->populateTeamPlanForNewPlan($newId, $eventId);

        return response()->json([
            'id' => $newId,
            'existing' => false,
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
        $activities = $this->fetchActivities($plan, freeBlocks: false);

        if ($activities->isEmpty()) {
            // Return stable headers so the frontend can render an empty grid
            return [ ['key' => 'time', 'title' => 'Zeit'],];
        }

        $matrix = $builder->buildRolesMatrix($activities);
        return response()->json($matrix);
    }

    public function previewTeams(int $plan, PreviewMatrix $builder)
    {
        $activities = $this->fetchActivities($plan, freeBlocks: false);

        if ($activities->isEmpty()) {
            // Return stable headers so the frontend can render an empty grid
            return [ ['key' => 'time', 'title' => 'Zeit'],];
        }

        $matrix = $builder->buildTeamsMatrix($activities);
        return response()->json($matrix);
    }

    public function previewRooms(int $plan, PreviewMatrix $builder)
    {
        $activities = $this->fetchActivities($plan, includeRooms: true, freeBlocks: false);

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
    bool $includeTeamNames = false,
    bool $freeBlocks = true   // NEU: default = true
    ) {

        $q = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->leftJoin('m_first_program as fp', 'atd.first_program', '=', 'fp.id')
            ->leftJoin('extra_block as peb', 'a.extra_block', '=', 'peb.id')
            ->join('plan as p', 'p.id', '=', 'ag.plan')
            ->where('ag.plan', $plan);

        // Free-Blocks filtern (optional)
        if (!$freeBlocks) {
            $q->where(function ($sub) {
                $sub->whereNull('a.extra_block')   // normale Activities
                    ->orWhereNotNull('peb.insert_point'); // Extra-Blocks mit insert_point
            });
        }

        // Group-Meta (optional)
        if ($includeGroupMeta) {
            $q->leftJoin('m_activity_type_detail as ag_atd', 'ag_atd.id', '=', 'ag.activity_type_detail')
            ->leftJoin('m_first_program as ag_fp', 'ag_fp.id', '=', 'ag_atd.first_program');
        }

        // Rooms (optional)
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

        // vor dem finalen orderBy einbauen:
        $q->leftJoinSub(
            DB::table('activity')
            ->select('activity_group', DB::raw('MIN(start) as group_first_start'))
            ->groupBy('activity_group'),
            'ag_min',
            'ag_min.activity_group',
            '=',
            'ag.id'
        );

        // sortieren: erst Gruppenstart, dann Activity-Start
        $q->orderBy('ag_min.group_first_start')
        ->orderBy('a.start');

        return $q->selectRaw($select)->get();
    }

    //
    // Detailed activities list
    //

    public function activities(int $planId): \Illuminate\Http\JsonResponse
    {
        // TODO do that in a standardized way and also reflect it in routes
        // Check if user has admin role
        $jwt = request()->attributes->get('jwt');
        $roles = $jwt['resource_access']->flow->roles ?? [];

        if (!in_array('flow-admin', $roles) && !in_array('flow_admin', $roles)) {
            return response()->json(['error' => 'Forbidden - admin role required'], 403);
        }

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
        [$pivot, $rows] = $this->prepareActivities($planId, $req);

        $rows = $rows->filter(function ($r) use ($pivot) {
            $start = Carbon::parse($r->start_time);
            $end   = Carbon::parse($r->end_time);

            return $start <= $pivot && $end >= $pivot;
        });

        return response()->json($this->groupActivitiesForApi($planId, $rows));
    }

    public function actionNext(int $planId, Request $req): JsonResponse
    {
        [$pivot, $rows] = $this->prepareActivities($planId, $req);

        $interval = (int) $req->query('interval', 30);

        $rows = $rows->filter(function ($r) use ($pivot, $interval) {
            $start = Carbon::parse($r->start_time);
            $end   = Carbon::parse($r->end_time);

            return $start >= $pivot && $end <= (clone $pivot)->addMinutes($interval);
        });

        return response()->json($this->groupActivitiesForApi($planId, $rows));
    }

    /**
     * Gemeinsame Selektion und Ausgabeform für now/next.
     */

    private function prepareActivities(int $planId, Request $req)
    {
        // Event-Datum holen
        $eventDate = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->value('event.date');

        if (!$eventDate) {
            abort(404, 'Event not found');
        }

        // Uhrzeit aus Request holen
        $timeInput = $req->query('point_in_time'); // erwartet "HH:MM"
        if ($timeInput) {
            $pivot = Carbon::createFromFormat('Y-m-d H:i', $eventDate . ' ' . $timeInput, 'UTC');
        } else {
            $pivot = Carbon::createFromFormat('Y-m-d H:i', $eventDate . ' ' . now('Europe/Berlin')->format('H:i'), 'UTC');
        }

        // Activities laden
        $rows = $this->fetchActivities(
            $planId,
            includeRooms: true,
            includeGroupMeta: true,
            includeActivityMeta: true
        );

        return [$pivot, $rows];
    }

    private function groupActivitiesForApi(int $planId, $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $gid = $row->activity_group_id ?? null;

            if (!isset($groups[$gid])) {
                $groups[$gid] = [
                    'activity_group_id' => $gid,
                    'group_meta' => [
                        'name'               => $row->group_atd_name ?? null,
                        'first_program_id'   => $row->group_first_program_id ?? null,
                        'first_program_name' => $row->group_first_program_name ?? null,
                        'description'        => $row->group_description ?? null,
                    ],
                    'activities' => [],
                ];
            }

            $groups[$gid]['activities'][] = [
                'activity_id'      => $row->activity_id,
                'start_time'       => $row->start_time,
                'end_time'         => $row->end_time,
                'activity_name'    => $row->activity_name,

                // Activity-ATD-Meta
                'meta' => [
                    'name'               => $row->activity_atd_name ?? null,
                    'first_program_id'   => $row->activity_first_program_id ?? null,
                    'first_program_name' => $row->activity_first_program_name ?? null,
                    'description'        => $row->activity_description ?? null,
                ],

                // Basis
                'program'          => $row->program_name,
                'lane'             => $row->lane,
                'team'             => $row->team,
                

                // Robot-Game Tische + Teams
                'table_1'              => $row->table_1,
                'table_1_team'         => $row->table_1_team,
                'table_2'              => $row->table_2,
                'table_2_team'         => $row->table_2_team,

                // NEU: Teamnamen (falls via fetchActivities(..., includeTeamNames: true) geladen)
                'team_name'            => $row->jury_team_name ?? null, 
                'table_1_team_name'    => $row->table_1_team_name ?? null,
                'table_2_team_name'    => $row->table_2_team_name ?? null,

                // NEU: Raumdaten (falls via fetchActivities(..., includeRooms: true) geladen)
                'room' => [
                    'room_type_id'    => $row->room_type_id    ?? null,
                    'room_type_name'  => $row->room_type_name  ?? null,
                    'room_id'         => $row->room_id         ?? null,
                    'room_name'       => $row->room_name       ?? null,
                ],
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
        Log::info("populateTeamPlanForNewPlan called for plan $planId, event $eventId");
        
        // Get all teams for this event
        $teams = Team::where('event', $eventId)->get();
        Log::info("Found " . $teams->count() . " teams for event $eventId");

        if ($teams->isEmpty()) {
            Log::info("No teams found for event $eventId - skipping team_plan population");
            return; // No teams to add
        }

        // Group teams by program and assign order
        $exploreTeams = $teams->where('first_program', 2)->values(); // Explore = 2
        $challengeTeams = $teams->where('first_program', 3)->values(); // Challenge = 3

        Log::info("Explore teams: " . $exploreTeams->count() . ", Challenge teams: " . $challengeTeams->count());

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

        Log::info("Prepared " . count($teamPlanEntries) . " team_plan entries to insert");

        // Insert all team_plan entries
        if (!empty($teamPlanEntries)) {
            try {
                TeamPlan::insert($teamPlanEntries);
                Log::info("Successfully inserted " . count($teamPlanEntries) . " team_plan entries");
            } catch (\Exception $e) {
                Log::error("Failed to insert team_plan entries: " . $e->getMessage());
            }
        } else {
            Log::warning("No team_plan entries to insert");
        }
    }

    /**
     * Ensure all teams for an event have entries in team_plan for existing plans
     * This handles cases where teams were added after plan creation
     */
    public function syncTeamPlanForEvent($eventId)
    {
        Log::info("syncTeamPlanForEvent called for event $eventId");
        $plans = Plan::where('event', $eventId)->get();

        if ($plans->isEmpty()) {
            Log::info("No plans found for event $eventId - skipping sync");
            return; // No plans to sync
        }

        Log::info("Found " . $plans->count() . " plans for event $eventId - syncing team_plan entries");
        foreach ($plans as $plan) {
            $this->syncTeamPlanForPlan($plan->id, $eventId);
        }
    }

    /**
     * Sync team_plan entries for a specific plan
     */
    private function syncTeamPlanForPlan($planId, $eventId)
    {
        Log::info("syncTeamPlanForPlan called for plan $planId, event $eventId");
        
        // Get all teams for this event
        $teams = Team::where('event', $eventId)->get();
        Log::info("Found " . $teams->count() . " teams for event $eventId");

        if ($teams->isEmpty()) {
            Log::info("No teams found for event $eventId - skipping sync");
            return;
        }

        // Get existing team_plan entries for this plan
        $existingTeamIds = TeamPlan::where('plan', $planId)
            ->pluck('team')
            ->toArray();
        Log::info("Found " . count($existingTeamIds) . " existing team_plan entries for plan $planId");

        // Find teams that don't have team_plan entries
        $missingTeams = $teams->whereNotIn('id', $existingTeamIds);
        Log::info("Found " . $missingTeams->count() . " missing teams for plan $planId");

        if ($missingTeams->isEmpty()) {
            Log::info("All teams already have team_plan entries for plan $planId");
            return; // All teams already have entries
        }

        // Get the highest current team_number_plan for this plan
        $maxOrder = TeamPlan::where('plan', $planId)
            ->max('team_number_plan') ?? 0;
        Log::info("Max team_number_plan for plan $planId: $maxOrder");

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

        Log::info("Prepared " . count($teamPlanEntries) . " missing team_plan entries to insert");

        // Insert missing team_plan entries
        if (!empty($teamPlanEntries)) {
            try {
                TeamPlan::insert($teamPlanEntries);
                Log::info("Successfully inserted " . count($teamPlanEntries) . " missing team_plan entries");
            } catch (\Exception $e) {
                Log::error("Failed to insert missing team_plan entries: " . $e->getMessage());
            }
        }
    }


   // Wichtige Zeite für die Veröffentlichung 

    public function importantTimes(int $planId): \Illuminate\Http\JsonResponse
    {
        // Activities laden
        $activities = $this->fetchActivities($planId);

        // Plan für last_changed
        $plan = DB::table('plan')
            ->select('last_change')
            ->where('id', $planId)
            ->first();

        // Hilfsfunktion: Erste Startzeit für gegebene ATD-IDs finden
        $findStart = function($ids) use ($activities) {
            $act = $activities->first(fn($a) => in_array($a->activity_type_detail_id, (array) $ids));
            return $act ? $act->start_time : null;
        };

        // Hilfsfunktion: Ende der Aktivität (end_time) für gegebene ATD-IDs
        $findEnd = function($ids) use ($activities) {
            $act = $activities->first(fn($a) => in_array($a->activity_type_detail_id, (array) $ids));
            return $act ? $act->end_time : null;
        };

        $data = [
            'plan_id'      => $planId,
            'last_changed' => $plan?->last_change,
            'explore' => [
                'briefing' => [
                    'teams'  => $findStart(ID_ATD_E_COACH_BRIEFING),
                    'judges' => $findStart(ID_ATD_E_JUDGE_BRIEFING),
                ],
                'opening' => $findStart([ID_ATD_E_OPENING, ID_ATD_OPENING]), // spezifisch oder gemeinsam
                'end'     => $findEnd([ID_ATD_E_AWARDS, ID_ATD_AWARDS]),     // spezifisch oder gemeinsam
            ],
            'challenge' => [
                'briefing' => [
                    'teams'    => $findStart(ID_ATD_C_COACH_BRIEFING),
                    'judges'   => $findStart(ID_ATD_C_JUDGE_BRIEFING),
                    'referees' => $findStart(ID_ATD_R_REFEREE_BRIEFING),
                ],
                'opening' => $findStart([ID_ATD_C_OPENING, ID_ATD_OPENING]), // spezifisch oder gemeinsam
                'end'     => $findEnd([ID_ATD_C_AWARDS, ID_ATD_AWARDS]),     // spezifisch oder gemeinsam
            ],
        ];

        return response()->json($data);
    }

}
