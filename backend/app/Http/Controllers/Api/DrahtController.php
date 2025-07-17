<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Models\MSeason;
use App\Models\RegionalPartner;
use App\Models\Team;
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
        ];


        if ($event->event_challenge) {
            $res = $this->makeDrahtCall("/handson/events/{$event->event_challenge}/scheduledata");
            if ($res->ok()) {
                $challenge = $res->json();
                $mergedData['event_challenge'] = $challenge;
                $mergedData['address'] = $challenge['address'] ?? null;
                $mergedData['contact'] = @unserialize($challenge['contact'] ?? null);
                $mergedData['information'] = $challenge['information'] ?? null;
                $mergedData['teams_challenge'] = $challenge['teams'] ?? [];
            }
        }

        if ($event->event_explore) {
            $res = $this->makeDrahtCall("/handson/events/{$event->event_explore}/scheduledata");
            if ($res->ok()) {
                $explore = $res->json();
                $mergedData['event_explore'] = $explore;

                // overwrite shared fields only if not already set
                $mergedData['address'] ??= $explore['address'] ?? null;
                $mergedData['contact'] ??= @unserialize($explore['contact'] ?? null);
                $mergedData['information'] ??= $explore['information'] ?? null;

                $mergedData['teams_explore'] = $explore['teams'] ?? [];
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
        $res = $this->makeDrahtCall("/handson/flow/events");
        if ($res->ok()) {
            $events = $res->json();
            DB::transaction(function () use ($seasonId, $events) {
                $eventIds = Event::where('season', $seasonId)->pluck('id');
                DB::statement("SET foreign_key_checks=0");
                Event::whereIn('id', $eventIds)->delete();
                $teamsToDelete = Team::whereIn('event', $eventIds)->pluck('id');
                Team::whereIn('event', $teamsToDelete)->delete();
                DB::statement("SET foreign_key_checks=1");
                foreach ($events as $eventData) {
                    $regional_partner = RegionalPartner::where("dolibarr_id", $eventData["region"])->first();
                    $event = new Event();
                    $event->name = $eventData['name'];
                    $event->date = date('Y-m-d', (int)$eventData['date']);
                    $event->enddate = date('Y-m-d', (int)$eventData['enddate']);
                    $event->season = $seasonId;
                    $event->days = (new \DateTime)->setTimestamp((int)$eventData['date'])->diff((new \DateTime)->setTimestamp((int)$eventData['enddate']))->days + 1;
                    $event->regional_partner = $regional_partner->id;
                    $event->level = $eventData["level"];
                    switch ((int)$eventData["first_program"]) {
                        case 2:
                            $event->event_explore = $eventData["id"];
                            break;
                        case 3:
                            $event->event_challenge = $eventData["id"];
                            break;
                    }
                    $event->save();

                    if (!array_key_exists('teams', $eventData)) continue;

                    foreach ($eventData['teams'] as $teamData) {
                        Team::create([
                            'event' => $event->id,
                            'name' => $teamData['name'],
                            'team_number_hot' => $teamData['team_number_hot'],
                            'first_program' => $teamData['first_program'],
                        ]);
                    }
                }
            });
        }
        return response()->json(['status' => 200]);
    }
}
