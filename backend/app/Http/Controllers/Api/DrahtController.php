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
            
            public function __construct($response) {
                $this->response = $response;
            }
            
            public function ok() {
                return $this->response->getStatusCode() >= 200 && $this->response->getStatusCode() < 300;
            }
            
            public function status() {
                return $this->response->getStatusCode();
            }
            
            public function json() {
                return json_decode($this->response->getContent(), true);
            }
            
            public function body() {
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
        $res = $this->makeDrahtCall("/handson/rp");
        if ($res->ok()) {
            $regions = $res->json();
            DB::statement("SET foreign_key_checks=0");
            RegionalPartner::truncate();
            DB::statement("SET foreign_key_checks=1");
            foreach ($regions as $r) {
                $region = new RegionalPartner();
                $region->name = $r['name'];
                $region->dolibarr_id = $r['id'];
                $region->region = $r['name'];
                $region->save();
            }

            return response()->json(['status' => 200]);
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
                $regionalPartner = RegionalPartner::where('dolibarr_id', $eventData['region'])->first();
                $firstProgram = (int)$eventData['first_program'];

                $days = 1;
                if ($eventData['date'] && $eventData['enddate']) {
                    $startDate = (new \DateTime)->setTimestamp((int)$eventData['date']);
                    $endDate = (new \DateTime)->setTimestamp((int)$eventData['enddate']);
                    $days = $startDate->diff($endDate)->days + 1;
                }

                $existingEvent = Event::where('regional_partner', $regionalPartner?->id)
                    ->where('date', date('Y-m-d', (int)$eventData['date']))
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
                    if (empty($existingEvent->enddate) && $eventData['enddate']) {
                        $updateData['enddate'] = date('Y-m-d', (int)$eventData['enddate']);
                        $updateData['days'] = $days;
                    }
                    if (empty($existingEvent->level) && $eventData['level']) {
                        $updateData['level'] = $eventData['level'];
                    }

                    $existingEvent->update($updateData);
                    $event = $existingEvent;
                } else {
                    $eventAttributes = [
                        'name' => $eventData['name'],
                        'date' => date('Y-m-d', (int)$eventData['date']),
                        'enddate' => date('Y-m-d', (int)$eventData['enddate']),
                        'season' => $seasonId,
                        'days' => $days,
                        'regional_partner' => $regionalPartner?->id,
                        'level' => $eventData['level'],
                    ];
                    match ($firstProgram) {
                        2 => $eventAttributes['event_explore'] = $eventData['id'],
                        3 => $eventAttributes['event_challenge'] = $eventData['id'],
                        default => null
                    };

                    $event = Event::create($eventAttributes);
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
