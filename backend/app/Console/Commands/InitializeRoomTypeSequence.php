<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InitializeRoomTypeSequence extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'room-types:initialize-sequence';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize sequence values for room_type_room table based on alphabetical order';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Initializing room type sequences...');

        // Get all room_type_room entries grouped by room and event
        $entries = DB::table('room_type_room')
            ->join('m_room_type', 'room_type_room.room_type', '=', 'm_room_type.id')
            ->select('room_type_room.*', 'm_room_type.name as room_type_name')
            ->orderBy('room_type_room.room')
            ->orderBy('room_type_room.event')
            ->orderBy('m_room_type.name')
            ->get();

        $grouped = $entries->groupBy(function ($item) {
            return $item->room . '_' . $item->event;
        });

        $updated = 0;
        foreach ($grouped as $group) {
            $sequence = 1;
            foreach ($group as $entry) {
                DB::table('room_type_room')
                    ->where('id', $entry->id)
                    ->update(['sequence' => $sequence]);
                $sequence++;
                $updated++;
            }
        }

        $this->info("Updated {$updated} room type sequences.");
        return 0;
    }
}
