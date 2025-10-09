<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Services\RoomTypeFetcherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.id as event_id', 'event.date as event_date')
            ->first();

        if (!$event) {
            return response()->json([]);
        }

        $roomTypes = collect($this->fetcher->fetchRoomTypes($planId));

        $mappedNormal = DB::table('room_type_room')
            ->where('event', $event->event_id)
            ->pluck('room_type')
            ->toArray();

        $mappedExtras = DB::table('extra_block')
            ->where('plan', $planId)
            ->where(function ($q) use ($event) {
                $q->whereNotNull('room')
                ->orWhereDate('start', '<', $event->event_date);
            })
            ->pluck('id')
            ->toArray();

        // Log::info('Mapped Normal Room Types for plan '.$planId.': '.json_encode($mappedNormal));

        $unmapped = $roomTypes->map(function ($group) use ($mappedNormal, $mappedExtras) {
            $isExtraGroup = ($group['id'] ?? 0) === 999;

            $filtered = collect($group['room_types'])->filter(function ($rt) use ($isExtraGroup, $mappedNormal, $mappedExtras) {
                if ($isExtraGroup) {
                    // Extra Blocks → ID steht in type_id
                    return !in_array($rt['type_id'], $mappedExtras);
                } else {
                    // Normale Room Types
                    return !in_array($rt['type_id'], $mappedNormal);
                }
            })->values()->all();

            return [
                'id' => $group['id'],
                'name' => $group['name'],
                'room_types' => $filtered,
            ];
        })->filter(fn($g) => count($g['room_types']) > 0)
        ->values()
        ->all();

        // Log::info('Unmapped Room Types for plan '.$planId.': '.json_encode($unmapped));

        return response()->json($unmapped);
    }  

}