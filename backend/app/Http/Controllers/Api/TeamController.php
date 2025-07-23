<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\FirstProgram;
use App\Models\Team;
use Illuminate\Http\Request;

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

        $teams = $event->teams()
            ->where('first_program', $program->id)
            ->orderBy('team_number_hot')
            ->get();

        return response()->json($teams);
    }

    public function update(Request $request, Team $team)
    {
        $data = $request->only(['id', 'name']);

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
        return response()->json(['message' => 'Team created successfully', 'team' => $team]);
    }
}
