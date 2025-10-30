<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\FirstProgram;
use App\Models\MRoomType;
use App\Models\MRoomTypeGroup;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class RoomController extends Controller
{
    public function index(Event $event)
    {
        // Räume inkl. normaler Typen laden
        $rooms = Room::where('event', $event->id)
            ->with('roomTypes')
            ->orderBy('sequence')
            ->orderBy('name') // Fallback ordering
            ->get();

        // Plan-ID zum Event holen
        $plan = DB::table('plan')->where('event', $event->id)->value('id');

        if ($plan) {
            // Extra-Blocks gruppiert nach room_id laden
            $extraBlocksByRoom = DB::table('extra_block')
                ->where('plan', $plan)
                ->select('id', 'name', 'room', 'first_program')
                ->whereNotNull('room')
                ->get()
                ->groupBy('room');
            
            // Log::debug('Extra blocks by room', $extraBlocksByRoom->toArray());

            } else {

            // log('No plan found for event '.$event->id);
            $extraBlocksByRoom = collect();
        }

        // Räume erweitern um zugehörige extra_blocks
        $rooms->transform(function ($room) use ($extraBlocksByRoom) {
            $room->extra_blocks = $extraBlocksByRoom->get($room->id, collect())->values();
            return $room;
        });

        // Ensure roomTypes relationship is loaded and accessible
        $rooms->load('roomTypes');

        // log::alert('Rooms with extra blocks', $rooms->toArray());

        return response()->json([
            'rooms' => $rooms,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'event' => 'required|exists:event,id',
            'navigation_instruction' => 'nullable|string|max:255',
            'is_accessible' => 'boolean',
        ]);

        // Get next sequence number for this event
        $nextSequence = $this->getNextRoomSequence($validated['event']);

        $room = Room::create([
            'name' => $validated['name'],
            'event' => $validated['event'],
            'navigation_instruction' => $validated['navigation_instruction'],
            'sequence' => $nextSequence,
            'is_accessible' => $validated['is_accessible'] ?? true,
        ]);

        return response()->json($room, 201);
    }

    public function update(Request $request, Room $room)
    {
        $room->update($request->only(['name', 'navigation_instruction', 'is_accessible']));
        return response()->json($room);
    }

    public function destroy(Room $room)
    {
        $room->delete();
        return response()->json();
    }

    public function assignRoomType(Request $request, Room $room)
    {
        $validated = $request->validate([
            'type_id' => 'required|integer',
            'room_id' => 'nullable|exists:room,id',
            'event' => 'nullable|exists:event,id',
            'extra_block' => 'required|boolean', // 🔹 NEU
        ]);

        // Log::debug('Assign request', $validated);

        if (!$validated['extra_block']) {
            // 🔹 Normaler Raum-Typ → Beziehung in Pivot-Tabelle
            $type = \App\Models\MRoomType::findOrFail($validated['type_id']);

            DB::table('room_type_room')
                ->where('room_type', $validated['type_id'])
                ->where('event', $validated['event'])
                ->delete();

            if ($validated['room_id']) {
                DB::table('room_type_room')->insert([
                    'room_type' => $type->id,
                    'room' => $validated['room_id'],
                    'event' => $validated['event'],
                ]);
            }

            // Log::info("Assigned normal room type {$type->id} to room {$validated['room_id']} (event {$validated['event']})");
        } 
        else {
            // 🔹 Extra Block → direktes Update in Tabelle `extra_block`
            $block = \App\Models\ExtraBlock::findOrFail($validated['type_id']);

            $block->room = $validated['room_id'] ?? null;
            $block->save();

            // Log::info("Assigned extra block {$block->id} to room {$validated['room_id']}");
        }

        return response()->json(['success' => true]);
    }

    public function assignTeam(Request $request)
    {
        $validated = $request->validate([
            'team_id' => 'required|integer|exists:team,id',
            'room_id' => 'nullable|integer|exists:room,id',
            'event' => 'required|integer|exists:event,id',
        ]);

        // Finde Plan für das Event
        $plan = \App\Models\Plan::where('event', $validated['event'])->firstOrFail();

        // Update direkt in team_plan (Eintrag muss existieren)
        DB::table('team_plan')
            ->where('team', $validated['team_id'])
            ->where('plan', $plan->id)
            ->update(['room' => $validated['room_id']]);

        return response()->json(['success' => true]);
    }

    /**
     * Update the sequence of rooms within an event (for drag-and-drop reordering)
     */
    public function updateRoomSequence(Request $request)
    {
        $validated = $request->validate([
            'rooms' => 'required|array',
            'rooms.*.room_id' => 'required|integer|exists:room,id',
            'rooms.*.sequence' => 'required|integer|min:1',
            'event_id' => 'required|integer|exists:event,id',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['rooms'] as $item) {
                DB::table('room')
                    ->where('id', $item['room_id'])
                    ->where('event', $validated['event_id'])
                    ->update(['sequence' => $item['sequence']]);
            }
        });

        return response()->json(['success' => true]);
    }

    /**
     * Get the next sequence number for a room in an event
     */
    private function getNextRoomSequence($eventId)
    {
        $maxSequence = DB::table('room')
            ->where('event', $eventId)
            ->max('sequence');
        
        return ($maxSequence ?? 0) + 1;
    }

}