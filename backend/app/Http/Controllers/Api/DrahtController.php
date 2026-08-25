<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Models\MSeason;
use App\Models\RegionalPartner;
use App\Models\Team;
use App\Support\ProgramCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;

class DrahtController extends Controller
{

    public function makeDrahtCall($route)
    {
        $headers = ['DOLAPIKEY' => config('services.draht_api.key')];
        return Http::withHeaders($headers)->get(config('services.draht_api.base_url') . $route);
    }

    /**
     * Make a POST request to DRAHT API
     */
    public function makeDrahtPostCall($route, array $data)
    {
        $headers = ['DOLAPIKEY' => config('services.draht_api.key')];
        return Http::withHeaders($headers)
            ->post(config('services.draht_api.base_url') . $route, $data);
    }


    public function show(Event $event)
    {
        return response()->json($this->fetchScheduleData($event)['data']);
    }

    /**
     * Same payload as show(), plus whether any DRAHT scheduledata HTTP call succeeded.
     * ok is true when the event has no draht_id (nothing to fetch) or at least one call returned JSON.
     *
     * @return array{ok: bool, data: array<string, mixed>}
     */
    public function fetchScheduleData(Event $event): array
    {
        $event->loadMissing('programs.firstProgram');

        $mergedData = [
            'programs' => [],
            'address' => null,
            'contact' => null,
            'information' => null,
        ];

        $attempted = 0;
        $succeeded = 0;

        foreach ($event->programs as $row) {
            if (! $row->draht_id) {
                $mergedData['programs'][] = [
                    'first_program' => $row->first_program,
                    'name' => $row->name,
                    'sequence' => $row->sequence,
                    'draht_id' => null,
                    'scheduledata' => null,
                    'teams' => [],
                    'capacity' => 0,
                ];
                continue;
            }

            $attempted++;
            $payload = null;
            try {
                $res = $this->makeDrahtCall("/handson/events/{$row->draht_id}/scheduledata");
                $payload = $res->ok() ? $res->json() : null;
            } catch (\Throwable $e) {
                Log::warning('DRAHT scheduledata failed', [
                    'event_id' => $event->id,
                    'draht_id' => $row->draht_id,
                    'error' => $e->getMessage(),
                ]);
            }

            if (is_array($payload)) {
                $succeeded++;
                $mergedData['address'] ??= $payload['address'] ?? null;
                $mergedData['contact'] ??= $this->formatContactData($payload['contact'] ?? null);
                $mergedData['information'] ??= $payload['information'] ?? null;
            }

            $mergedData['programs'][] = [
                'first_program' => $row->first_program,
                'name' => $row->name,
                'sequence' => $row->sequence,
                'draht_id' => $row->draht_id,
                'scheduledata' => is_array($payload) ? $payload : null,
                'teams' => is_array($payload) ? ($payload['teams'] ?? []) : [],
                'capacity' => is_array($payload) ? ($payload['capacity_teams'] ?? 0) : 0,
            ];
        }

        return [
            'ok' => $attempted === 0 || $succeeded > 0,
            'data' => $mergedData,
        ];
    }

    public function getAllRegions()
    {
        try {
            Log::info('Starting sync-draht-regions');

            $res = $this->makeDrahtCall("/handson/rp");

            if (!$res->ok()) {
                Log::error('Draht API call failed', [
                    'status' => $res->status(),
                    'body' => $res->body()
                ]);
                return response()->json([
                    'error' => 'Failed to fetch regions from Draht API',
                    'status' => $res->status(),
                    'message' => $res->body()
                ], 500);
            }

            $regions = $res->json();
            Log::info('Received regions from Draht API', ['count' => count($regions)]);

            // Get existing regional partners by dolibarr_id
            $existingRegions = RegionalPartner::whereIn('dolibarr_id', array_column($regions, 'id'))
                ->get()
                ->keyBy('dolibarr_id');

            $created = 0;
            $updated = 0;

            foreach ($regions as $r) {
                try {
                    $dolibarrId = $r['id'];

                    if ($existingRegions->has($dolibarrId)) {
                        // Update existing regional partner
                        $region = $existingRegions[$dolibarrId];
                        $region->name = $r['name'];
                        $region->region = $r['name'];
                        $region->save();
                        $updated++;
                        Log::info('Updated regional partner', ['id' => $region->id, 'name' => $r['name']]);
                    } else {
                        // Create new regional partner
                        $region = new RegionalPartner();
                        $region->name = $r['name'];
                        $region->dolibarr_id = $r['id'];
                        $region->region = $r['name'];
                        $region->save();
                        $created++;
                        Log::info('Created regional partner', ['id' => $region->id, 'name' => $r['name']]);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to save regional partner', [
                        'data' => $r,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('Sync completed successfully', ['created' => $created, 'updated' => $updated]);

            return response()->json([
                'status' => 200,
                'message' => 'Regions synced successfully',
                'created' => $created,
                'updated' => $updated,
                'total' => count($regions)
            ]);

        } catch (\Exception $e) {
            Log::error('Error in sync-draht-regions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getAllEventsAndTeams(int $seasonId)
    {
        try {
            $response = $this->makeDrahtCall("/handson/flow/events");

            if (!$response->ok()) {
                Log::error('Failed to fetch events from Draht API', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json([
                    'error' => 'Failed to fetch events from Draht API',
                    'message' => 'HTTP ' . $response->status() . ': ' . $response->body()
                ], 500);
            }

            ini_set('max_execution_time', 300);
            $eventsData = $response->json();

            if (!is_array($eventsData)) {
                Log::error('Invalid response format from Draht API', [
                    'response' => $eventsData
                ]);
                return response()->json([
                    'error' => 'Invalid response format from Draht API',
                    'message' => 'Expected array but got ' . gettype($eventsData)
                ], 500);
            }

            $icsEventIds = [];
            DB::transaction(function () use ($seasonId, $eventsData, &$icsEventIds) {
                // Track which events we've processed to identify events that should be deleted
                $processedEventIds = [];
                $processedDrahtIds = [];

                foreach ($eventsData as $eventData) {
                    try {
                        $date = (isset($eventData["date"]) && $eventData["date"] != "") ? $eventData["date"] : "1970-01-01";
                        $enddate = (isset($eventData["enddate"]) && $eventData["enddate"] != "") ? $eventData["enddate"] : "1970-01-01";

                        $regionalPartner = RegionalPartner::where('dolibarr_id', $eventData['region'])->first();
                        $firstProgram = (int) $eventData['first_program'];
                        $days = 1;

                        $existingEvent = Event::where('season', $seasonId)
                            ->whereHas('programs', function ($query) use ($eventData) {
                                $query->where('draht_id', $eventData['id']);
                            })
                            ->first();

                        if (! $existingEvent) {
                            $existingEvent = Event::where('regional_partner', $regionalPartner?->id)
                                ->where('date', $date)
                                ->where('season', $seasonId)
                                ->whereDoesntHave('programs', function ($query) use ($firstProgram) {
                                    $query->where('first_program', $firstProgram);
                                })
                                ->first();
                        }

                        if ($existingEvent) {
                            $existingEvent->update([
                                'name' => $eventData['name'] ?? $existingEvent->name,
                                'date' => $date,
                                'enddate' => $enddate,
                                'days' => $days,
                                'regional_partner' => $regionalPartner?->id ?? $existingEvent->regional_partner,
                                'level' => $eventData['level'] ?? $existingEvent->level,
                            ]);
                            $event = $existingEvent;
                        } else {
                            $event = Event::create([
                                'name' => $eventData['name'] ?? null,
                                'date' => $date,
                                'enddate' => $enddate,
                                'season' => $seasonId,
                                'days' => $days,
                                'regional_partner' => $regionalPartner?->id,
                                'level' => $eventData['level'] ?? null,
                            ]);

                            // Automatically generate link and QR code for new events using existing PublishController
                            try {
                                $publishController = app(\App\Http\Controllers\Api\PublishController::class);
                                $publishController->linkAndQRcode($event->id);
                                Log::info("Automatically generated link and QR code for new event {$event->id}");
                            } catch (\Exception $e) {
                                Log::error("Failed to auto-generate link and QR code for event {$event->id}", [
                                    'error' => $e->getMessage()
                                ]);
                                // Don't fail the entire process if link generation fails
                            }
                        }

                        ProgramCatalog::upsertDrahtProgram(
                            $event,
                            $firstProgram,
                            (int) $eventData['id'],
                            isset($eventData['contao_id']) ? (int) $eventData['contao_id'] : null
                        );

                        $processedEventIds[] = $event->id;
                        $processedDrahtIds[] = $eventData['id'];
                        $icsEventIds[] = $event->id;
                        if (isset($eventData['teams']) && is_array($eventData['teams'])) {
                            $existingTeams = Team::where('event', $event->id)
                                ->get()
                                ->keyBy('team_number_hot');

                            $processedTeamNumbers = [];

                            foreach ($eventData['teams'] as $teamData) {
                                $teamNumberHot = $teamData['team_number_hot'] ?? null;

                                if ($teamNumberHot === null) {
                                    Log::warning('Skipping team without team_number_hot', [
                                        'event_id' => $event->id,
                                        'team_data' => $teamData
                                    ]);
                                    continue;
                                }

                                $processedTeamNumbers[] = $teamNumberHot;

                                $existingTeam = $existingTeams->get($teamNumberHot);

                                if ($existingTeam) {
                                    $existingTeam->update([
                                        'name' => $teamData['name'],
                                        'location' => $teamData['location'] ?? null,
                                        'organization' => $teamData['organization'] ?? null,
                                        'first_program' => $teamData['first_program'] ?? $existingTeam->first_program,
                                    ]);
                                } else {
                                    Team::create([
                                        'event' => $event->id,
                                        'name' => $teamData['name'],
                                        'team_number_hot' => $teamNumberHot,
                                        'first_program' => $teamData['first_program'] ?? $firstProgram,
                                        'location' => $teamData['location'] ?? null,
                                        'organization' => $teamData['organization'] ?? null,
                                    ]);
                                }
                            }

                            $teamsToDelete = Team::where('event', $event->id)
                                ->whereNotIn('team_number_hot', $processedTeamNumbers)
                                ->whereDoesntHave('teamPlans')
                                ->get();

                            foreach ($teamsToDelete as $teamToDelete) {
                                $teamToDelete->delete();
                            }

                            $teamsWithPlans = Team::where('event', $event->id)
                                ->whereNotIn('team_number_hot', $processedTeamNumbers)
                                ->whereHas('teamPlans')
                                ->get();

                            if ($teamsWithPlans->isNotEmpty()) {
                                Log::warning('Teams not deleted because they have team_plan entries', [
                                    'event_id' => $event->id,
                                    'team_numbers' => $teamsWithPlans->pluck('team_number_hot')->toArray()
                                ]);
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('Error processing event from Draht', [
                            'event_data' => $eventData,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        continue;
                    }
                }
            });

            foreach (array_unique($icsEventIds) as $eventId) {
                app(\App\Services\CalendarFeedService::class)->rebuildSafely((int) $eventId);
            }

            return response()->json(['status' => 200, 'message' => 'Events and teams synced successfully']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Connection error while fetching events from Draht API', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'error' => 'Connection error',
                'message' => 'Could not connect to Draht API: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error in getAllEventsAndTeams', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the public link in DRAHT for an event
     *
     * @param int $drahtEventId The DRAHT event ID
     * @param string $link The public link URL
     * @return bool True if successful, false otherwise
     */
    public function updateEventLink(int $drahtEventId, string $link): bool
    {
        try {
            $response = $this->makeDrahtPostCall(
                "/handson/planner/setplanlink/{$drahtEventId}",
                ['data' => ['link' => $link]]
            );

            if ($response->ok()) {
                Log::info("Successfully updated link in DRAHT for event {$drahtEventId}", [
                    'link' => $link
                ]);
                return true;
            } else {
                Log::error("Failed to update link in DRAHT for event {$drahtEventId}", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'link' => $link
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception while updating link in DRAHT for event {$drahtEventId}", [
                'error' => $e->getMessage(),
                'link' => $link
            ]);
            return false;
        }
    }

    /**
     * Get regional partners for a user from Draht API
     *
     * @param int $dolibarrId The user's dolibarr_id
     * @return array Array of regional partner dolibarr_ids
     */
    public function getUserRegionalPartners(int $dolibarrId): array
    {
        try {
            $response = $this->makeDrahtCall("/handson/contact/{$dolibarrId}/regionalpartner");

            if (!$response->ok()) {
                Log::warning("Failed to fetch regional partners for user", [
                    'dolibarr_id' => $dolibarrId,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return [];
            }

            $data = $response->json();

            // Handle different response formats
            if (is_array($data)) {
                // If it's an array of IDs
                if (isset($data[0]) && is_numeric($data[0])) {
                    return $data;
                }
                // If it's an array of objects with 'id' field
                if (isset($data[0]) && is_array($data[0]) && isset($data[0]['id'])) {
                    return array_column($data, 'id');
                }
                // If it's a single object with 'id' field
                if (isset($data['id'])) {
                    return [$data['id']];
                }
            }

            Log::warning("Unexpected response format from Draht API for user regional partners", [
                'dolibarr_id' => $dolibarrId,
                'response' => $data
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error("Exception while fetching regional partners for user", [
                'dolibarr_id' => $dolibarrId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Sync user-regional partner relations from Draht API
     *
     * @param \App\Models\User $user The user to sync
     * @return bool True if sync was successful, false otherwise
     */
    public function syncUserRegionalPartners(\App\Models\User $user): bool
    {
        if (!$user->dolibarr_id) {
            Log::info("Skipping regional partner sync - user has no dolibarr_id", [
                'user_id' => $user->id,
                'subject' => $user->subject
            ]);
            return false;
        }

        try {
            $regionalPartnerIds = $this->getUserRegionalPartners($user->dolibarr_id);
            $sourceDraht = \App\Support\FlowAccess::SOURCE_DRAHT;
            $sourceManual = \App\Support\FlowAccess::SOURCE_MANUAL;

            // Only Draht-sourced links are managed here. Manual FLOW grants stay untouched.
            if (empty($regionalPartnerIds)) {
                Log::info("No regional partners found for user in Draht", [
                    'user_id' => $user->id,
                    'dolibarr_id' => $user->dolibarr_id
                ]);
                DB::table('user_regional_partner')
                    ->where('user', $user->id)
                    ->where('source', $sourceDraht)
                    ->delete();
                return true;
            }

            $regionalPartners = RegionalPartner::whereIn('dolibarr_id', $regionalPartnerIds)->get();

            if ($regionalPartners->isEmpty()) {
                Log::warning("Regional partners not found in database", [
                    'user_id' => $user->id,
                    'dolibarr_ids' => $regionalPartnerIds
                ]);
                return false;
            }

            $targetRelations = $regionalPartners->pluck('id')->map(fn ($id) => (int) $id)->all();

            $currentDraht = DB::table('user_regional_partner')
                ->where('user', $user->id)
                ->where('source', $sourceDraht)
                ->pluck('regional_partner')
                ->map(fn ($id) => (int) $id)
                ->all();

            $currentManual = DB::table('user_regional_partner')
                ->where('user', $user->id)
                ->where('source', $sourceManual)
                ->pluck('regional_partner')
                ->map(fn ($id) => (int) $id)
                ->all();

            $toRemove = array_diff($currentDraht, $targetRelations);
            if (!empty($toRemove)) {
                DB::table('user_regional_partner')
                    ->where('user', $user->id)
                    ->where('source', $sourceDraht)
                    ->whereIn('regional_partner', $toRemove)
                    ->delete();
                Log::info("Removed Draht regional partner relations", [
                    'user_id' => $user->id,
                    'removed' => array_values($toRemove)
                ]);
            }

            $toAdd = array_diff($targetRelations, $currentDraht, $currentManual);
            if (!empty($toAdd)) {
                $insertData = array_map(function ($rpId) use ($user, $sourceDraht) {
                    return [
                        'user' => $user->id,
                        'regional_partner' => $rpId,
                        'source' => $sourceDraht,
                        'granted_at' => now(),
                        'granted_by' => null,
                    ];
                }, $toAdd);

                DB::table('user_regional_partner')->insert($insertData);
                Log::info("Added Draht regional partner relations", [
                    'user_id' => $user->id,
                    'added' => array_values($toAdd)
                ]);
            }

            // If Draht confirms a previously manual grant, mark it as Draht-owned
            $toPromote = array_intersect($targetRelations, $currentManual);
            if (!empty($toPromote)) {
                DB::table('user_regional_partner')
                    ->where('user', $user->id)
                    ->where('source', $sourceManual)
                    ->whereIn('regional_partner', $toPromote)
                    ->update([
                        'source' => $sourceDraht,
                        'granted_by' => null,
                    ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to sync user regional partners", [
                'user_id' => $user->id,
                'dolibarr_id' => $user->dolibarr_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Get people data (players and coaches) for a DRAHT event
     *
     * @param int $drahtEventId The DRAHT event ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPeople(int $drahtEventId)
    {
        try {
            $response = $this->makeDrahtCall("/handson/flow/{$drahtEventId}/people");

            if (!$response->ok()) {
                Log::error("Failed to fetch people data from DRAHT API", [
                    'draht_event_id' => $drahtEventId,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json([
                    'error' => 'Failed to fetch people data from DRAHT API',
                    'status' => $response->status()
                ], $response->status());
            }

            $peopleData = $response->json();
            return response()->json($peopleData);
        } catch (\Exception $e) {
            Log::error("Exception while fetching people data from DRAHT API", [
                'draht_event_id' => $drahtEventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get coordinates of all the teams of a given event
     *
     * @param int $event
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTeamsCoordinates(Event $event)
    {
        try {
            $response = $this->makeDrahtCall("/handson/teams/{$event->programs->first()?->draht_id}/locations");

            if (!$response->ok()) {
                Log::error("Failed to fetch teams locations from DRAHT API", [
                    'event_id' => $event->id,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json([
                    'error' => 'Failed to fetch teams locations from DRAHT API',
                    'status' => $response->status()
                ], $response->status());
            }
            $locations = $response->json();
            return response()->json($locations);
        } catch (\Exception $e) {
            Log::error("Failed to fetch teams locations from DRAHT API", [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format contact data for frontend consumption
     */
    private function formatContactData($contactData)
    {
        if (!$contactData) {
            return [];
        }

        // If it's already an array, return it
        if (is_array($contactData)) {
            return $contactData;
        }

        // If it's a serialized string, unserialize it
        if (is_string($contactData)) {
            $unserialized = @unserialize($contactData);
            if ($unserialized && is_array($unserialized)) {
                return $unserialized;
            }
        }

        return [];
    }
}
