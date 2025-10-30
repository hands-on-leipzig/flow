<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ContaoController extends Controller
{
    /**
     * Get tournament scores for an event
     */
    public function getScore(Request $request): JsonResponse
    {
        try {
            $eventId = $request->input('event_id');

            if (!$eventId) {
                return response()->json(['error' => 'event_id parameter is required'], 400);
            }

            $roundShowSetting = $this->getRoundsToShow($eventId);
            $tournamentId = $this->getTournamentId($eventId);

            if (!$tournamentId) {
                return response()->json(['error' => "No Contao ID found for event {$eventId}. Please set contao_id_challenge or contao_id_explore."], 404);
            }

            // Get tournament data
            $tournament = DB::connection('contao')
                ->table('hot_tournament')
                ->where('region', $tournamentId)
                ->first();

            if (!$tournament) {
                return response()->json(['error' => "No tournament found for region {$tournamentId} in Contao database"], 404);
            }

            $results = [
                "id" => $tournament->id,
                "name" => $tournament->name,
                "rounds" => [],
            ];

            // Determine which rounds to show
            $roundsToShow = [];
            if ($roundShowSetting->vr1 || $roundShowSetting->vr2 || $roundShowSetting->vr3) {
                $roundsToShow[] = "VR";
            }
            if ($roundShowSetting->af) $roundsToShow[] = "AF";
            if ($roundShowSetting->vf) $roundsToShow[] = "VF";
            if ($roundShowSetting->hf) $roundsToShow[] = "HF";

            // Get scores for each round
            foreach ($roundsToShow as $round) {
                $scores = DB::connection('contao')
                    ->table('hot_round as r')
                    ->join('hot_tournament as t', 'r.tournament', '=', 't.id')
                    ->join('hot_match as m', 'm.round', '=', 'r.id')
                    ->join('hot_assessment as a', 'a.matchx', '=', 'm.id')
                    ->join('hot_teams as te', 'a.team', '=', 'te.id')
                    ->where('t.region', $tournamentId)
                    ->where('r.type', $round)
                    ->where('a.confirmed_team', '1')
                    ->where('a.confirmed_referee', '1')
                    ->orderBy('a.crdate', 'asc')
                    ->select('te.team_name as name', 'te.id as id', 'a.points as points', 'r.matches as num_matches')
                    ->get();

                $maxPoints = [];

                foreach ($scores as $score) {
                    $teamId = $score->id;

                    if (!isset($maxPoints[$teamId])) {
                        $maxPoints[$teamId] = 0;
                    }
                    if ($score->points > $maxPoints[$teamId]) {
                        $maxPoints[$teamId] = $score->points;
                    }

                    $results["rounds"][$round][$teamId]["scores"][] = [
                        "points" => $score->points,
                        "highlight" => false,
                    ];

                    $results["rounds"][$round][$teamId]["name"] = $score->name;
                }
            }

            // Apply round visibility settings for VR rounds
            if (isset($results["rounds"]["VR"])) {
                foreach ($results["rounds"]["VR"] as $teamId => $roundData) {
                    if (!$roundShowSetting->vr1 && isset($roundData["scores"][0])) {
                        $results["rounds"]["VR"][$teamId]["scores"][0]["points"] = 0;
                    }
                    if (!$roundShowSetting->vr2 && isset($roundData["scores"][1])) {
                        $results["rounds"]["VR"][$teamId]["scores"][1]["points"] = 0;
                    }
                    if (!$roundShowSetting->vr3 && isset($roundData["scores"][2])) {
                        $results["rounds"]["VR"][$teamId]["scores"][2]["points"] = 0;
                    }
                }
            }

            return response()->json($results);

        } catch (Exception $e) {
            Log::error('Contao getScore error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve scores from Contao'], 500);
        }
    }

    /**
     * Get rounds to show setting for an event
     */
    private function getRoundsToShow($eventId): object
    {

        // 1) Get manually published rounds from the database
        $settings = DB::table('contao_public_rounds')->where('event_id', $eventId)->first();
        if ($settings) {
            return $settings;
        }

        // 2) Check if additional rounds should be public
        // General idea: A round should be public once it is fully complete and the next round has started
        // TODO implement

        // This method should return the round visibility settings
        // For now, returning default settings - you may need to adapt this
        // based on how this data is stored in your system
        return (object)[
            'vr1' => true,
            'vr2' => true,
            'vr3' => true,
            'af' => true,
            'vf' => true,
            'hf' => true,
        ];
    }

    public function getRoundsToShowEndpoint(Request $request, $eventId): JsonResponse
    {
        $roundsToShow = $this->getRoundsToShow($eventId);
        return response()->json($roundsToShow);
    }

    public function saveRoundsToShow(Request $request, $eventId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'vr1' => 'nullable|boolean',
                'vr2' => 'nullable|boolean',
                'vr3' => 'nullable|boolean',
                'af'  => 'nullable|boolean',
                'vf'  => 'nullable|boolean',
                'hf'  => 'nullable|boolean',
            ]);

            // Sicherstellen, dass das Event existiert
            $exists = DB::table('event')->where('id', $eventId)->exists();
            if (!$exists) {
                return response()->json(['error' => 'Event not found'], 404);
            }

            // In 0/1 umwandeln für die Datenbank
            $payload = [
                'vr1' => isset($validated['vr1']) ? (int)$validated['vr1'] : 0,
                'vr2' => isset($validated['vr2']) ? (int)$validated['vr2'] : 0,
                'vr3' => isset($validated['vr3']) ? (int)$validated['vr3'] : 0,
                'af'  => isset($validated['af'])  ? (int)$validated['af']  : 0,
                'vf'  => isset($validated['vf'])  ? (int)$validated['vf']  : 0,
                'hf'  => isset($validated['hf'])  ? (int)$validated['hf']  : 0,
            ];

            DB::table('contao_public_rounds')->updateOrInsert(
                ['event_id' => $eventId],
                $payload
            );

            return response()->json(['status' => 'ok']);
        } catch (Exception $e) {
            Log::error('Contao saveRoundsToShow error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to save rounds_to_show'], 500);
        }
    }

    /**
     * Get tournament ID for an event
     */
    private function getTournamentId($eventId)
    {
        // Get the event and check for Contao IDs
        $event = DB::table('event')->where('id', $eventId)->first();

        if (!$event) {
            return null;
        }

        // Use contao_id_challenge if available, otherwise fall back to contao_id_explore
        if ($event->contao_id_challenge) {
            return $event->contao_id_challenge;
        }

        if ($event->contao_id_explore) {
            return $event->contao_id_explore;
        }

        // Fallback: return the event_id as tournament_id (for backward compatibility)
        return $eventId;
    }

    /**
     * Test Contao database connection
     */
    public function testConnection(): JsonResponse
    {
        try {
            $result = DB::connection('contao')->select('SELECT 1 as test');
            return response()->json([
                'status' => 'success',
                'message' => 'Contao database connection is working',
                'test_result' => $result[0]->test ?? null
            ]);
        } catch (Exception $e) {
            Log::error('Contao connection test failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Contao database connection failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
