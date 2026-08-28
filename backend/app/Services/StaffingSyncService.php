<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventStaffingGroup;
use App\Models\EventStaffingRole;
use App\Models\MRole;
use App\Models\MStaffingRule;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Materialize event staffing roles/groups from staffable m_role + m_staffing_rule
 * after plan generation. Snapshots min/best/max/ui_description onto event roles.
 */
class StaffingSyncService
{
    /**
     * @return array{roles: int, groups_created: int, groups_surplus: int, groups_collapsed: int, skipped: list<string>}
     */
    public function syncForEvent(int $eventId): array
    {
        $event = Event::find($eventId);
        if (! $event) {
            throw new \InvalidArgumentException("Event {$eventId} not found");
        }

        $planId = (int) (DB::table('plan')->where('event', $eventId)->value('id') ?? 0);
        if ($planId < 1) {
            return [
                'roles' => 0,
                'groups_created' => 0,
                'groups_surplus' => 0,
                'groups_collapsed' => 0,
                'skipped' => ['no_plan'],
            ];
        }

        $params = PlanParameter::load($planId);
        $presence = ProgramPresence::forPlan($planId, $params);
        $programIds = $this->activeProgramIds($presence);

        $stats = [
            'roles' => 0,
            'groups_created' => 0,
            'groups_surplus' => 0,
            'groups_collapsed' => 0,
            'skipped' => [],
        ];

        $catalogRoles = MRole::query()
            ->where('staffable', true)
            ->where(function ($q) use ($programIds) {
                $q->whereNull('first_program');
                if ($programIds !== []) {
                    $q->orWhereIn('first_program', $programIds);
                }
            })
            ->orderByRaw('(first_program is null) asc')
            ->orderBy('first_program')
            ->orderBy('sequence')
            ->get();

        $activeRoleIds = [];

        foreach ($catalogRoles as $role) {
            $rule = MStaffingRule::query()->find($role->id);
            if (! $rule) {
                $msg = "staffable role {$role->id} ({$role->name}) has no m_staffing_rule";
                Log::warning('[staffing-sync] '.$msg);
                $stats['skipped'][] = $msg;
                continue;
            }

            if ($rule->min > $rule->best || $rule->best > $rule->max || $rule->min < 0) {
                $msg = "invalid min/best/max on rule for role {$role->id}";
                Log::warning('[staffing-sync] '.$msg);
                $stats['skipped'][] = $msg;
                continue;
            }

            $programOn = $role->first_program === null
                || in_array((int) $role->first_program, $programIds, true);

            $expectedGroups = $programOn ? $this->expectedGroupCount($role, $planId) : 0;

            $eventRole = EventStaffingRole::query()->updateOrCreate(
                [
                    'event' => $eventId,
                    'm_role' => $role->id,
                ],
                [
                    'label' => $role->name,
                    'min' => $rule->min,
                    'best' => $rule->best,
                    'max' => $rule->max,
                    'ui_description' => $rule->ui_description,
                    'sequence' => (int) $role->sequence,
                ]
            );

            $activeRoleIds[] = $eventRole->id;
            $stats['roles']++;

            $groupStats = $this->syncGroups($eventRole, $expectedGroups);
            $stats['groups_created'] += $groupStats['created'];
            $stats['groups_surplus'] += $groupStats['surplus'];
            $stats['groups_collapsed'] += $groupStats['collapsed'];
        }

        // Catalog roles that no longer apply (program off / no longer staffable): surplus all groups
        $obsolete = EventStaffingRole::query()
            ->where('event', $eventId)
            ->whereNotNull('m_role')
            ->when($activeRoleIds !== [], fn ($q) => $q->whereNotIn('id', $activeRoleIds))
            ->when($activeRoleIds === [], fn ($q) => $q)
            ->get();

        foreach ($obsolete as $eventRole) {
            $groupStats = $this->syncGroups($eventRole, 0);
            $stats['groups_surplus'] += $groupStats['surplus'];
            $stats['groups_collapsed'] += $groupStats['collapsed'];
        }

        return $stats;
    }

    /**
     * Assigned people and min gaps per staffing scope (cross, each attached program, local).
     *
     * @param  list<int>  $programIds  attached event first_program ids
     * @return list<array{key: string, assigned: int, missing_min: int, roles: int}>
     */
    public function summaryByScope(int $eventId, array $programIds): array
    {
        $buckets = [
            'cross' => ['assigned' => 0, 'missing_min' => 0, 'roles' => 0],
            'local' => ['assigned' => 0, 'missing_min' => 0, 'roles' => 0],
        ];
        foreach ($programIds as $programId) {
            $buckets['program:'.$programId] = ['assigned' => 0, 'missing_min' => 0, 'roles' => 0];
        }

        $roles = EventStaffingRole::query()
            ->where('event', $eventId)
            ->with(['catalogRole', 'groups.assignments'])
            ->get();

        foreach ($roles as $role) {
            $scopeKey = $this->scopeKeyForRole($role);
            if (! isset($buckets[$scopeKey])) {
                continue;
            }

            $buckets[$scopeKey]['roles']++;

            $min = (int) $role->min;
            foreach ($role->groups as $group) {
                $filled = $group->assignments->count();
                $buckets[$scopeKey]['assigned'] += $filled;

                if (! $group->surplus && $filled < $min) {
                    $buckets[$scopeKey]['missing_min'] += $min - $filled;
                }
            }
        }

        $result = [
            ['key' => 'cross', ...$buckets['cross']],
        ];

        $sortedPrograms = $programIds;
        sort($sortedPrograms);
        foreach ($sortedPrograms as $programId) {
            $key = 'program:'.$programId;
            $result[] = ['key' => $key, ...$buckets[$key]];
        }

        $result[] = ['key' => 'local', ...$buckets['local']];

        return $result;
    }

    /**
     * Nav red-dot: surplus group with people, or non-surplus group below min (catalog + local).
     */
    public function staffingOk(int $eventId): bool
    {
        $roles = EventStaffingRole::query()
            ->where('event', $eventId)
            ->with(['groups.assignments'])
            ->get();

        if ($roles->isEmpty()) {
            return true;
        }

        foreach ($roles as $role) {
            foreach ($role->groups as $group) {
                $count = $group->assignments->count();
                if ($group->surplus) {
                    if ($count > 0) {
                        return false;
                    }

                    continue;
                }
                if ($count < (int) $role->min) {
                    return false;
                }
            }
        }

        return true;
    }

    private function scopeKeyForRole(EventStaffingRole $role): string
    {
        if ($role->isLocal()) {
            return 'local';
        }

        $firstProgram = $role->catalogRole?->first_program;
        if ($firstProgram === null) {
            return 'cross';
        }

        return 'program:'.(int) $firstProgram;
    }

    /**
     * @return list<int>
     */
    private function activeProgramIds(ProgramPresence $presence): array
    {
        $ids = [];
        foreach ([2, 3, 8] as $id) {
            if ($presence->programOn($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function expectedGroupCount(MRole $role, int $planId): int
    {
        if ($role->differentiation_type === 'number' && $role->differentiation_source) {
            return max(0, $this->runDifferentiationCount($role->differentiation_source, $planId));
        }

        // Singular staffable role
        return 1;
    }

    private function runDifferentiationCount(string $source, int $planId): int
    {
        $sql = str_replace('[plan]', (string) $planId, $source);
        try {
            $row = DB::selectOne($sql);
        } catch (\Throwable $e) {
            Log::error('[staffing-sync] differentiation SQL failed: '.$e->getMessage(), [
                'sql' => $sql,
            ]);

            return 0;
        }

        if (! $row) {
            return 0;
        }
        $values = array_values((array) $row);

        return (int) ($values[0] ?? 0);
    }

    /**
     * @return array{created: int, surplus: int, collapsed: int}
     */
    private function syncGroups(EventStaffingRole $eventRole, int $expectedGroups): array
    {
        $created = 0;
        $surplusMarked = 0;
        $collapsed = 0;

        $existing = EventStaffingGroup::query()
            ->where('event_staffing_role', $eventRole->id)
            ->withCount('assignments')
            ->get()
            ->keyBy('group_index');

        for ($i = 1; $i <= $expectedGroups; $i++) {
            if (! $existing->has($i)) {
                EventStaffingGroup::create([
                    'event_staffing_role' => $eventRole->id,
                    'group_index' => $i,
                    'surplus' => false,
                ]);
                $created++;
            } else {
                $group = $existing->get($i);
                if ($group->surplus) {
                    $group->surplus = false;
                    $group->save();
                }
            }
        }

        foreach ($existing as $index => $group) {
            if ($index <= $expectedGroups) {
                continue;
            }
            // Surplus: no longer needed
            if ((int) $group->assignments_count === 0) {
                $group->delete();
                $collapsed++;
                continue;
            }
            if (! $group->surplus) {
                $group->surplus = true;
                $group->save();
                $surplusMarked++;
            } else {
                $surplusMarked++;
            }
        }

        return [
            'created' => $created,
            'surplus' => $surplusMarked,
            'collapsed' => $collapsed,
        ];
    }
}
