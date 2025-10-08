<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Services\RoomTypeFetcherService;
use Illuminate\Support\Facades\DB;

class PlanRoomTypeController extends Controller
{
    protected RoomTypeFetcherService $fetcher;

    public function __construct(RoomTypeFetcherService $fetcher)
    {
        $this->fetcher = $fetcher;
    }

    /**
     * Liste aller Room Types für einen Plan (inkl. Extra Blocks)
     */
    public function listRoomTypes(int $planId): JsonResponse
    {
        $roomTypes = $this->fetcher->fetchRoomTypes($planId);
        return response()->json($roomTypes);
    }

    /**
     * Liste aller Room Types, die noch keinem Raum zugeordnet sind
     */
    public function unmappedRoomTypes(int $planId): JsonResponse
    {
        // 1️⃣ Plan-Event bestimmen
        $eventId = DB::table('plan')->where('id', $planId)->value('event');
        if (!$eventId) {
            return response()->json([]);
        }

        // 2️⃣ Alle Room Types laden (wie im UI)
        $roomTypes = collect($this->fetcher->fetchRoomTypes($planId));

        // 3️⃣ Gemappte Room Types aus room_type_room holen
        $mappedNormal = DB::table('room_type_room')
            ->where('event', $eventId)
            ->pluck('room_type')
            ->toArray();

        // 4️⃣ Extra Blocks mit gesetztem Raum (werden als gemappt betrachtet)
        $mappedExtras = DB::table('extra_block')
            ->where('plan', $planId)
            ->whereNotNull('room')
            ->pluck('id')
            ->toArray();

        // 5️⃣ Filtern: nur ungemappte behalten
        $unmapped = $roomTypes->map(function ($group) use ($mappedNormal, $mappedExtras) {
            $filtered = collect($group['room_types'])->filter(function ($rt) use ($mappedNormal, $mappedExtras) {
                $isExtra = ($rt['id'] ?? 0) >= 900; // extra blocks haben künstliche ID 999
                if ($isExtra) {
                    return !in_array($rt['type_id'], $mappedExtras);
                } else {
                    return !in_array($rt['type_id'], $mappedNormal);
                }
            })->values()->all();

            return [
                'id' => $group['id'],
                'name' => $group['name'],
                'room_types' => $filtered
            ];
        })->filter(fn($g) => count($g['room_types']) > 0)
          ->values()
          ->all();

        return response()->json($unmapped);
    }    

}