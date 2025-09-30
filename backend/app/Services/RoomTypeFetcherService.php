<?php


namespace App\Services;

use Illuminate\Support\Facades\DB;

class RoomTypeFetcherService
{
    
    public function fetchRoomTypes(int $plan): \Illuminate\Support\Collection
    {
        // --- Normale Room Types ---
        $normal = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->join('m_activity_type as at', 'at.id', '=', 'atd.activity_type')
            ->join('m_room_type as rt', 'a.room_type', '=', 'rt.id')
            ->where('ag.plan', $plan)
            ->select(
                'at.id   as group_id',
                'at.name as group_name',
                'at.sequence as group_seq',
                'atd.id  as type_id',
                'rt.name as type_name',
                'atd.sequence as type_seq'
            )
            ->distinct()
            ->orderBy('at.sequence')
            ->orderBy('atd.sequence')
            ->get();

        // --- Sonderfall: Extra Blocks ---
        $extra = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->join('m_activity_type as at', 'at.id', '=', 'atd.activity_type')
            ->join('extra_block as eb', 'eb.id', '=', 'a.extra_block')
            ->where('ag.plan', $plan)
            ->where('at.id', 9) // activity_type = 9
            ->select(
                DB::raw('1 as group_id'),
                DB::raw('"Extra Blocks" as group_name'),
                DB::raw('999 as group_seq'), // kommt nach allen anderen
                'eb.id as type_id',
                'eb.name as type_name',
                DB::raw('0 as type_seq')
            )
            ->distinct()
            ->orderBy('eb.name')
            ->get();

        // --- Merge und strukturieren ---
        $all = $normal->merge($extra)
            ->groupBy('group_id')
            ->sortBy(fn($grp) => $grp->first()->group_seq)
            ->map(function ($items) {
                $first = $items->first();
                return [
                    'group_id'   => $first->group_id,
                    'group_name' => $first->group_name,
                    'room_types' => $items->sortBy('type_seq')
                                        ->map(fn($i) => [
                                            'type_id'   => $i->type_id,
                                            'type_name' => $i->type_name,
                                        ])
                                        ->values()
                                        ->all(),
                ];
            })
            ->values();

        return $all;
    }


}