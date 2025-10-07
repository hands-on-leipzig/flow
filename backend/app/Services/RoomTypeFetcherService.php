<?php


namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoomTypeFetcherService
{
    
    public function fetchRoomTypes(int $plan): array
{
    // --- Normale Room Types ---
    $normal = DB::table('activity as a')
        ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
        ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
        ->join('m_activity_type as at', 'at.id', '=', 'atd.activity_type')
        ->join('m_room_type as rt', 'a.room_type', '=', 'rt.id')
        ->where('ag.plan', $plan)
        ->select(
            'at.id as group_id',
            'at.name as group_name',
            'at.sequence as group_seq',
            'atd.id as type_id',
            'rt.name as type_name',
            'atd.sequence as type_seq'
        )
        ->distinct()
        ->orderBy('at.sequence')
        ->orderBy('atd.sequence')
        ->get();

    Log::info('room types normal', $normal->toArray());

    // --- Extra Blocks ---
    $extra = DB::table('activity as a')
        ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
        ->join('extra_block as eb', 'eb.id', '=', 'a.extra_block')
        ->where('ag.plan', $plan)
        ->select(
            DB::raw('999 as group_id'),
            DB::raw('"Extra Blocks" as group_name'),
            DB::raw('999 as group_seq'),
            'eb.id as type_id',
            'eb.name as type_name',
            DB::raw('0 as type_seq')
        )
        ->distinct()
        ->orderBy('eb.name')
        ->get();

    Log::info('room types extra', $extra->toArray());

    // --- Zusammenführen ---
    $merged = $normal->merge($extra);
    Log::info('room types merged', $merged->toArray());

    // --- Gruppieren für Frontend ---
    $grouped = $merged
        ->groupBy('group_id')
        ->sortBy(fn($items) => $items->first()->group_seq ?? 0)
        ->map(function ($items) {
            $first = $items->first();

            return [
                'id' => (int) $first->group_id,
                'name' => $first->group_name,
                'room_types' => $items
                    ->sortBy('type_seq')
                    ->map(fn($r) => [
                        'type_id' => (int) $r->type_id,
                        'type_name' => $r->type_name,
                    ])
                    ->values()
                    ->all(),
            ];
        })
        ->values()
        ->all();

    return $grouped;
}


}