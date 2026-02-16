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

            $tournamentId = $this->getTournamentId($eventId);

            if (!$tournamentId) {
                return response()->json(['error' => "No Contao ID found for event {$eventId}. Please set contao_id_challenge or contao_id_explore."], 404);
            }

            // Test: does the contao tournament exist?
            $tournamentExists = DB::connection('contao')
                ->table('hot_tournament')
                ->where('region', $tournamentId)
                ->exists();

            if (!$tournamentExists) {
                return response()->json(['error' => "No tournament found for region {$tournamentId} for event {$eventId} in Contao database"], 404);
            }

            $roundShowSetting = $this->getRoundsToShow($eventId, $tournamentId);

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
            if ($roundShowSetting->vf) $roundsToShow[] = "VF";
            if ($roundShowSetting->hf) $roundsToShow[] = "HF";

            // Get scores for each round
            foreach ($roundsToShow as $round) {
                $this->getScoresForRound($round, $tournamentId, $results);
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
            return response()->json(['error' => 'Failed to retrieve scores from Contao.'], 500);
        }
    }

    /**
     * Get scores for a specific round
     * @param string $round Round type (e.g., 'VR', 'VF', 'HF')
     * @param int $tournamentId Tournament region ID
     * @param array &$results Reference to results array to populate
     */
    private function getScoresForRound(string $round, int $tournamentId, array &$results): void
    {
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

        if (!$scores) {
            return;
        }

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

    /**
     * Get rounds to show setting for an event
     */
    private function getRoundsToShow($eventId, $tournamentId): object
    {
        // 1) Get manually published rounds from the database
        $settings = DB::table('contao_public_rounds')->where('event_id', $eventId)->first();

        if (!$tournamentId && $settings) {
            return $settings;
        }

        // 2) Überprüfen, welche Runden automatisch veröffentlicht werden können
        $completed = null;
        if ($tournamentId) {
            $completed = $this->getCompletedRounds($tournamentId);
        }

        // Fehler beim Berechnen der abgeschlossenen Runden -> manuelle Werte nutzen
        if (!$completed && $settings) {
            return $settings;
        } else if ($completed && !$settings) {
            // Einstellung in DB speichern
            DB::table('contao_public_rounds')->updateOrInsert(
                ['event_id' => $eventId],
                $completed
            );
            return (object)$completed;
        } else if ($completed && $settings) {
            // Merge mit manuellen Einstellungen
            $merged = [
                'vr1' => ($settings->vr1 || $completed['vr1']) ? 1 : 0,
                'vr2' => ($settings->vr2 || $completed['vr2']) ? 1 : 0,
                'vr3' => ($settings->vr3 || $completed['vr3']) ? 1 : 0,
                'vf' => ($settings->vf || $completed['vf']) ? 1 : 0,
                'hf' => ($settings->hf || $completed['hf']) ? 1 : 0,
            ];

            DB::table('contao_public_rounds')->updateOrInsert(
                ['event_id' => $eventId],
                $merged
            );

            return (object)$merged;
        }

        // 3) Default: Nothing visible
        return (object)[
            'vr1' => false,
            'vr2' => false,
            'vr3' => false,
            'vf' => false,
            'hf' => false,
        ];
    }

    private string $completedVrSql = <<<'SQL'
        WITH vr_assess AS (
        SELECT a.team, count(a.team) as c, r.matches * 2 / 3 as matches
        FROM tl_hot_round r
            JOIN tl_hot_tournament t ON r.tournament = t.id
            JOIN tl_hot_match m ON m.round = r.id
            JOIN tl_hot_assessment a ON a.matchx = m.id
        WHERE t.region = :region
            AND r.type = 'VR'
            AND a.confirmed_team = TRUE
            AND a.confirmed_referee = TRUE
        GROUP BY a.team, r.matches
        )
        SELECT
            IF(MAX(matches) > 0, SUM(c > 0) / MAX(matches), 0) as vr1,
            IF(MAX(matches) > 0, SUM(c > 1) / MAX(matches), 0) as vr2,
            IF(MAX(matches) > 0, SUM(c > 2) / MAX(matches), 0) as vr3,
            MAX(matches) as matches
        FROM vr_assess;
        SQL;

    private string $completedFinalsSql = <<<'SQL'
        SELECT r.matches, r.type, count(a.id) / 2 as assessments_count
        FROM tl_hot_round r
            JOIN tl_hot_tournament as t ON r.tournament = t.id
            JOIN tl_hot_match as m ON m.round = r.id
            JOIN tl_hot_assessment as a ON a.matchx = m.id
        WHERE t.region = :region
            AND a.confirmed_team = TRUE
            AND a.confirmed_referee = TRUE
        GROUP BY r.type, r.matches;
        SQL;


    private function getCompletedRounds(int $tournamentId): ?array
    {
        $pdo = DB::connection('contao')->getPdo();

        // 1) Vorrunde prüfen
        $stmt = $pdo->prepare($this->completedVrSql);
        $stmt->bindValue(':region', $tournamentId, \PDO::PARAM_INT);
        $stmt->execute();
        $vrResult = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$vrResult) {
            Log::error('Failed to fetch VR completion data for tournament ID: ' . $tournamentId);
            return null;
        }

        if ($vrResult['vr3'] < 0.6) {
            // Vorrunden noch nicht abgeschlossen, keine Prüfung der Finalrunden notwendig
            return [
                'vr1' => ($vrResult['vr1'] > 0.6) && ($vrResult['vr2'] > 0.3),
                'vr2' => ($vrResult['vr2'] > 0.6) && ($vrResult['vr3'] > 0.3),
                'vr3' => false,
                'vf' => false,
                'hf' => false,
            ];
        }

        // 2) Finalrunden prüfen
        $stmt = $pdo->prepare($this->completedFinalsSql);
        $stmt->bindValue(':region', $tournamentId, \PDO::PARAM_INT);
        $stmt->execute();
        $finalsResults = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $progress = [];
        foreach ($finalsResults as $finalResult) {
            $type = $finalResult['type'];
            $matches = $finalResult['matches'];
            $assessmentsCount = $finalResult['assessments_count'];

            if ($matches <= 0) {
                $progress[$type] = 0.0;
                continue;
            }

            $val = $assessmentsCount / $matches;
            $progress[$type] = max(0.0, min(1.0, $val));
        }

        // Runde abgeschlossen und öffentlich sichtbar wenn
        // -> mindestens 2/3 der Paarungen bewertet sind (ignoriert Teams die nicht erschienen sind)
        // -> und die nächste Runde zu mindestens 1/3 bewertet ist (um sicherzustellen, dass die nächste Runde auch wirklich begonnen hat)
        // Das Halbfinale ist nie automatisch öffentlich sichtbar, da diese Ergebnisse in der Siegerehrung vorgestellt werden sollen.
        return [
            'vr1' => ($vrResult['vr1'] ?? 0 > 0.6) && ($vrResult['vr2'] ?? 0 > 0.3),
            'vr2' => ($vrResult['vr2'] ?? 0 > 0.6) && ($vrResult['vr3'] ?? 0 > 0.3),
            'vr3' => ($vrResult['vr3'] ?? 0 > 0.6) && (($progress['VF'] ?? 0 > 0.3) || ($progress['HF'] ?? 0 > 0.3)),
            'vf' => ($progress['VF'] ?? 0 > 0.6) & ($progress['HF'] ?? 0 > 0.3),
            'hf' => false,
        ];
    }

    public function getRoundsToShowEndpoint(Request $request, $eventId): JsonResponse
    {
        $tournamentId = $this->getTournamentId($eventId);
        if (!$tournamentId) {
            return response()->json(['error' => "No Contao ID found for event {$eventId}. Please set contao_id_challenge or contao_id_explore."], 404);
        }
        $roundsToShow = $this->getRoundsToShow($eventId, $tournamentId);
        return response()->json($roundsToShow);
    }

    public function saveRoundsToShow(Request $request, $eventId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'vr1' => 'nullable|boolean',
                'vr2' => 'nullable|boolean',
                'vr3' => 'nullable|boolean',
                'vf' => 'nullable|boolean',
                'hf' => 'nullable|boolean',
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
                'vf' => isset($validated['vf']) ? (int)$validated['vf'] : 0,
                'hf' => isset($validated['hf']) ? (int)$validated['hf'] : 0,
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

    private function getMatchups($round, $tournamentId)
    {
        return DB::connection('contao')
            ->table('hot_round as r')
            ->join('hot_tournament as t', 'r.tournament', '=', 't.id')
            ->join('hot_match as m', 'm.round', '=', 'r.id')
            ->join('hot_teams as ta', 'm.team_a', '=', 'ta.id')
            ->join('hot_teams as tb', 'm.team_b', '=', 'tb.id')
            ->where('t.region', $tournamentId)
            ->where('r.type', $round)
            ->select('ta.team_name as aname', 'ta.dolibarrId as adbid', 'ta.team_id as aid', 'tb.team_name as bname', 'tb.dolibarrId as bdbid', 'tb.team_id as bid')
            ->orderBy('m.id', 'asc')
            ->get();
    }

    private function findTeamByHotId($hotId, $eventId, $planId)
    {
        return DB::table('team')
            ->join('team_plan as tp', 'team.id', '=', 'tp.team')
            ->where('team.event', $eventId)
            ->where('team.team_number_hot', $hotId)
            ->where('team.first_program', 3) // Challenge (TODO: nicht hardcoden!)
            ->where('tp.plan', $planId)
            ->select('tp.team_number_plan as id');
    }

    private function roundToCode($round)
    {
        return match ($round) {
            'vr1' => 'r_round_1',
            'vr2' => 'r_round_2',
            'vr3' => 'r_round_3',
            'af' => 'r_final_16',
            'vf' => 'r_final_8',
            'hf' => 'r_final_4',
            default => throw new Exception("Unknown round type: {$round}"),
        };
    }

    private function writeMatchupsToSchedule($round, $tournamentId, $eventId, $planId)
    {
        $matchups = $this->getMatchups($round, $tournamentId);

        $code = $this->roundToCode($round);
        $activities = DB::table('activity', 'a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->where('atd.code', $code)
            ->where('ag.plan', $planId)
            ->get();

        $teams = [];

        for ($i = 0; $i < count($activities); $i++) {
            if ($i >= count($matchups)) {
                break;
            }
            $matchup = $matchups[$i];
            $activity = $activities[$i];

            $teamA = $this->findTeamByHotId($matchup->team_a, $eventId, $planId);
            $teamB = $this->findTeamByHotId($matchup->team_b, $eventId, $planId);

            $teams[] = $teamA;
            $teams[] = $teamB;

            if (isset($teamA->id) && $teamA->id > 0 && isset($teamB->id) && $teamB->id > 0) {
                Log::info("Mapping matchup for round {$round}: Team A HOT ID {$matchup->aid} -> Team ID {$teamA->id}, Team B HOT ID {$matchup->bid} -> Team ID {$teamB->id}");
                DB::table('activity')
                    ->where('id', $activity->id)
                    ->update([
                        'table_1_team' => $teamA->id,
                        'table_2_team' => $teamB->id,
                    ]);
            }
        }

        $activities_new = DB::table('activity', 'a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->where('atd.code', $code)
            ->where('ag.plan', $planId)
            ->get();


        return ['status' => 'ok', 'message' => "Matchups for round {$round} written to schedule", 'matchups' => $matchups, 'code', $code, 'teams' => $teams, 'activities' => $activities, 'activities_updated' => $activities_new];
    }

    public function writeRoundsEndpoint(Request $request)
    {
        $round = $request->query('round');
        $eventId = $request->query('event');
        $planId = $request->query('plan');
        $tournamentId = $this->getTournamentId($eventId);

        Log::info("writeRoundsEndpoint called with round={$round}, eventId={$eventId}, planId={$planId}, tournamentId={$tournamentId}");

        return $this->writeMatchupsToSchedule($round, $tournamentId, $eventId, $planId);
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
