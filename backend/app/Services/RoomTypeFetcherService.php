<?php


namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoomTypeFetcherService
{
    
    public function fetchRoomTypes(int $plan): array
    {
        // --- Normale Room Types ---
        $normal = DB::table('activity_group as ag')
        ->join('activity as a', 'a.activity_group', '=', 'ag.id')
        ->join('m_room_type as rt', 'a.room_type', '=', 'rt.id')
        ->leftJoin('m_room_type_group as rg', 'rt.room_type_group', '=', 'rg.id')
        ->where('ag.plan', $plan)
        ->select(
            'rg.id   as group_id',
            'rg.name as group_name',
            'rg.sequence as group_seq',
            'rt.id   as type_id',
            'rt.name as type_name',
            'rt.sequence as type_seq',
            'rt.first_program',
            DB::raw("'room_type' as item_type")
        )
        ->distinct()
        ->orderBy('rt.sequence')
        ->get();

        // Log::info('room types normal', $normal->toArray());

        // --- Extra Blocks ---
        $extra = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('extra_block as eb', 'eb.id', '=', 'a.extra_block')
            ->where('ag.plan', $plan)
            ->select(
                DB::raw('999 as group_id'),
                DB::raw('"Zusätzliche Blöcke" as group_name'),
                DB::raw('999 as group_seq'),
                'eb.id as type_id',
                'eb.name as type_name',
                DB::raw('0 as type_seq'),
                'eb.first_program',
                DB::raw("'extra_block' as item_type")
            )
            ->distinct()
            ->orderBy('eb.name')
            ->get();

        // Log::info('room types extra', $extra->toArray());

        // --- Zusammenführen ---
        $merged = $normal->merge($extra);
        // Log::info('room types merged', $merged->toArray());

        // --- Gruppieren für Frontend ---
        $grouped = $merged
            ->groupBy('group_id')
            ->sortBy(function ($items) {
                $first = $items->first();
                $seq = $first->group_seq ?? null;
                return $seq === null ? PHP_INT_MAX : (int) $seq;
            })
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
                            'first_program' => (int) ($r->first_program ?? 0),
                            'item_type' => $r->item_type ?? 'room_type', // 'room_type' or 'extra_block'
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