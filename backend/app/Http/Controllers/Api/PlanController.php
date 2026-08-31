<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\MSupportedPlan;
use App\Models\PlanParamValue;
use App\Models\Team;
use App\Models\TeamPlan;
use App\Enums\FirstProgram;
use App\Services\AfternoonBlockOrderService;
use App\Services\EventAttentionService;
use App\Support\ProgramCatalog;

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
            ->select('id', 'last_change', 'locked')
            ->first();

        if ($plan) {
            // Prüfen, ob es mindestens eine activity_group für diesen Plan gibt
            $hasActivityGroup = DB::table('activity_group')
                ->where('plan', $plan->id)
                ->exists();

            return response()->json([
                'id' => $plan->id,
                'existing' => $hasActivityGroup,  // true nur, wenn activity_group existiert
                'last_change' => $plan->last_change,
                'locked' => (bool) ($plan->locked ?? false),
            ]);
        }

        // Sonst anlegen
        $newId = DB::table('plan')->insertGetId([
            'name' => 'Zeitplan',
            'event' => $eventId,
            'created' => Carbon::now(),
            'last_change' => Carbon::now(),
        ]);

        // Get DRAHT team counts for this event
        $event = \App\Models\Event::find($eventId);

        $e_teams = 0;
        $c_teams = 0;
        $f8_teams = 0;

        if ($event) {
            $enrolledExplore = 0;
            $enrolledChallenge = 0;

            $drahtController = new \App\Http\Controllers\Api\DrahtController();
            $drahtData = $drahtController->show($event);
            $data = $drahtData->getData(true);

            if ($data) {
                if (array_key_exists('teams_explore_count', $data)) {
                    $enrolledExplore = (int)$data['teams_explore_count'];
                } elseif (isset($data['teams_explore'])) {
                    if (is_array($data['teams_explore'])) {
                        $enrolledExplore = count($data['teams_explore']);
                    } elseif (is_numeric($data['teams_explore'])) {
                        $enrolledExplore = (int)$data['teams_explore'];
                    }
                }

                if (array_key_exists('teams_challenge_count', $data)) {
                    $enrolledChallenge = (int)$data['teams_challenge_count'];
                } elseif (isset($data['teams_challenge'])) {
                    if (is_array($data['teams_challenge'])) {
                        $enrolledChallenge = count($data['teams_challenge']);
                    } elseif (is_numeric($data['teams_challenge'])) {
                        $enrolledChallenge = (int)$data['teams_challenge'];
                    }
                }
            }

            if (ProgramCatalog::hasExplore($event)) {
                $e_teams = max(6, $enrolledExplore);
            }
            if (ProgramCatalog::hasChallenge($event)) {
                $c_teams = max(8, $enrolledChallenge);
            }
            if (ProgramCatalog::hasFuture($event)) {
                $f8_teams = 8;
            }
        }

        // Max one explore group
        $e2_teams = 0;
        $e2_lanes = 0;


        if ($e_teams > 0) {

            if ($c_teams == 0 && $f8_teams == 0) {
                // e_mode standalone morning
                $e_mode = 3;
            } else {
                // e_mode integrated morning (Challenge or Future 8+ is the lead)
                $e_mode = 1;
            }

            $e1_teams = $e_teams;
            $e1_lanes = (int) (MSupportedPlan::bestFor(FirstProgram::EXPLORE->value, $e_teams)?->lanes ?? 0);

        } else {

            // e_mode off
            $e_mode = 0;

            $e1_teams = 0;
            $e1_lanes = 0;

        }


        if ($c_teams > 0) {

            // c_mode on
            $c_mode = 1;

            $challengePlan = MSupportedPlan::bestFor(FirstProgram::CHALLENGE->value, $c_teams);
            $j_lanes = (int) ($challengePlan->lanes ?? 0);
            $r_tables = (int) ($challengePlan->tables ?? 0);

        } else {

            // c_mode off
            $c_mode = 0;
            $j_lanes = 0;
            $r_tables = 0;

        }

        $f8_mode = 0;
        $f8_lanes = 0;
        $f8_fields = 0;
        if ($f8_teams > 0) {
            $f8_mode = 1;
            $f8Plan = MSupportedPlan::bestFor(FirstProgram::FUTURE_8->value, $f8_teams);
            $f8_lanes = (int) ($f8Plan->lanes ?? 0);
            $f8_fields = (int) ($f8Plan->tables ?? 0);
        }

        $hasExplore = $event && ProgramCatalog::hasExplore($event);
        $hasChallenge = $event && ProgramCatalog::hasChallenge($event);
        $hasFuture = $event && ProgramCatalog::hasFuture($event);

        if ($hasExplore) {
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
        }

        if ($hasChallenge) {
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
        }

        if ($hasFuture) {
            $this->setPlanParamByName($newId, 'f8_mode', $f8_mode);
            $this->setPlanParamByName($newId, 'f8_teams', $f8_teams);
            $this->setPlanParamByName($newId, 'f8_lanes', $f8_lanes);
            $this->setPlanParamByName($newId, 'f8_fields', $f8_fields);
        }

        app(AfternoonBlockOrderService::class)->writeDefaultOrder($newId);

        // Populate team_plan table with all teams for this event
        Log::info("Creating plan $newId for event $eventId - calling populateTeamPlanForNewPlan");
        $this->populateTeamPlanForNewPlan($newId, $eventId);

        // Add some default free blocks to illustrate usage
        $this->addDefaultFreeBlocks($newId);

        // Update attention status after creating plan (team counts are set which affects attention)
        app(EventAttentionService::class)->updateEventAttentionStatus($eventId);

        return response()->json([
            'id' => $newId,
            'existing' => false,
            'last_change' => Carbon::now(),
            'locked' => false,
        ]);
    }

    public function updateLock(int $id, \Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locked' => 'required|boolean',
        ]);

        $updated = DB::table('plan')
            ->where('id', $id)
            ->update(['locked' => $validated['locked'] ? 1 : 0]);

        if ($updated === 0 && !DB::table('plan')->where('id', $id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'locked' => (bool) $validated['locked'],
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
        $exploreTeams = $teams->where('first_program', FirstProgram::EXPLORE->value)->values();
        $challengeTeams = $teams->where('first_program', FirstProgram::CHALLENGE->value)->values();
        $futureTeams = $teams->where('first_program', FirstProgram::FUTURE_8->value)->values();

        Log::info("Explore teams: " . $exploreTeams->count() . ", Challenge teams: " . $challengeTeams->count() . ", Future 8+ teams: " . $futureTeams->count());

        $teamPlanEntries = [];

        // Add explore teams with order (starting from 1)
        foreach ($exploreTeams as $index => $team) {
            $teamPlanEntries[] = [
                'team' => $team->id,
                'plan' => $planId,
                'team_number_plan' => $index + 1,
                'room' => null
            ];
        }

        // Add challenge teams with order (also starting from 1, independently)
        foreach ($challengeTeams as $index => $team) {
            $teamPlanEntries[] = [
                'team' => $team->id,
                'plan' => $planId,
                'team_number_plan' => $index + 1,
                'room' => null
            ];
        }

        foreach ($futureTeams as $index => $team) {
            $teamPlanEntries[] = [
                'team' => $team->id,
                'plan' => $planId,
                'team_number_plan' => $index + 1,
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
     * Ensure teams for one program have team_plan rows on the event plan.
     */
    public function syncTeamPlanForProgram(int $planId, int $eventId, int $programId): void
    {
        Log::info("syncTeamPlanForProgram called for plan $planId, event $eventId, program $programId");

        $teams = Team::where('event', $eventId)
            ->where('first_program', $programId)
            ->get();

        if ($teams->isEmpty()) {
            return;
        }

        $existingTeamIds = TeamPlan::where('plan', $planId)
            ->pluck('team')
            ->all();

        $missingTeams = $teams->whereNotIn('id', $existingTeamIds)->values();
        if ($missingTeams->isEmpty()) {
            return;
        }

        $existingMax = TeamPlan::where('plan', $planId)
            ->join('team', 'team_plan.team', '=', 'team.id')
            ->where('team.first_program', $programId)
            ->max('team_plan.team_number_plan') ?? 0;

        $teamPlanEntries = [];
        foreach ($missingTeams as $index => $team) {
            $teamPlanEntries[] = [
                'team' => $team->id,
                'plan' => $planId,
                'team_number_plan' => $existingMax + $index + 1,
                'room' => null,
            ];
        }

        if ($teamPlanEntries !== []) {
            try {
                TeamPlan::insert($teamPlanEntries);
                Log::info('Inserted '.count($teamPlanEntries)." team_plan entries for program $programId");
            } catch (\Exception $e) {
                Log::error('Failed to insert team_plan entries for program '.$programId.': '.$e->getMessage());
            }
        }
    }

    /**
     * Sync team_plan entries for a specific plan
     */
    private function syncTeamPlanForPlan($planId, $eventId)
    {
        Log::info("syncTeamPlanForPlan called for plan $planId, event $eventId");

        $programIds = Team::where('event', $eventId)
            ->distinct()
            ->pluck('first_program')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        foreach ($programIds as $programId) {
            $this->syncTeamPlanForProgram($planId, $eventId, $programId);
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
        $end = $date->copy();


        // --- IDs der relevanten Parameter finden ---
        $paramIds = DB::table('m_parameter')
            ->whereIn('name', ['e_teams', 'c_teams'])
            ->pluck('id', 'name');

        $eTeamsParamId = $paramIds['e_teams'] ?? null;
        $cTeamsParamId = $paramIds['c_teams'] ?? null;

        // --- Werte aus plan_param_value lesen ---
        $paramValues = DB::table('plan_param_value')
            ->where('plan', $planId)
            ->whereIn('parameter', [$eTeamsParamId, $cTeamsParamId])
            ->pluck('set_value', 'parameter');

        $e_teams = isset($paramValues[$eTeamsParamId]) ? (int)$paramValues[$eTeamsParamId] : 0;
        $c_teams = isset($paramValues[$cTeamsParamId]) ? (int)$paramValues[$cTeamsParamId] : 0;

        // Mittagessen: Explore-only, Challenge-only, or Joint depending on which programs the event has
        $mittagessenProgram = ($e_teams > 0 && $c_teams === 0)
            ? FirstProgram::EXPLORE->value
            : (($c_teams > 0 && $e_teams === 0) ? FirstProgram::CHALLENGE->value : FirstProgram::JOINT->value);

        $start->setTime(11, 30, 0);
        $end->setTime(13, 30, 0);

        DB::table('extra_block')->insert([
            'plan' => $planId,
            'first_program' => $mittagessenProgram,
            'name' => 'Mittagessen',
            'description' => 'Es gibt verschiedene Gerichte für Teams, Helfer und Besucher.',
            'link' => 'https://lecker-essen.mhhm',
            'start' => $start,
            'end' => $end,
            'room' => null,
            'active' => 1,
            'type' => 'free',
        ]);

        $start->setTime(9, 0, 0);
        $end->setTime(16, 30, 0);

        DB::table('extra_block')->insert([
            'plan' => $planId,
            'first_program' => FirstProgram::JOINT->value,
            'name' => 'Awareness',
            'description' => 'Awareness bedeutet, achtsam miteinander umzugehen, Grenzen zu respektieren und eine Umgebung frei von Diskriminierung, Mobbing oder unangemessenem Verhalten zu schaffen. Das Konzept bietet Anregungen zu Schutzmaßnahmen, inklusivem Miteinander und einer Kultur der Achtsamkeit, damit alle Kinder und Jugendlichen unsere Veranstaltungen als positive und sichere Erfahrung erleben.',
            'link' => 'https://www.first-lego-league.org/de/community/awareness',
            'start' => $start,
            'end' => $end,
            'room' => null,
            'active' => 0,
            'type' => 'free',
        ]);

        $start->setTime(8, 0, 0);
        $end->setTime(8, 30, 0);

        DB::table('extra_block')->insert([
            'plan' => $planId,
            'first_program' => FirstProgram::EXPLORE->value,
            'name' => 'Check-In FLL Explore',
            'description' => 'Teams und Gutacher:innen bitte beim Check-In melden, damit wir wissen, dass ihr da seid.',
            'link' => null,
            'start' => $start,
            'end' => $end,
            'room' => null,
            'active' => $e_teams > 0 ? 1 : 0,
            'type' => 'free',
        ]);

        DB::table('extra_block')->insert([
            'plan' => $planId,
            'first_program' => FirstProgram::CHALLENGE->value,
            'name' => 'Check-In FLL Challenge',
            'description' => 'Teams, Juror:innen und Schiedsrichter:innen bitte beim Check-In melden, damit wir wissen, dass ihr da seid.',
            'link' => null,
            'start' => $start,
            'end' => $end,
            'room' => null,
            'active' => $c_teams > 0 ? 1 : 0,
            'type' => 'free',
        ]);


    }

    public function delete(int $id)
    {
        // Event-ID zum Plan holen
        $eventId = DB::table('plan')->where('id', $id)->value('event');

        if ($eventId) {
            // Zugehörige Veröffentlichungen löschen
            $pubDeleted = DB::table('publication')->where('event', $eventId)->delete();
            Log::info("Publications deleted for event {$eventId}: {$pubDeleted}");
        } else {
            Log::warning("No event found for plan {$id}, skipping publication cleanup.");
        }

        // Plan löschen
        $deleted = DB::table('plan')->where('id', $id)->delete();
        Log::info("Plan {$id} deletion attempted, deleted count: {$deleted}");

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

    private function setPlanParamByName(int $planId, string $name, mixed $value): void
    {
        $parameterId = DB::table('m_parameter')->where('name', $name)->value('id');
        if (! $parameterId) {
            Log::warning("PlanController: unknown parameter {$name}, skip write", [
                'plan_id' => $planId,
            ]);

            return;
        }

        PlanParamValue::updateOrCreate(
            ['plan' => $planId, 'parameter' => $parameterId],
            ['set_value' => $value]
        );
    }

}
