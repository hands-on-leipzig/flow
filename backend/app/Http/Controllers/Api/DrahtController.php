<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Models\MSeason;
use App\Models\RegionalPartner;
use App\Models\Team;
use App\Enums\FirstProgram;
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
        $mergedData = [
            'event_challenge' => null,
            'event_explore' => null,
            'address' => null,
            'contact' => null,
            'information' => null,
            'teams_challenge' => [],
            'teams_explore' => [],
            'capacity_challenge' => 0,
            'capacity_explore' => 0,
        ];


        if ($event->event_challenge) {
            $res = $this->makeDrahtCall("/handson/events/{$event->event_challenge}/scheduledata");
            if ($res->ok()) {
                $challenge = $res->json();
                $mergedData['event_challenge'] = $challenge;
                $mergedData['address'] = $challenge['address'] ?? null;
                $mergedData['contact'] = $this->formatContactData($challenge['contact'] ?? null);
                $mergedData['information'] = $challenge['information'] ?? null;
                $mergedData['teams_challenge'] = $challenge['teams'] ?? [];
                $mergedData['capacity_challenge'] = $challenge['capacity_teams'] ?? 0;
            }
        }

        if ($event->event_explore) {
            $res = $this->makeDrahtCall("/handson/events/{$event->event_explore}/scheduledata");
            if ($res->ok()) {
                $explore = $res->json();
                $mergedData['event_explore'] = $explore;

                // overwrite shared fields only if not already set
                $mergedData['address'] ??= $explore['address'] ?? null;
                $mergedData['contact'] ??= $this->formatContactData($explore['contact'] ?? null);
                $mergedData['information'] ??= $explore['information'] ?? null;

                $mergedData['teams_explore'] = $explore['teams'] ?? [];
                $mergedData['capacity_explore'] = $explore['capacity_teams'] ?? 0;
            }
        }

        return response()->json($mergedData);
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
        $response = $this->makeDrahtCall("/handson/flow/events");

        if (!$response->ok()) {
            return response()->json(['error' => 'Failed to fetch events from Draht API'], 500);
        }

        ini_set('max_execution_time', 300);
        $eventsData = $response->json();

        DB::transaction(function () use ($seasonId, $eventsData) {
            // Track which events we've processed to identify events that should be deleted
            $processedEventIds = [];
            $processedDrahtIds = [];

            foreach ($eventsData as $eventData) {
                $date = (isset($eventData["date"]) && $eventData["date"] != "") ? $eventData["date"] : "1970-01-01";
                $enddate = (isset($eventData["enddate"]) && $eventData["enddate"] != "") ? $eventData["enddate"] : "1970-01-01";

                $regionalPartner = RegionalPartner::where('dolibarr_id', $eventData['region'])->first();
                $firstProgram = (int)$eventData['first_program'];

                $days = 1;

                $IDs = [];
                switch ($firstProgram) {
                    case FirstProgram::EXPLORE->value:
                        $IDs['event_explore'] = $eventData['id'];
                        $IDs['contao_id_explore'] = $eventData['contao_id'] ?? null;
                        break;
                    case FirstProgram::CHALLENGE->value:
                        $IDs['event_challenge'] = $eventData['id'];
                        $IDs['contao_id_challenge'] = $eventData['contao_id'] ?? null;
                        break;
                }

                // First, try to find event by draht ID (most reliable)
                $existingEvent = null;
                if ($firstProgram === FirstProgram::EXPLORE->value) {
                    $existingEvent = Event::where('event_explore', $eventData['id'])
                        ->where('season', $seasonId)
                        ->first();
                } elseif ($firstProgram === FirstProgram::CHALLENGE->value) {
                    $existingEvent = Event::where('event_challenge', $eventData['id'])
                        ->where('season', $seasonId)
                        ->first();
                }

                // If not found by draht ID, try fallback: find by date/regional_partner/season
                // (for events that don't have draht IDs yet)
                if (!$existingEvent) {
                    $existingEvent = Event::where('regional_partner', $regionalPartner?->id)
                        ->where('date', $date)
                        ->where('season', $seasonId)
                        ->where(function ($query) use ($firstProgram) {
                            if ($firstProgram === FirstProgram::EXPLORE->value) {
                                $query->whereNull('event_explore');
                            } elseif ($firstProgram === FirstProgram::CHALLENGE->value) {
                                $query->whereNull('event_challenge');
                            }
                        })
                        ->first();
                }

                if ($existingEvent) {
                    // Update existing event
                    $updateData = array_merge($IDs, [
                        'name' => $eventData['name'] ?? $existingEvent->name,
                        'date' => $date,
                        'enddate' => $enddate,
                        'days' => $days,
                        'regional_partner' => $regionalPartner?->id ?? $existingEvent->regional_partner,
                        'level' => $eventData['level'] ?? $existingEvent->level,
                    ]);

                    $existingEvent->update($updateData);
                    $event = $existingEvent;
                } else {
                    // Create new event
                    $eventAttributes = [
                        'name' => $eventData['name'] ?? null,
                        'date' => $date,
                        'enddate' => $enddate,
                        'season' => $seasonId,
                        'days' => $days,
                        'regional_partner' => $regionalPartner?->id,
                        'level' => $eventData['level'] ?? null,
                    ];
                    $eventAttributes = array_merge($eventAttributes, $IDs);

                    $event = Event::create($eventAttributes);

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

                $processedEventIds[] = $event->id;
                if ($firstProgram === FirstProgram::EXPLORE->value) {
                    $processedDrahtIds[] = $eventData['id'];
                } elseif ($firstProgram === FirstProgram::CHALLENGE->value) {
                    $processedDrahtIds[] = $eventData['id'];
                }

                // Sync teams for this event: delete existing teams and insert new ones
                // (Teams don't have unique identifiers from Draht, so delete/recreate is safest)
                Team::where('event', $event->id)->delete();

                if (isset($eventData['teams']) && is_array($eventData['teams'])) {
                    $teamsToCreate = collect($eventData['teams'])->map(function ($teamData) use ($event) {
                        return [
                            'event' => $event->id,
                            'name' => $teamData['name'],
                            'team_number_hot' => $teamData['team_number_hot'],
                            'first_program' => $teamData['first_program'],
                            'location' => $teamData['location'] ?? null,
                            'organization' => $teamData['organization'] ?? null,
                        ];
                    });

                    if ($teamsToCreate->isNotEmpty()) {
                        Team::insert($teamsToCreate->toArray());
                    }
                }
            }
        });

        return response()->json(['status' => 200, 'message' => 'Events and teams synced successfully']);
    }

    /**
     * Update the public link in DRAHT for an event
     *
     * @param int $drahtEventId The DRAHT event ID (event_explore or event_challenge)
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

            if (empty($regionalPartnerIds)) {
                Log::info("No regional partners found for user", [
                    'user_id' => $user->id,
                    'dolibarr_id' => $user->dolibarr_id
                ]);
                // Remove all existing relations if API returns empty
                DB::table('user_regional_partner')->where('user', $user->id)->delete();
                return true;
            }

            // Get regional partners by dolibarr_id
            $regionalPartners = RegionalPartner::whereIn('dolibarr_id', $regionalPartnerIds)->get();

            if ($regionalPartners->isEmpty()) {
                Log::warning("Regional partners not found in database", [
                    'user_id' => $user->id,
                    'dolibarr_ids' => $regionalPartnerIds
                ]);
                return false;
            }

            // Get current relations
            $currentRelations = DB::table('user_regional_partner')
                ->where('user', $user->id)
                ->pluck('regional_partner')
                ->toArray();

            // Get target relations
            $targetRelations = $regionalPartners->pluck('id')->toArray();

            // Remove relations that are no longer valid
            $toRemove = array_diff($currentRelations, $targetRelations);
            if (!empty($toRemove)) {
                DB::table('user_regional_partner')
                    ->where('user', $user->id)
                    ->whereIn('regional_partner', $toRemove)
                    ->delete();
                Log::info("Removed regional partner relations", [
                    'user_id' => $user->id,
                    'removed' => $toRemove
                ]);
            }

            // Add new relations
            $toAdd = array_diff($targetRelations, $currentRelations);
            if (!empty($toAdd)) {
                $insertData = array_map(function ($rpId) use ($user) {
                    return [
                        'user' => $user->id,
                        'regional_partner' => $rpId
                    ];
                }, $toAdd);

                DB::table('user_regional_partner')->insert($insertData);
                Log::info("Added regional partner relations", [
                    'user_id' => $user->id,
                    'added' => $toAdd
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
     * @param int $drahtEventId The DRAHT event ID (event_explore or event_challenge)
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
