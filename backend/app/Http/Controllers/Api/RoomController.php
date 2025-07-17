<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\FirstProgram;
use App\Models\MRoomType;
use App\Models\MRoomTypeGroup;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RoomController extends Controller
{
    public function index(Event $event)
    {
        $rooms = Room::where('event', $event->id)->with('roomTypes')->get();
        $programsByName = FirstProgram::all()->keyBy(fn($p) => strtolower(trim($p->name)));

        $roomTypes = MRoomType::with('group')
            ->where('level', '<=', $event->level)
            ->get()
            ->map(function ($type) use ($programsByName) {
                $group = $type->group;
                $groupName = strtolower(trim($group->name ?? ''));
                $matchedProgram = $programsByName->get($groupName);

                return [
                    'id' => $type->id,
                    'name' => $type->name,
                    'sequence' => $type->sequence,
                    'room_type_group' => $type->room_type_group,
                    'level' => $type->level,
                    'group' => [
                        'id' => $group->id ?? null,
                        'name' => $group->name ?? null,
                        'program' => $matchedProgram ? [
                            'id' => $matchedProgram->id,
                            'name' => $matchedProgram->name,
                            'color' => "#" . $matchedProgram->color_hex,
                        ] : null,
                    ]
                ];
            });

        $validGroupIds = $roomTypes->pluck('group.id')->unique();
        $groups = MRoomTypeGroup::whereIn('id', $validGroupIds)->get();

        return response()->json([
            'rooms' => $rooms,
            'roomTypes' => $roomTypes,
            'groups' => $groups
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'event' => 'required|exists:event,id',
            'navigation_instruction' => 'nullable|string|max:255',
        ]);

        $room = Room::create([
            'name' => $validated['name'],
            'event' => $validated['event'],
            'navigation_instruction' => $validated['navigation_instruction'],
        ]);

        return response()->json($room, 201);
    }

    public function update(Request $request, Room $room)
    {
        $room->update($request->only(['name', 'navigation_instruction']));
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
            'type_id' => 'required|exists:m_room_type,id',
            'room_id' => 'nullable|exists:room,id',
            'event' => 'nullable|exists:event,id',
        ]);
        Log::debug($validated);

        $type = MRoomType::findOrFail($validated['type_id']);

        \DB::table('room_type_room')
            ->where('room_type', $validated['type_id'])
            ->where('event', $validated['event'])
            ->delete();

        if ($validated['room_id']) {
            \DB::table('room_type_room')
                ->insert([
                    'room_type' => $type->id,
                    'room' => $validated['room_id'],
                    'event' => $validated['event'],
                ]);
        }

        return response()->json(['success' => true]);
    }
}
