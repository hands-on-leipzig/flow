<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventStaffingGroup;
use App\Models\EventStaffingRole;
use App\Models\MRole;
use App\Models\MStaffingRule;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use App\Support\RoleDifferentiation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Materialize event staffing roles/groups from staffable m_role + m_staffing_rule
 * after plan generation. Snapshots min/best/max/ui_description onto event roles.
 */
class StaffingSyncService
{
    public function __construct(
        private RoleFetcherService $roleFetcher,
    ) {}
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

        $onPlanIds = array_fill_keys(
            $this->roleFetcher->fetchRoles($planId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            true,
        );
        $catalogOwnerIds = array_fill_keys(
            DB::table('m_activity_type_detail')
                ->whereNotNull('role')
                ->pluck('role')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all(),
            true,
        );

        $activeRoleIds = [];

        foreach ($catalogRoles as $role) {
            $rule = MStaffingRule::query()->where('m_role', $role->id)->first();
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

            $onPlan = isset($onPlanIds[(int) $role->id]);
            $neverOwner = ! isset($catalogOwnerIds[(int) $role->id]);
            if (! $onPlan && ! ($neverOwner && $programOn)) {
                continue;
            }

            $grouped = $role->group_label !== null && $role->group_label !== '';
            $expectedGroups = ($programOn && $grouped)
                ? RoleDifferentiation::optionCount(
                    $role->first_program !== null ? (int) $role->first_program : null,
                    $role->differentiation_parameter,
                    $params,
                )
                : 0;

            $eventRole = EventStaffingRole::query()->updateOrCreate(
                [
                    'event' => $eventId,
                    'm_role' => $role->id,
                ],
                [
                    'label' => $role->name,
                    'group_label' => $role->group_label,
                    'min' => $rule->min,
                    'best' => $rule->best,
                    'max' => $rule->max,
                    'ui_description' => $rule->ui_description,
                    'sequence' => (int) $role->sequence,
                    'surplus' => false,
                ]
            );

            $activeRoleIds[] = $eventRole->id;
            $stats['roles']++;

            $groupStats = $this->syncGroups($eventRole, $expectedGroups);
            $stats['groups_created'] += $groupStats['created'];
            $stats['groups_surplus'] += $groupStats['surplus'];
            $stats['groups_collapsed'] += $groupStats['collapsed'];
        }

        // Catalog roles that no longer apply (program off / no longer staffable)
        $obsolete = EventStaffingRole::query()
            ->where('event', $eventId)
            ->whereNotNull('m_role')
            ->when($activeRoleIds !== [], fn ($q) => $q->whereNotIn('id', $activeRoleIds))
            ->when($activeRoleIds === [], fn ($q) => $q)
            ->get();

        foreach ($obsolete as $eventRole) {
            if ($eventRole->isGrouped()) {
                $groupStats = $this->syncGroups($eventRole, 0);
                $stats['groups_surplus'] += $groupStats['surplus'];
                $stats['groups_collapsed'] += $groupStats['collapsed'];
            } else {
                if (! $eventRole->surplus) {
                    $eventRole->surplus = true;
                    $eventRole->save();
                }
            }
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
            ->with(['catalogRole', 'groups.assignments', 'assignments'])
            ->get();

        foreach ($roles as $role) {
            $scopeKey = $this->scopeKeyForRole($role);
            if (! isset($buckets[$scopeKey])) {
                continue;
            }

            $buckets[$scopeKey]['roles']++;

            $min = (int) $role->min;
            if ($role->isGrouped()) {
                foreach ($role->groups as $group) {
                    $filled = $group->assignments->count();
                    $buckets[$scopeKey]['assigned'] += $filled;

                    if (! $group->surplus && $filled < $min) {
                        $buckets[$scopeKey]['missing_min'] += $min - $filled;
                    }
                }
            } else {
                $filled = $role->assignments->count();
                $buckets[$scopeKey]['assigned'] += $filled;
                if (! $role->surplus && $filled < $min) {
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
     * Open staffing gaps per scope, one entry per role (grouped containers summed).
     *
     * @param  list<int>  $programIds  attached event first_program ids
     * @return list<array{
     *     key: string,
     *     critical: list<array{role_id: int, group_id: int|null, group_index: int|null, label: string, wanted: int, sequence: int, first_program: int|null, is_local: bool}>,
     *     recommended: list<array{role_id: int, group_id: int|null, group_index: int|null, label: string, wanted: int, sequence: int, first_program: int|null, is_local: bool}>
     * }>
     */
    public function openPositionsByScope(int $eventId, array $programIds): array
    {
        $sortedPrograms = $programIds;
        sort($sortedPrograms);

        $orderedKeys = ['cross'];
        foreach ($sortedPrograms as $programId) {
            $orderedKeys[] = 'program:'.$programId;
        }
        $orderedKeys[] = 'local';

        $accumulators = [];
        foreach ($orderedKeys as $key) {
            $accumulators[$key] = ['critical' => [], 'recommended' => []];
        }

        $roles = EventStaffingRole::query()
            ->where('event', $eventId)
            ->with(['catalogRole', 'groups.assignments', 'assignments'])
            ->get();

        foreach ($roles as $role) {
            $scopeKey = $this->scopeKeyForRole($role);
            if (! isset($accumulators[$scopeKey])) {
                continue;
            }

            $min = (int) $role->min;
            $best = (int) $role->best;
            $roleLabel = trim($role->label ?: ($role->catalogRole?->name ?? '')) ?: 'Unbenannt';

            if ($role->isGrouped()) {
                $meta = $this->openPositionContainerMeta($role, null, null, $roleLabel);
                foreach ($role->groups as $group) {
                    if ($group->surplus) {
                        continue;
                    }
                    $this->recordOpenPositionGaps(
                        $accumulators[$scopeKey],
                        $meta,
                        $group->assignments->count(),
                        $min,
                        $best,
                    );
                }
            } elseif (! $role->surplus) {
                $filled = $role->assignments->count();
                $meta = $this->openPositionContainerMeta($role, null, null, $roleLabel);
                $this->recordOpenPositionGaps(
                    $accumulators[$scopeKey],
                    $meta,
                    $filled,
                    $min,
                    $best,
                );
            }
        }

        $result = [];
        foreach ($orderedKeys as $key) {
            $critical = $this->finalizeOpenPositionEntries($accumulators[$key]['critical']);
            $recommended = $this->finalizeOpenPositionEntries($accumulators[$key]['recommended']);
            if ($critical === [] && $recommended === []) {
                continue;
            }

            $result[] = [
                'key' => $key,
                'critical' => $critical,
                'recommended' => $recommended,
            ];
        }

        return $result;
    }

    /**
     * Nav red-dot: surplus group with people, or non-surplus group below min (catalog + local).
     */
    public function staffingOk(int $eventId): bool
    {
        $roles = EventStaffingRole::query()
            ->where('event', $eventId)
            ->with(['groups.assignments', 'assignments'])
            ->get();

        if ($roles->isEmpty()) {
            return true;
        }

        foreach ($roles as $role) {
            if ($role->isGrouped()) {
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

                continue;
            }

            $count = $role->assignments->count();
            if ($role->surplus) {
                if ($count > 0) {
                    return false;
                }

                continue;
            }
            if ($count < (int) $role->min) {
                return false;
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
     * @return array{role_id: int, group_id: int|null, group_index: int|null, label: string, sequence: int, first_program: int|null, is_local: bool}
     */
    private function openPositionContainerMeta(
        EventStaffingRole $role,
        ?int $groupId,
        ?int $groupIndex,
        string $label,
    ): array {
        $firstProgram = null;
        if (! $role->isLocal() && $role->catalogRole?->first_program !== null) {
            $firstProgram = (int) $role->catalogRole->first_program;
        }

        return [
            'role_id' => (int) $role->id,
            'group_id' => $groupId,
            'group_index' => $groupIndex,
            'label' => $label,
            'sequence' => (int) $role->sequence,
            'first_program' => $firstProgram,
            'is_local' => $role->isLocal(),
        ];
    }

    /**
     * @param  array{critical: array<string, array<string, mixed>>, recommended: array<string, array<string, mixed>>}  $scope
     * @param  array{role_id: int, group_id: int|null, group_index: int|null, label: string, sequence: int, first_program: int|null, is_local: bool}  $meta
     */
    private function recordOpenPositionGaps(array &$scope, array $meta, int $filled, int $min, int $best): void
    {
        $key = 'r'.$meta['role_id'];

        if ($filled < $min) {
            $this->accumulateOpenPosition($scope['critical'], $key, $meta, $min - $filled);
        }
        if ($filled < $best && $best > $min) {
            $this->accumulateOpenPosition($scope['recommended'], $key, $meta, $best - $min);
        }
    }

    /**
     * @param  array<string, array{role_id: int, group_id: int|null, group_index: int|null, label: string, wanted: int, sequence: int, first_program: int|null, is_local: bool}>  $byKey
     * @param  array{role_id: int, group_id: int|null, group_index: int|null, label: string, sequence: int, first_program: int|null, is_local: bool}  $meta
     */
    private function accumulateOpenPosition(array &$byKey, string $key, array $meta, int $amount): void
    {
        if (! isset($byKey[$key])) {
            $byKey[$key] = [...$meta, 'wanted' => 0];
        }

        $byKey[$key]['wanted'] += $amount;
    }

    /**
     * @param  array<string, array{role_id: int, group_id: int|null, group_index: int|null, label: string, wanted: int, sequence: int, first_program: int|null, is_local: bool}>  $byKey
     * @return list<array{role_id: int, group_id: int|null, group_index: int|null, label: string, wanted: int, sequence: int, first_program: int|null, is_local: bool}>
     */
    private function finalizeOpenPositionEntries(array $byKey): array
    {
        $entries = array_values(array_filter(
            $byKey,
            fn (array $entry) => (int) ($entry['wanted'] ?? 0) > 0,
        ));

        usort($entries, function (array $a, array $b): int {
            if ($a['sequence'] !== $b['sequence']) {
                return $a['sequence'] <=> $b['sequence'];
            }

            $byLabel = strcasecmp($a['label'], $b['label']);
            if ($byLabel !== 0) {
                return $byLabel;
            }

            $byGroup = ($a['group_index'] ?? 0) <=> ($b['group_index'] ?? 0);
            if ($byGroup !== 0) {
                return $byGroup;
            }

            return $a['role_id'] <=> $b['role_id'];
        });

        return $entries;
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
