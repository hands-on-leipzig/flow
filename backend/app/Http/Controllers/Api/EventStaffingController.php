<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventStaffingRole;
use App\Services\StaffingSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EventStaffingController extends Controller
{
    public function __construct(
        private StaffingSyncService $sync,
    ) {}

    public function index(Event $event): JsonResponse
    {
        $planId = (int) (DB::table('plan')->where('event', $event->id)->value('id') ?? 0);
        $event->loadMissing('programs');
        $programIds = $event->programs
            ->pluck('first_program')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $roles = EventStaffingRole::query()
            ->where('event', $event->id)
            ->with([
                'catalogRole',
                'groups' => fn ($q) => $q->orderBy('group_index'),
                'groups.assignments.person',
            ])
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $payload = $roles->map(function (EventStaffingRole $role) {
            $groups = $role->groups->map(function ($group) use ($role) {
                $people = $group->assignments
                    ->map(fn ($a) => $a->person)
                    ->filter()
                    ->sortBy([
                        fn ($p) => mb_strtolower($p->last_name),
                        fn ($p) => mb_strtolower($p->first_name),
                    ])
                    ->values()
                    ->map(fn ($p) => [
                        'id' => $p->id,
                        'first_name' => $p->first_name,
                        'last_name' => $p->last_name,
                        'nickname' => $p->nickname,
                        'email' => $p->email,
                    ]);

                $filled = $people->count();

                return [
                    'id' => $group->id,
                    'group_index' => $group->group_index,
                    'surplus' => (bool) $group->surplus,
                    'filled' => $filled,
                    'min' => $role->min,
                    'best' => $role->best,
                    'max' => $role->max,
                    'under_min' => ! $group->surplus && $filled < $role->min,
                    'people' => $people,
                ];
            });

            return [
                'id' => $role->id,
                'm_role' => $role->m_role,
                'is_local' => $role->isLocal(),
                'label' => $role->label ?: ($role->catalogRole?->name ?? 'Rolle'),
                'first_program' => $role->catalogRole?->first_program,
                'min' => $role->min,
                'best' => $role->best,
                'max' => $role->max,
                'ui_description' => $role->ui_description,
                'sequence' => $role->sequence,
                'groups' => $groups,
            ];
        });

        return response()->json([
            'plan_id' => $planId ?: null,
            'roles' => $payload,
            'open_positions' => $this->sync->openPositionsByScope($event->id, $programIds),
        ]);
    }

    public function sync(Event $event): JsonResponse
    {
        $stats = $this->sync->syncForEvent($event->id);

        return response()->json([
            'ok' => true,
            'stats' => $stats,
        ]);
    }
}
