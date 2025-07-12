<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Season;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EventController extends Controller
{
    public function getEvent($id)
    {
        $event = Event::findOrFail($id);
        return response()->json($event);
    }

    public function getSelectableEvents()
    {
        $user = Auth::user();
        $season = Season::latest('year')->first();
        $regionalPartners = $user->regionalPartners()->with(['events' => function ($query) use ($season) {
            $query->where('season', $season->id)->orderBy('date');
        }])->get();
        return $regionalPartners->map(function ($rp) {
            return [
                'regional_partner' => [
                    'id' => $rp->id,
                    'name' => $rp->name,
                    'region' => $rp->region,
                ],
                'events' => $rp->events->map(function ($event) {
                    return [
                        'id' => $event->id,
                        'name' => $event->name,
                        'date' => $event->date,
                        'slug' => $event->slug,
                        'season' => [
                            'id' => $event->season,
                            'name' => optional($event->seasonRel)->name,
                        ],
                    ];
                }),
            ];
        });
    }

}
