<?php

namespace App\Services;

use App\Enums\FirstProgram;
use App\Models\Activity;
use App\Models\ActivityGroup;
use App\Models\ExtraBlock;
use App\Models\MActivityTypeDetail;
use App\Models\Plan;
use App\Support\ExtraBlockActivityTypeCode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Materializes slot extra blocks into plan activities (from slot_block_team rows).
 */
class SlotBlockPlanSyncService
{
    /**
     * @return array<int, array{program: int, code: string}>
     */
    private function materializationTargetsForFirstProgram(int $firstProgram): array
    {
        // Joint slot blocks are materialized as two groups: Explore + Challenge.
        if ($firstProgram === FirstProgram::JOINT->value) {
            return [
                [
                    'program' => FirstProgram::EXPLORE->value,
                    'code' => ExtraBlockActivityTypeCode::forSlot(FirstProgram::EXPLORE->value),
                ],
                [
                    'program' => FirstProgram::CHALLENGE->value,
                    'code' => ExtraBlockActivityTypeCode::forSlot(FirstProgram::CHALLENGE->value),
                ],
            ];
        }

        return [[
            'program' => $firstProgram,
            'code' => ExtraBlockActivityTypeCode::forSlot($firstProgram),
        ]];
    }

    /**
     * @return array{removed_activities: int, removed_groups: int, created_groups: int, created_activities: int}
     */
    public function applyToPlan(int $planId): array
    {
        Plan::findOrFail($planId);

        $slotBlockIds = ExtraBlock::query()
            ->where('plan', $planId)
            ->where('type', 'slot')
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return DB::transaction(function () use ($planId, $slotBlockIds) {
            $removedActivities = 0;
            $removedGroups = 0;
            $createdGroups = 0;
            $createdActivities = 0;

            if (! empty($slotBlockIds)) {
                $groupIds = Activity::query()
                    ->whereIn('extra_block', $slotBlockIds)
                    ->pluck('activity_group')
                    ->unique()
                    ->map(fn ($v) => (int) $v)
                    ->all();

                $removedActivities = Activity::query()
                    ->whereIn('extra_block', $slotBlockIds)
                    ->delete();

                if (! empty($groupIds)) {
                    $removedGroups = ActivityGroup::query()
                        ->where('plan', $planId)
                        ->whereIn('id', $groupIds)
                        ->delete();
                }
            }

            $blocks = ExtraBlock::query()
                ->where('plan', $planId)
                ->where('type', 'slot')
                ->where('active', true)
                ->get(['id', 'first_program', 'duration']);

            foreach ($blocks as $block) {
                $fp = (int) $block->first_program;
                foreach ($this->materializationTargetsForFirstProgram($fp) as $target) {
                    $targetProgram = (int) $target['program'];
                    $code = $target['code'];
                    $activityTypeDetailId = (int) (MActivityTypeDetail::where('code', $code)->value('id'));
                    if ($activityTypeDetailId <= 0) {
                        throw new \RuntimeException("m_activity_type_detail code not found: {$code}");
                    }

                    $group = ActivityGroup::create([
                        'plan' => $planId,
                        'activity_type_detail' => $activityTypeDetailId,
                        'explore_group' => null,
                    ]);
                    $createdGroups++;

                    $q = DB::table('slot_block_team as sbt')
                        ->join('team_plan as tp', function ($j) use ($planId) {
                            $j->on('tp.team', '=', 'sbt.team')->where('tp.plan', '=', $planId);
                        })
                        ->join('team as t', 't.id', '=', 'sbt.team')
                        ->where('sbt.extra_block', (int) $block->id)
                        ->whereNotNull('sbt.start');

                    if ($targetProgram === FirstProgram::EXPLORE->value) {
                        $q->whereIn('t.first_program', [FirstProgram::DISCOVER->value, FirstProgram::EXPLORE->value]);
                    } elseif ($targetProgram === FirstProgram::CHALLENGE->value) {
                        $q->where('t.first_program', FirstProgram::CHALLENGE->value);
                    }

                    $rows = $q->select([
                        'tp.team_number_plan as team_number_plan',
                        'sbt.start as start',
                    ])
                        ->orderBy('sbt.start')
                        ->get();

                    $dur = (int) $block->duration;
                    $tz = new DateTimeZone('UTC');

                    $insert = [];
                    foreach ($rows as $r) {
                        $teamNo = (int) ($r->team_number_plan ?? 0);
                        if ($teamNo <= 0) {
                            continue;
                        }

                        $startStr = is_string($r->start) ? $r->start : (string) $r->start;
                        $startStr = preg_replace('/T/', ' ', $startStr, 1);
                        if (strlen($startStr) === 16) {
                            $startStr .= ':00';
                        }

                        $startDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startStr, $tz);
                        if (! $startDt) {
                            continue;
                        }
                        $endDt = $startDt->modify('+'.$dur.' minutes');

                        $insert[] = [
                            'activity_group' => $group->id,
                            'activity_type_detail' => $activityTypeDetailId,
                            'start' => $startDt->format('Y-m-d H:i:s'),
                            'end' => $endDt->format('Y-m-d H:i:s'),
                            'extra_block' => (int) $block->id,
                            'slot_team' => $teamNo,
                            'explore_group' => null,
                        ];
                    }

                    if (! empty($insert)) {
                        Activity::insert($insert);
                        $createdActivities += count($insert);
                    }
                }
            }

            return [
                'removed_activities' => $removedActivities,
                'removed_groups' => $removedGroups,
                'created_groups' => $createdGroups,
                'created_activities' => $createdActivities,
            ];
        });
    }
}
