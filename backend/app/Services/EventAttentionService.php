<?php

namespace App\Services;

use App\Models\Event;
use App\Models\FirstProgram;
use App\Http\Controllers\Api\DrahtController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\PlanRoomTypeController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class EventAttentionService
{
    /**
     * Calculate if an event needs attention based on:
     * - Teams: Discrepancy between local and DRAHT teams
     * - Schedule: Planned teams vs registered teams mismatch
     * - Rooms: Unmapped room types or teams without rooms
     * 
     * Returns true if ANY of these conditions indicate attention is needed
     */
    public function calculateNeedsAttention(int $eventId): bool
    {
        $event = Event::find($eventId);
        if (!$event) {
            return false;
        }

        $plan = DB::table('plan')->where('event', $eventId)->first();
        if (!$plan) {
            // No plan exists - could be an issue, but return false for now
            // (can be adjusted based on business logic)
            return false;
        }

        // Check 1: Team Discrepancy (Teams tab)
        $hasTeamDiscrepancy = $this->checkTeamDiscrepancy($event);
        
        // Check 2: Schedule - Planned vs Registered Teams (Schedule tab)
        $hasScheduleIssue = $this->checkScheduleTeams($event, $plan->id);
        
        // Check 3: Room Mapping (Rooms tab)
        $hasRoomMappingIssue = $this->checkRoomMapping($event, $plan->id);

        // Event needs attention if ANY check fails
        return $hasTeamDiscrepancy || $hasScheduleIssue || $hasRoomMappingIssue;
    }

    /**
     * Update the needs_attention status for an event in the database
     */
    public function updateEventAttentionStatus(int $eventId): void
    {
        try {
            $needsAttention = $this->calculateNeedsAttention($eventId);
            
            DB::table('event')
                ->where('id', $eventId)
                ->update([
                    'needs_attention' => $needsAttention,
                    'needs_attention_checked_at' => now(),
                ]);
            
            Log::debug("Updated needs_attention for event {$eventId}: " . ($needsAttention ? 'true' : 'false'));
        } catch (\Exception $e) {
            Log::error("Failed to update needs_attention for event {$eventId}: " . $e->getMessage());
            // Don't throw - allow operation to continue even if attention update fails
        }
    }

    /**
     * Ensure attention status is calculated for an event (lazy initialization)
     * If needs_attention_checked_at is null, calculate and update the status
     * 
     * @param int $eventId
     * @return bool True if status was calculated, false if already existed
     */
    public function ensureAttentionStatusCalculated(int $eventId): bool
    {
        $event = Event::find($eventId);
        if (!$event) {
            return false;
        }

        // If already calculated, nothing to do
        if ($event->needs_attention_checked_at !== null) {
            return false;
        }

        // Calculate and update
        $this->updateEventAttentionStatus($eventId);
        return true;
    }

    /**
     * Check 1: Team Discrepancy
     * Returns true if local teams differ from DRAHT teams
     */
    private function checkTeamDiscrepancy(Event $event): bool
    {
        try {
            $drahtController = app(DrahtController::class);
            $response = $drahtController->show($event);
            $drahtData = $response->getData(true);

            $teamController = app(TeamController::class);

            // Check Explore teams (only if event_explore exists)
            if ($event->event_explore) {
                $requestExplore = new Request();
                $requestExplore->query->set('program', 'explore');
                $exploreResponse = $teamController->index($requestExplore, $event);
                $exploreData = $exploreResponse->getData(true);
                $localExplore = is_array($exploreData) && !isset($exploreData['teams'])
                    ? $exploreData
                    : ($exploreData['teams'] ?? []);
                
                $drahtExplore = $drahtData['teams_explore'] ?? [];
                
                if ($this->hasDiscrepancy($localExplore, $drahtExplore)) {
                    return true;
                }
            }

            // Check Challenge teams (only if event_challenge exists)
            if ($event->event_challenge) {
                $requestChallenge = new Request();
                $requestChallenge->query->set('program', 'challenge');
                $challengeResponse = $teamController->index($requestChallenge, $event);
                $challengeData = $challengeResponse->getData(true);
                $localChallenge = is_array($challengeData) ? $challengeData : ($challengeData['teams'] ?? []);
                
                $drahtChallenge = $drahtData['teams_challenge'] ?? [];
                
                if ($this->hasDiscrepancy($localChallenge, $drahtChallenge)) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::error("Error checking team discrepancy for event {$event->id}: " . $e->getMessage());
            return false; // On error, assume no discrepancy (conservative approach)
        }
    }

    /**
     * Helper: Check if there's a discrepancy between local and DRAHT teams
     */
    private function hasDiscrepancy(array $localTeams, array $drahtTeams): bool
    {
        // Normalize team numbers for comparison
        $normalizeTeamNumber = function($num) {
            if ($num == null || $num === '' || $num === 0) return null;
            $normalized = (int)$num;
            return ($normalized === 0 || is_nan($normalized)) ? null : $normalized;
        };

        // Create maps by team number
        $localMap = [];
        foreach ($localTeams as $team) {
            $num = $normalizeTeamNumber($team['team_number_hot'] ?? null);
            if ($num != null) {
                $localMap[$num] = $team;
            }
        }

        $drahtMap = [];
        foreach ($drahtTeams as $team) {
            $num = $normalizeTeamNumber($team['ref'] ?? $team['number'] ?? null);
            if ($num != null) {
                $drahtMap[$num] = $team;
            }
        }

        // Collect all team numbers
        $allNumbers = array_unique(array_merge(array_keys($localMap), array_keys($drahtMap)));

        // Check for discrepancies
        foreach ($allNumbers as $number) {
            $local = $localMap[$number] ?? null;
            $draht = $drahtMap[$number] ?? null;

            // Conflict: Same number but different names
            if ($local && $draht && ($local['name'] ?? '') !== ($draht['name'] ?? '')) {
                return true;
            }
            // New: In DRAHT but not local
            if ($draht && !$local) {
                return true;
            }
            // Missing: In local but not DRAHT
            if ($local && !$draht) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check 2: Schedule Teams
     * Returns true if planned teams don't match registered teams
     */
    private function checkScheduleTeams(Event $event, int $planId): bool
    {
        try {
            // Get planned team counts
            $paramIds = DB::table('m_parameter')
                ->whereIn('name', ['c_teams', 'e_teams'])
                ->pluck('id', 'name');

            $values = DB::table('plan_param_value')
                ->where('plan', $planId)
                ->whereIn('parameter', $paramIds->values())
                ->pluck('set_value', 'parameter')
                ->map(fn($v) => (int)$v);

            $plannedChallengeTeams = $values[$paramIds['c_teams']] ?? 0;
            $plannedExploreTeams = $values[$paramIds['e_teams']] ?? 0;

            // Get registered team counts from DRAHT
            $drahtController = app(DrahtController::class);
            $response = $drahtController->show($event);
            $drahtData = $response->getData(true);

            $registeredChallengeTeams = isset($drahtData['teams_challenge'])
                ? count($drahtData['teams_challenge'])
                : 0;

            $registeredExploreTeams = isset($drahtData['teams_explore'])
                ? count($drahtData['teams_explore'])
                : 0;

            // Only check if the event has the corresponding DRAHT event ID
            $exploreTeamsOk = true;
            $challengeTeamsOk = true;

            if ($event->event_explore) {
                $exploreTeamsOk = ($plannedExploreTeams === $registeredExploreTeams);
            }

            if ($event->event_challenge) {
                $challengeTeamsOk = ($plannedChallengeTeams === $registeredChallengeTeams);
            }

            return !$exploreTeamsOk || !$challengeTeamsOk;
        } catch (\Exception $e) {
            Log::error("Error checking schedule teams for event {$event->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check 3: Room Mapping
     * Returns true if there are unmapped room types or teams without rooms
     */
    private function checkRoomMapping(Event $event, int $planId): bool
    {
        try {
            // Check for unmapped room types
            $planRoomTypeController = app(PlanRoomTypeController::class);
            $unmappedResponse = $planRoomTypeController->unmappedRoomTypes($planId);
            $unmappedList = $unmappedResponse->getData(true);
            $hasUnmappedRooms = !empty($unmappedList);

            // Check if all teams have rooms assigned
            $teamController = app(TeamController::class);
            
            $allTeamsHaveRooms = true;

            // Check Explore teams (only if event_explore exists)
            if ($event->event_explore) {
                $requestExplore = new Request();
                $requestExplore->query->set('program', 'explore');
                $exploreResponse = $teamController->index($requestExplore, $event);
                $exploreData = $exploreResponse->getData(true);
                $exploreTeams = is_array($exploreData) && !isset($exploreData['teams'])
                    ? collect($exploreData)
                    : collect($exploreData['teams'] ?? []);
                
                $exploreWithoutRoom = $exploreTeams->whereNull('room')->count();
                $allExploreRoomsOk = $exploreTeams->isEmpty() || $exploreWithoutRoom === 0;
                $allTeamsHaveRooms = $allTeamsHaveRooms && $allExploreRoomsOk;
            }

            // Check Challenge teams (only if event_challenge exists)
            if ($event->event_challenge) {
                $requestChallenge = new Request();
                $requestChallenge->query->set('program', 'challenge');
                $challengeResponse = $teamController->index($requestChallenge, $event);
                $challengeData = $challengeResponse->getData(true);
                $challengeTeams = is_array($challengeData) ? collect($challengeData) : collect($challengeData['teams'] ?? []);
                
                $challengeWithoutRoom = $challengeTeams->whereNull('room')->count();
                $allChallengeRoomsOk = $challengeTeams->isEmpty() || $challengeWithoutRoom === 0;
                $allTeamsHaveRooms = $allTeamsHaveRooms && $allChallengeRoomsOk;
            }

            return $hasUnmappedRooms || !$allTeamsHaveRooms;
        } catch (\Exception $e) {
            Log::error("Error checking room mapping for event {$event->id}: " . $e->getMessage());
            return false;
        }
    }
}
