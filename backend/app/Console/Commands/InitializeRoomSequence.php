<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InitializeRoomSequence extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rooms:initialize-sequence';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize sequence values for room table based on alphabetical order';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Initializing room sequences...');

        // Get all rooms grouped by event
        $rooms = DB::table('room')
            ->select('id', 'name', 'event')
            ->orderBy('event')
            ->orderBy('name')
            ->get();

        $grouped = $rooms->groupBy('event');

        $updated = 0;
        foreach ($grouped as $eventId => $eventRooms) {
            $sequence = 1;
            foreach ($eventRooms as $room) {
                DB::table('room')
                    ->where('id', $room->id)
                    ->update(['sequence' => $sequence]);
                $sequence++;
                $updated++;
            }
        }

        $this->info("Updated {$updated} room sequences.");
        return 0;
    }
}
