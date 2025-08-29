<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MSeason;
use App\Models\RegionalPartner;
use App\Models\TableEvent; 
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class EventController extends Controller
{
    public function getEvent($id)
    {
        $event = Event::with(['seasonRel', 'levelRel', 'tableNames'])->findOrFail($id);
        $event->wifi_password = isset($event->wifi_password) ? Crypt::decryptString($event->wifi_password) : "";

        return response()->json($event);
    }

    public function getSelectableEvents()
    {
        $user = Auth::user();
        $season = MSeason::latest('year')->first();

        switch ($user->is_admin) {
            case 0:
                $regionalPartners = $user->regionalPartners()
                    ->with(['events' => function ($query) use ($season) {
                        $query->where('season', $season->id)
                            ->orderBy('date')
                            ->with(['seasonRel', 'levelRel']);
                    }])
                    ->get();
                break;
            case 1:
                $regionalPartners = RegionalPartner::all()->sortBy('name');
                break;
        }

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
                            'id' => $event->seasonRel?->id,
                            'name' => $event->seasonRel?->name,
                            'year' => $event->seasonRel?->year,
                        ],
                        'level' => [
                            'id' => $event->levelRel?->id,
                            'name' => $event->levelRel?->name,
                        ],
                    ];
                }),
            ];
        });
    }

    public function update(Request $request, Event $event)
    {
        $updatableFields = [
            'wifi_ssid',
            'wifi_password',
        ];

        $data = $request->only($updatableFields);

        if (!empty($data['wifi_password'])) {
            $data['wifi_password'] = Crypt::encryptString($data['wifi_password']);
        }

        $event->update($data);

        return response()->json(['success' => true]);
    }





    public function getTableNames($event)
    {

        Log::info($event);

        $tables = TableEvent::where('event', $event)
            ->orderBy('table_number')
            ->get(['table_number', 'table_name']);

        return response()->json([
            'table_names' => $tables,
        ]);
    }

    public function updateTableNames(Request $request, $event)
    {
        Log::info("Payload empfangen für Event $event", ['payload' => $request->all()]);

        $tables = $request->input('table_names'); 

        if (!is_array($tables)) {
            return response()->json(['error' => 'Ungültiges Format'], 422);
        }

        DB::transaction(function () use ($tables, $event) {
            
            TableEvent::where('event', $event)->delete();

            
            foreach ($tables as $entry) {
                if (!isset($entry['table_number']) || !isset($entry['table_name'])) {
                    continue;
                }

                TableEvent::create([
                    'event' => $event,
                    'table_number' => (int)$entry['table_number'],
                    'table_name' => $entry['table_name'],
                ]);
            }
        });

        log::info("Table names updated successfully for event ID: $event");

        return response()->json(['success' => true]);
    }

}
