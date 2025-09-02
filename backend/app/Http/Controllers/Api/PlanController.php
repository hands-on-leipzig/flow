<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\MSupportedPlan;
use App\Models\PlanParamValue;
use App\Services\PreviewMatrix;
use App\Services\GeneratePlan;
use App\Jobs\GeneratePlanJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'created' => now(),
            'last_change' => now(),
            'public' => false
        ]);

        // TODO
        // Hier sollten e_teams und c_teams mit den geplanten Werten aus DRAHT befüllt werden.
        
        $e_teams = 6; // TODO aus DRAHT
        $c_teams = 12;  // TODO aus DRAHT

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

        if ($async) {
        
            log::info("Generate async for plan ID {$planId}");
            
            GeneratePlanJob::dispatch($planId);

            return response()->json(['message' => 'Generation dispatched']);

        } else {

            log::info("Generate sync for plan ID {$planId}");

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

    /**
     * Build the base activities query used by all views.
     *
     * @param int $plan
     * @param bool $includeRooms If true, joins/selects room type fields as well.
     * @return \Illuminate\Support\Collection
     */
    private function fetchActivities(int $plan, bool $includeRooms = false)
    
    {
        // Map configured "free" constants to numeric IDs (defensive)
        $freeIds = array_values(array_filter(array_map(function ($c) {
            if (is_string($c) && defined($c)) return (int) constant($c);
            if (is_numeric($c)) return (int) $c;
            return null;
        }, (array) config('atd.free')), fn ($v) => $v !== null));

        $q = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->leftJoin('m_first_program as fp', 'atd.first_program', '=', 'fp.id')
            ->leftJoin('extra_block as peb', 'a.extra_block', '=', 'peb.id')   // wichtig für COALESCE(peb.name,…)
            ->join('plan as p', 'p.id', '=', 'ag.plan')
            ->where('ag.plan', $plan);

        // <<< CRUCIAL: exclude "free" ATDs to keep raster small >>>
        if (!empty($freeIds)) {
            $q->whereNotIn('atd.id', $freeIds);
        }

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

        $select = '
            a.id as activity_id,
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

        // Nur für Rooms nach rt/r sortieren – sonst existieren die Aliase nicht
        if ($includeRooms) {
            $q->orderBy('rt.sequence')
            ->orderBy('r.name');
        }

        return $q->orderBy('a.start')->selectRaw($select)->get();
    }

    //
    // Detailed activities list
    //

    function activities($planId): JsonResponse
    {

        // TODO: 
        $activities = $this->fetchActivities($planId, includeRooms: true);
        return response()->json($activities);
    }

}
