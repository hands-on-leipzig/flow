<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\MSupportedPlan;
use App\Models\PlanParamValue;
use App\Models\Team;
use App\Models\TeamPlan;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

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

        // Add some default free blocks to illustrate usage
        $this->addDefaultFreeBlocks($newId);
    
        return response()->json([
            'id' => $newId,
            'existing' => false,
        ]);
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

    private function addDefaultFreeBlocks(int $planId): void
    {
        $eventDate = DB::table('plan')
            ->join('event', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->value('event.date');

        $date = Carbon::parse($eventDate);
        $start = $date->copy();
        $end   = $date->copy();

        $start->setTime(11, 30, 0);
        $end->setTime(13, 30, 0);

        DB::table('extra_block')->insert([
            'plan'        => $planId,
            'first_program' => 0, 
            'name'        => 'Mittagessen',
            'description' => 'Es gibt verschiedene Gerichte für Teams, Helfer und Besucher.',
            'link'        => 'https://lecker-essen.mhhm',
            'start'       => $start,
            'end'         => $end,
            'room'        => null,
            'active'      => 1,
        ]);

        $start->setTime(9, 0, 0);
        $end->setTime(16, 30, 0);
        
        DB::table('extra_block')->insert([
            'plan'        => $planId,
            'first_program' => 0,
            'name'        => 'Awareness',
            'description' => 'Awareness bedeutet, achtsam miteinander umzugehen, Grenzen zu respektieren und eine Umgebung frei von Diskriminierung, Mobbing oder unangemessenem Verhalten zu schaffen. Das Konzept bietet Anregungen zu Schutzmaßnahmen, inklusivem Miteinander und einer Kultur der Achtsamkeit, damit alle Kinder und Jugendlichen unsere Veranstaltungen als positive und sichere Erfahrung erleben.',
            'link'        => 'https://youtube.com/shorts/vYOn38IBYX8?si=OMRuh3gsRYwle1kw',
            'start'       => $start,
            'end'         => $end,
            'room'        => null,
            'active'      => 1,
        ]);
    }

    public function delete(int $id)
    {
        $deleted = DB::table('plan')->where('id', $id)->delete();

         Log::info("Plan $id deletion attempted, deleted count: $deleted");

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Plan deleted successfully',
        ]);
    }

}
