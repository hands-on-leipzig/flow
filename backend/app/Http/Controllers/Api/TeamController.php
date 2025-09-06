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
        'noshow',
    ];

    public function index(Request $request, Event $event)
    {
        $programName = $request->query('program');

        if (!in_array($programName, ['explore', 'challenge'])) {
            return response()->json(['error' => 'Invalid program'], 400);
        }

        $program = FirstProgram::where('name', $programName)->first();

        if (!$program) {
            return response()->json(['error' => 'Program not found'], 404);
        }

        // Get teams with their plan order
        $teams = $event->teams()
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
            ->select('team.*', 'team_plan.team_number_plan')
            ->orderBy('team_plan.team_number_plan')
            ->orderBy('team.team_number_hot') // Fallback ordering
            ->get();

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
        }

        if (isset($data['noshow'])) {
            $team->noshow = $data['noshow'];
        }

        $team->save();

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
        $team->noshow = 0;
        $team->save();
        
        // Sync team_plan entries for existing plans
        $planController = new PlanController();
        $planController->syncTeamPlanForEvent($team->event);
        
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
            // Delete existing team_plan entries for this plan and program
            $teamIds = collect($validated['order'])->pluck('team_id');
            TeamPlan::where('plan', $plan->id)
                ->whereIn('team', $teamIds)
                ->delete();

            // Insert new team order
            foreach ($validated['order'] as $item) {
                TeamPlan::create([
                    'team' => $item['team_id'],
                    'plan' => $plan->id,
                    'team_number_plan' => $item['order'],
                    'room' => null // Will be set later when rooms are assigned
                ]);
            }
        });

        return response()->json(['message' => 'Team order updated successfully']);
    }
}
