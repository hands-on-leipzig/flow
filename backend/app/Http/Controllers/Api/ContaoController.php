<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use app\Services\ContaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ContaoController extends Controller
{

    public function __construct(private readonly ContaoService $contaoService)
    {
    }

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

            return $this->contaoService->getScores($eventId, $tournamentId);
        } catch (Exception $e) {
            Log::error('Contao getScore error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve scores from Contao.'], 500);
        }
    }

    public function getRoundsToShowEndpoint(Request $request, $eventId): JsonResponse
    {
        $tournamentId = $this->getTournamentId($eventId);
        if (!$tournamentId) {
            return response()->json(['error' => "No Contao ID found for event {$eventId}. Please set contao_id_challenge or contao_id_explore."], 404);
        }
        $roundsToShow = $this->contaoService->getRoundsToShow($eventId, $tournamentId);
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

            // In 0/1/null umwandeln für die Datenbank
            $payload = [
                'vr1' => isset($validated['vr1']) ? (int)$validated['vr1'] : null,
                'vr2' => isset($validated['vr2']) ? (int)$validated['vr2'] : null,
                'vr3' => isset($validated['vr3']) ? (int)$validated['vr3'] : null,
                'vf' => isset($validated['vf']) ? (int)$validated['vf'] : null,
                'hf' => isset($validated['hf']) ? (int)$validated['hf'] : null,
            ];

            DB::table('contao_public_rounds')->updateOrInsert(
                ['event_id' => $eventId],
                $payload
            );

            return response()->json(['status' => 'ok', 'values' => $payload]);
        } catch (Exception $e) {
            Log::error('Contao saveRoundsToShow error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to save rounds_to_show'], 500);
        }
    }

    public function writeRoundsEndpoint(Request $request): JsonResponse
    {
        $round = $request->query('round');
        $eventId = (int) $request->query('event');
        $tournamentId = $this->getTournamentId($eventId);

        Log::info("writeRoundsEndpoint called with round={$round}, eventId={$eventId}}, tournamentId={$tournamentId}");

        try {
            $result = $this->contaoService->writeMatchupsToSchedule($round, $tournamentId, $eventId);
            return response()->json(['status' => 'ok', 'values' => $result]);
        } catch (Exception $e) {
            Log::error('Error in writeRoundsEndpoint: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to write rounds to schedule', 'error' => $e->getMessage(), 'file' => $e->getFile(), 'code' => $e->getCode(), 'trace' => $e->getTraceAsString()], 500);
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
