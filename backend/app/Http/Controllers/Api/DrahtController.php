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
        // Use simulator in test environments
        if (app()->environment('local', 'staging')) {
            return $this->makeSimulatedCall($route);
        }

        $headers = ['DOLAPIKEY' => config('services.draht_api.key')];
        return Http::withHeaders($headers)->get(config('services.draht_api.base_url') . $route);
    }

    /**
     * Make a POST request to DRAHT API
     */
    public function makeDrahtPostCall($route, array $data)
    {
        // Use simulator in test environments
        if (app()->environment('local', 'staging')) {
            // For POST requests in simulator, we'll just log it
            Log::info('DRAHT POST call (simulated)', [
                'route' => $route,
                'data' => $data
            ]);
            // Return a mock successful response
            return new class {
                public function ok()
                {
                    return true;
                }

                public function status()
                {
                    return 200;
                }

                public function json()
                {
                    return ['success' => true];
                }

                public function body()
                {
                    return json_encode(['success' => true]);
                }
            };
        }

        $headers = ['DOLAPIKEY' => config('services.draht_api.key')];
        return Http::withHeaders($headers)
            ->post(config('services.draht_api.base_url') . $route, $data);
    }

    /**
     * Make simulated Draht API calls for test environments
     */
    private function makeSimulatedCall($route)
    {
        $simulator = new DrahtSimulatorController();

        // Create a mock request with the route
        $mockRequest = new \Illuminate\Http\Request();
        $mockRequest->setMethod('GET');

        // Extract the path from the route (remove leading slash)
        $path = ltrim($route, '/');

        // Call the simulator
        $response = $simulator->handle($mockRequest, $path);

        // Create a mock HTTP response that behaves like the real one
        return new class($response) {
            private $response;

            public function __construct($response)
            {
                $this->response = $response;
            }

            public function ok()
            {
                return $this->response->getStatusCode() >= 200 && $this->response->getStatusCode() < 300;
            }

            public function status()
            {
                return $this->response->getStatusCode();
            }

            public function json()
            {
                return json_decode($this->response->getContent(), true);
            }

            public function body()
            {
                return $this->response->getContent();
            }
        };
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
            $eventIds = Event::where('season', $seasonId)->pluck('id');

            if ($eventIds->isNotEmpty()) {
                DB::statement("SET foreign_key_checks=0");
                Team::whereIn('event', $eventIds)->delete();
                Event::whereIn('id', $eventIds)->delete();
                DB::statement("SET foreign_key_checks=1");
            }

            foreach ($eventsData as $eventData) {
                $date = (isset($eventData["date"]) && $eventData["date"] != "") ? $eventData["date"] : "1970-01-01";
                $enddate = (isset($eventData["enddate"]) && $eventData["enddate"] != "") ? $eventData["enddate"] : "1970-01-01";

                $regionalPartner = RegionalPartner::where('dolibarr_id', $eventData['region'])->first();
                $firstProgram = (int)$eventData['first_program'];

                $days = 1;

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

                if ($existingEvent) {
                    $updateData = [];

                    if ($firstProgram === FirstProgram::EXPLORE->value) {
                        $updateData['event_explore'] = $eventData['id'];
                    } elseif ($firstProgram === FirstProgram::CHALLENGE->value) {
                        $updateData['event_challenge'] = $eventData['id'];
                    }

                    if (empty($existingEvent->name) && !empty($eventData['name'])) {
                        $updateData['name'] = $eventData['name'];
                    }
                    if (empty($existingEvent->enddate) && $enddate) {
                        $updateData['days'] = $days;
                    }
                    if (empty($existingEvent->level) && isset($eventData['level']) && $eventData['level']) {
                        $updateData['level'] = $eventData['level'];
                    }

                    $existingEvent->update($updateData);
                    $event = $existingEvent;
                } else {
                    $eventAttributes = [
                        'name' => $eventData['name'] ?? null,
                        'date' => $date,
                        'enddate' => $enddate,
                        'season' => $seasonId,
                        'days' => $days,
                        'regional_partner' => $regionalPartner?->id,
                        'level' => $eventData['level'] ?? null,
                    ];
                    match ($firstProgram) {
                        2 => $eventAttributes['event_explore'] = $eventData['id'],
                        3 => $eventAttributes['event_challenge'] = $eventData['id'],
                        default => null
                    };

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

                if (isset($eventData['teams']) && is_array($eventData['teams'])) {
                    $teamsToCreate = collect($eventData['teams'])->map(function ($teamData) use ($event) {
                        return [
                            'event' => $event->id,
                            'name' => $teamData['name'],
                            'team_number_hot' => $teamData['team_number_hot'],
                            'first_program' => $teamData['first_program'],
                        ];
                    });

                    Team::insert($teamsToCreate->toArray());
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
