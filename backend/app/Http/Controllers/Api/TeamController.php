<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\FirstProgram;
use App\Models\Team;
use App\Models\TeamPlan;
use App\Models\Plan;
use App\Http\Controllers\Api\PlanController;
use App\Services\EventAttentionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class TeamController extends Controller
{
    public $table = 'team';
    public $hasTimestamps = false;

    public $fillable = [
        'id',
        'first_program',
        'name',
        'event',
        'team_number_hot',
        'location',
        'organization',
    ];

    public function index(Request $request, Event $event)
    {
        $programName = $request->query('program');
        $sortBy = $request->query('sort', 'name'); // 'name' or 'plan_order'

        if (!in_array($programName, ['explore', 'challenge'])) {
            return response()->json(['error' => 'Invalid program'], 400);
        }

        if (!in_array($sortBy, ['name', 'plan_order'])) {
            return response()->json(['error' => 'Invalid sort parameter'], 400);
        }

        $program = FirstProgram::where('name', $programName)->first();

        if (!$program) {
            return response()->json(['error' => 'Program not found'], 404);
        }

        // Get teams with their plan order
        $query = $event->teams()
            ->where('first_program', $program->id)
            ->leftJoin('team_plan', function ($join) use ($event) {
                $join->on('team.id', '=', 'team_plan.team')
                    ->where('team_plan.plan', '=', function ($query) use ($event) {
                        $query->select('id')
                            ->from('plan')
                            ->where('event', $event->id)
                            ->limit(1);
                    });
            })
            ->select('team.*', 'team_plan.team_number_plan', 'team_plan.room', 'team_plan.noshow');

        // Apply sorting based on parameter
        if ($sortBy === 'plan_order') {
            $query->orderBy('team_plan.team_number_plan');
        } else {
            $query->orderBy('team.name')->orderBy('team.team_number_hot');
        }

        $teams = $query->get();

        // If Explore teams, include e1_teams and e_mode for frontend to determine morning/afternoon split
        if ($programName === 'explore') {
            $plan = Plan::where('event', $event->id)->first();
            if ($plan) {
                $e1Teams = DB::table('plan_param_value')
                    ->join('m_parameter', 'plan_param_value.parameter', '=', 'm_parameter.id')
                    ->where('plan_param_value.plan', $plan->id)
                    ->where('m_parameter.name', 'e1_teams')
                    ->value('plan_param_value.set_value');

                $eMode = DB::table('plan_param_value')
                    ->join('m_parameter', 'plan_param_value.parameter', '=', 'm_parameter.id')
                    ->where('plan_param_value.plan', $plan->id)
                    ->where('m_parameter.name', 'e_mode')
                    ->value('plan_param_value.set_value');

                // Return object with teams and metadata for Explore
                return response()->json([
                    'teams' => $teams,
                    'metadata' => [
                        'e1_teams' => $e1Teams ? (int)$e1Teams : 0,
                        'e_mode' => $eMode ? (int)$eMode : 0
                    ]
                ]);
            }
        }

        // For Challenge or if no plan found, return teams array directly (backward compatible)
        return response()->json($teams);
    }

    public function update(Request $request, Team $team)
    {
        $data = $request->only(['id', 'name', 'noshow']);

        if (!isset($data['id'])) {
            return $this->create($request);
        }

        $team = Team::where('id', $data['id'])->first();

        if (!$team) {
            return response()->json(['error' => 'Team not found'], 404);
        }

        if (isset($data['name'])) {
            $team->name = $data['name'];
            $team->save();
        }

        $eventId = null;
        if (isset($data['noshow'])) {
            // Update noshow in team_plan for the current event's plan
            $event = Event::find($team->event);
            if ($event) {
                $eventId = $event->id;
                $plan = Plan::where('event', $event->id)->first();
                if ($plan) {
                    TeamPlan::where('team', $team->id)
                        ->where('plan', $plan->id)
                        ->update(['noshow' => $data['noshow']]);
                }
            }
        } else {
            $eventId = $team->event;
        }

        // Update attention status after team modification
        if ($eventId) {
            app(EventAttentionService::class)->updateEventAttentionStatus($eventId);
        }

        return response()->json(['message' => 'Team updated successfully', 'team' => $team]);
    }

    public function create(Request $request)
    {
        $program = FirstProgram::where('name', $request->get('first_program'))->first();
        $team = new Team();
        $team->first_program = $program->id;
        $team->name = $request->get('name');
        $team->event = $request->get('event');
        $team->team_number_hot = $request->get('team_number_hot');
        $team->location = $request->get('location');
        $team->organization = $request->get('organization');
        $team->save();

        // Sync team_plan entries for existing plans
        $planController = new PlanController();
        $planController->syncTeamPlanForEvent($team->event);

        // Set noshow to false in team_plan for the current event's plan
        $event = Event::find($team->event);
        if ($event) {
            $plan = Plan::where('event', $event->id)->first();
            if ($plan) {
                TeamPlan::where('team', $team->id)
                    ->where('plan', $plan->id)
                    ->update(['noshow' => false]);

                // Renumber team_plan entries sequentially for this program
                $this->renumberTeamPlanForProgram($plan->id, $event->id, $program->id);
            }
        }

        // Update attention status after creating new team
        app(EventAttentionService::class)->updateEventAttentionStatus($team->event);

        return response()->json(['message' => 'Team created successfully', 'team' => $team]);
    }

    public function updateOrder(Request $request, Event $event)
    {
        $validated = $request->validate([
            'program' => 'required|in:explore,challenge',
            'order' => 'required|array',
            'order.*.team_id' => 'required|integer|exists:team,id',
            'order.*.order' => 'required|integer|min:1'
        ]);

        $program = FirstProgram::where('name', $validated['program'])->first();
        if (!$program) {
            return response()->json(['error' => 'Program not found'], 404);
        }

        // Get the plan for this event
        $plan = Plan::where('event', $event->id)->first();
        if (!$plan) {
            return response()->json(['error' => 'No plan found for this event'], 404);
        }

        DB::transaction(function () use ($validated, $plan, $program) {
            // Get existing room assignments before deletion
            $teamIds = collect($validated['order'])->pluck('team_id');
            $existingAssignments = TeamPlan::where('plan', $plan->id)
                ->whereIn('team', $teamIds)
                ->pluck('room', 'team')
                ->toArray();

            // Delete existing team_plan entries for this plan and program
            TeamPlan::where('plan', $plan->id)
                ->whereIn('team', $teamIds)
                ->delete();

            // Insert new team order with preserved room assignments
            foreach ($validated['order'] as $item) {
                TeamPlan::create([
                    'team' => $item['team_id'],
                    'plan' => $plan->id,
                    'team_number_plan' => $item['order'],
                    'room' => $existingAssignments[$item['team_id']] ?? null, // Preserve existing room assignment
                    'noshow' => false // Default to false
                ]);
            }
        });

        // Update attention status after reordering teams
        app(EventAttentionService::class)->updateEventAttentionStatus($event->id);

        return response()->json(['message' => 'Team order updated successfully']);
    }

    public function destroy(Team $team)
    {
        try {
            $eventId = $team->event;
            $programId = $team->first_program;

            // Get plan before deleting team (for renumbering)
            $plan = Plan::where('event', $eventId)->first();

            $team->delete();

            // Renumber team_plan entries sequentially for this program after deletion
            if ($plan) {
                $this->renumberTeamPlanForProgram($plan->id, $eventId, $programId);
            }

            // Update attention status after deleting team
            app(EventAttentionService::class)->updateEventAttentionStatus($eventId);

            return response()->json(['message' => 'Team deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Error deleting team', [
                'team_id' => $team->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Failed to delete team'], 500);
        }
    }

    /**
     * Renumber team_plan entries sequentially (1, 2, 3...) for a given plan and program
     * Preserves room assignments and noshow status
     */
    private function renumberTeamPlanForProgram($planId, $eventId, $programId)
    {
        // Get all teams for this event and program
        $teams = Team::where('event', $eventId)
            ->where('first_program', $programId)
            ->get();

        if ($teams->isEmpty()) {
            return; // No teams to renumber
        }

        // Get existing team_plan entries for these teams, ordered by current team_number_plan
        $teamPlanEntries = TeamPlan::where('plan', $planId)
            ->whereIn('team', $teams->pluck('id'))
            ->orderBy('team_number_plan')
            ->get();

        if ($teamPlanEntries->isEmpty()) {
            return; // No team_plan entries to renumber
        }

        // Renumber sequentially starting from 1, preserving room and noshow
        DB::transaction(function () use ($teamPlanEntries, $planId) {
            foreach ($teamPlanEntries as $index => $entry) {
                TeamPlan::where('team', $entry->team)
                    ->where('plan', $planId)
                    ->update(['team_number_plan' => $index + 1]);
            }
        });

        Log::info("Renumbered team_plan entries for plan $planId, program $programId - new sequential order 1-" . count($teamPlanEntries));
    }
}
