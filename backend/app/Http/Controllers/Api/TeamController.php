<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\FirstProgram;
use App\Models\Team;
use App\Models\TeamPlan;
use App\Models\Plan;
use App\Http\Controllers\Api\PlanController;
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
            ->leftJoin('team_plan', function($join) use ($event) {
                $join->on('team.id', '=', 'team_plan.team')
                     ->where('team_plan.plan', '=', function($query) use ($event) {
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

        // Log::info('Fetched teams', $teams->toArray());      

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

        if (isset($data['noshow'])) {
            // Update noshow in team_plan for the current event's plan
            $event = Event::find($team->event);
            if ($event) {
                $plan = Plan::where('event', $event->id)->first();
                if ($plan) {
                    TeamPlan::where('team', $team->id)
                        ->where('plan', $plan->id)
                        ->update(['noshow' => $data['noshow']]);
                }
            }
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
            }
        }
        
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

        return response()->json(['message' => 'Team order updated successfully']);
    }
}
