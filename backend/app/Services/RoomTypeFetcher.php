<?php


namespace App\Services;

use Illuminate\Support\Facades\DB;

class RoomTypeFetcher
{
    
    public function fetchRoomTypes(int $plan): \Illuminate\Support\Collection
    {
        // Normale room_types
        $roomTypes = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('plan as p', 'p.id', '=', 'ag.plan')
            ->join('m_room_type as rt', 'a.room_type', '=', 'rt.id')
            ->join('m_room_type_group as rg', 'rt.room_type_group', '=', 'rg.id')
            ->where('ag.plan', $plan)
            ->select(
                'rg.id as group_id',
                'rg.name as group_name',
                'rt.id as type_id',
                'rt.name as type_name'
            );

        // Sonderfall Extra Block (at.id = 9)
        $extraBlocks = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('plan as p', 'p.id', '=', 'ag.plan')
            ->join('extra_block as eb', 'a.extra_block', '=', 'eb.id')
            ->where('ag.plan', $plan)
            ->where('a.activity_type', 9)
            ->select(
                DB::raw('1 as group_id'),
                DB::raw('"Extra Blocks" as group_name'),
                'eb.id as type_id',
                'eb.name as type_name'
            );

        // Zusammenführen und gruppieren
        $all = $roomTypes->union($extraBlocks)->get();

        return $all
            ->groupBy('group_id')
            ->map(function ($items) {
                $first = $items->first();
                return [
                    'group_id'   => $first->group_id,
                    'group_name' => $first->group_name,
                    'room_types' => $items->map(fn($i) => [
                        'id'   => $i->type_id,
                        'name' => $i->type_name,
                    ])->unique('id')->values(),
                ];
            })
            ->values();
    }


}