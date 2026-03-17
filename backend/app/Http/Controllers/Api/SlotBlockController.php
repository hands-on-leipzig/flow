<?php

namespace App\Http\Controllers\Api;

use App\Enums\FirstProgram;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityGroup;
use App\Models\ExtraBlock;
use App\Models\MActivityTypeDetail;
use App\Models\Plan;
use App\Models\SlotBlockTeam;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SlotBlockController extends Controller
{
    private function activityCodeForFirstProgram(int $fp): string
    {
        return match ($fp) {
            FirstProgram::CHALLENGE->value => 'c_slot_block',
            FirstProgram::EXPLORE->value => 'e_slot_block',
            FirstProgram::JOINT->value => 'g_slot_block',
            default => 'g_slot_block',
        };
    }

    private function assertSlotBlock(ExtraBlock $block, int $planId): void
    {
        if ((int) $block->plan !== $planId || $block->type !== 'slot') {
            abort(404);
        }
    }

    private function firstProgramFromFlags(bool $forExplore, bool $forChallenge): int
    {
        if ($forExplore && $forChallenge) {
            return FirstProgram::JOINT->value;
        }
        if ($forExplore) {
            return FirstProgram::EXPLORE->value;
        }
        if ($forChallenge) {
            return FirstProgram::CHALLENGE->value;
        }
        throw ValidationException::withMessages([
            'for_explore' => ['Mindestens Explore oder Challenge muss aktiviert sein.'],
        ]);
    }

    private function flagsFromFirstProgram(?int $fp): array
    {
        $fp = (int) $fp;
        if ($fp === FirstProgram::JOINT->value) {
            return ['for_explore' => true, 'for_challenge' => true];
        }
        if ($fp === FirstProgram::EXPLORE->value) {
            return ['for_explore' => true, 'for_challenge' => false];
        }
        if ($fp === FirstProgram::CHALLENGE->value) {
            return ['for_explore' => false, 'for_challenge' => true];
        }

        return ['for_explore' => false, 'for_challenge' => false];
    }

    public function index(int $planId): JsonResponse
    {
        Plan::findOrFail($planId);

        $blocks = ExtraBlock::query()
            ->where('plan', $planId)
            ->where('type', 'slot')
            ->orderBy('name')
            ->get()
            ->map(function (ExtraBlock $b) {
                $flags = $this->flagsFromFirstProgram($b->first_program);

                return [
                    'id' => $b->id,
                    'plan' => $b->plan,
                    'name' => $b->name,
                    'description' => $b->description,
                    'link' => $b->link,
                    'duration' => $b->duration,
                    'active' => (bool) $b->active,
                    'room' => $b->room,
                    'for_explore' => $flags['for_explore'],
                    'for_challenge' => $flags['for_challenge'],
                ];
            });

        return response()->json($blocks);
    }

    public function store(Request $request, int $planId): JsonResponse
    {
        Plan::findOrFail($planId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'link' => 'nullable|string|max:500',
            'duration' => ['required', 'integer', 'min:5', 'max:480', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((int) $value % 5 !== 0) {
                    $fail('Dauer nur in 5-Minuten-Schritten.');
                }
            }],
            'for_explore' => 'required|boolean',
            'for_challenge' => 'required|boolean',
            'active' => 'sometimes|boolean',
        ]);

        $firstProgram = $this->firstProgramFromFlags(
            $validated['for_explore'],
            $validated['for_challenge']
        );

        $block = ExtraBlock::create([
            'plan' => $planId,
            'type' => 'slot',
            'first_program' => $firstProgram,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'link' => $validated['link'] ?? null,
            'duration' => $validated['duration'],
            'insert_point' => null,
            'start' => null,
            'end' => null,
            'active' => array_key_exists('active', $validated) ? (bool) $validated['active'] : true,
        ]);

        return response()->json([
            'id' => $block->id,
            'plan' => $block->plan,
            'name' => $block->name,
            'description' => $block->description,
            'link' => $block->link,
            'duration' => $block->duration,
            'active' => (bool) $block->active,
            'room' => $block->room,
            'for_explore' => $validated['for_explore'],
            'for_challenge' => $validated['for_challenge'],
        ], 201);
    }

    public function update(Request $request, int $planId, int $extraBlock): JsonResponse
    {
        $block = ExtraBlock::where('plan', $planId)->findOrFail($extraBlock);
        $this->assertSlotBlock($block, $planId);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'link' => 'nullable|string|max:500',
            'duration' => ['sometimes', 'integer', 'min:5', 'max:480', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((int) $value % 5 !== 0) {
                    $fail('Dauer nur in 5-Minuten-Schritten.');
                }
            }],
            'for_explore' => 'sometimes|boolean',
            'for_challenge' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('for_explore', $validated) || array_key_exists('for_challenge', $validated)) {
            $fe = array_key_exists('for_explore', $validated) ? $validated['for_explore'] : $this->flagsFromFirstProgram($block->first_program)['for_explore'];
            $fc = array_key_exists('for_challenge', $validated) ? $validated['for_challenge'] : $this->flagsFromFirstProgram($block->first_program)['for_challenge'];
            $block->first_program = $this->firstProgramFromFlags((bool) $fe, (bool) $fc);
            unset($validated['for_explore'], $validated['for_challenge']);
        }

        $block->fill($validated);
        $block->save();

        $flags = $this->flagsFromFirstProgram($block->first_program);

        return response()->json([
            'id' => $block->id,
            'plan' => $block->plan,
            'name' => $block->name,
            'description' => $block->description,
            'link' => $block->link,
            'duration' => $block->duration,
            'active' => (bool) $block->active,
            'room' => $block->room,
            'for_explore' => $flags['for_explore'],
            'for_challenge' => $flags['for_challenge'],
        ]);
    }

    public function destroy(int $planId, int $extraBlock): JsonResponse
    {
        $block = ExtraBlock::where('plan', $planId)->findOrFail($extraBlock);
        $this->assertSlotBlock($block, $planId);
        $block->delete();

        return response()->json(['message' => 'deleted']);
    }

    public function teamAssignments(int $planId, int $extraBlock): JsonResponse
    {
        $block = ExtraBlock::where('plan', $planId)->findOrFail($extraBlock);
        $this->assertSlotBlock($block, $planId);

        $fp = (int) $block->first_program;

        $q = DB::table('team_plan as tp')
            ->join('team as t', 't.id', '=', 'tp.team')
            ->leftJoin('slot_block_team as sbt', function ($j) use ($extraBlock) {
                $j->on('sbt.team', '=', 't.id')
                    ->where('sbt.extra_block', '=', $extraBlock);
            })
            ->where('tp.plan', $planId);

        if ($fp === FirstProgram::EXPLORE->value) {
            $q->whereIn('t.first_program', [FirstProgram::DISCOVER->value, FirstProgram::EXPLORE->value]);
        } elseif ($fp === FirstProgram::CHALLENGE->value) {
            $q->where('t.first_program', FirstProgram::CHALLENGE->value);
        }

        $rows = $q->select([
            't.id as team_id',
            'tp.team_number_plan',
            't.team_number_hot',
            't.name as team_name',
            't.first_program',
            'sbt.start as slot_start',
        ])
            ->orderByRaw('sbt.start IS NULL')
            ->orderBy('sbt.start')
            ->orderBy('tp.team_number_plan')
            ->get()
            ->map(function ($r) {
                return [
                    'team_id' => (int) $r->team_id,
                    'team_number_plan' => $r->team_number_plan,
                    'team_number_hot' => $r->team_number_hot,
                    'team_name' => $r->team_name,
                    'first_program' => (int) $r->first_program,
                    // Naive wall time — no ISO/Z to avoid client TZ/DST shifts
                    'start' => $r->slot_start
                        ? (is_string($r->slot_start)
                            ? $r->slot_start
                            : \Carbon\Carbon::parse($r->slot_start)->format('Y-m-d H:i:s'))
                        : null,
                ];
            });

        return response()->json(['teams' => $rows]);
    }

    public function updateTeamStart(Request $request, int $planId, int $extraBlock, int $team): JsonResponse
    {
        $block = ExtraBlock::where('plan', $planId)->findOrFail($extraBlock);
        $this->assertSlotBlock($block, $planId);

        $validated = $request->validate([
            // Key must be sent (null/'' = clear); value is naive wall time, no TZ
            'start' => [
                'present',
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value)) {
                        $fail('Ungültiges Start-Datum (erwartet YYYY-MM-DD HH:mm:ss).');
                    }
                },
            ],
        ]);

        $startRaw = $validated['start'];
        $startVal = $startRaw !== null && $startRaw !== ''
            ? preg_replace('/T/', ' ', (string) $startRaw, 1)
            : null;
        if ($startVal !== null && strlen($startVal) === 16) {
            $startVal .= ':00';
        }
        if ($startVal === '' || $startVal === null) {
            SlotBlockTeam::query()
                ->where('extra_block', $extraBlock)
                ->where('team', $team)
                ->delete();

            return response()->json(['team_id' => $team, 'start' => null]);
        }

        $tp = DB::table('team_plan')
            ->where('plan', $planId)
            ->where('team', $team)
            ->first();
        if (! $tp) {
            abort(404, 'Team not in plan');
        }

        $t = DB::table('team')->where('id', $team)->first();
        $fp = (int) $block->first_program;
        if ($fp === FirstProgram::EXPLORE->value) {
            if (! in_array((int) $t->first_program, [FirstProgram::DISCOVER->value, FirstProgram::EXPLORE->value], true)) {
                abort(422, 'Team not applicable for this slot block');
            }
        } elseif ($fp === FirstProgram::CHALLENGE->value) {
            if ((int) $t->first_program !== FirstProgram::CHALLENGE->value) {
                abort(422, 'Team not applicable for this slot block');
            }
        }

        SlotBlockTeam::updateOrCreate(
            ['extra_block' => $extraBlock, 'team' => $team],
            ['start' => $startVal]
        );

        $row = SlotBlockTeam::where('extra_block', $extraBlock)->where('team', $team)->first();

        $startOut = $row->start instanceof \Carbon\Carbon
            ? $row->start->format('Y-m-d H:i:s')
            : (string) $row->getRawOriginal('start');

        return response()->json([
            'team_id' => $team,
            'start' => $startOut,
        ]);
    }

    public function applyToPlan(int $planId): JsonResponse
    {
        Plan::findOrFail($planId);

        $slotBlockIds = ExtraBlock::query()
            ->where('plan', $planId)
            ->where('type', 'slot')
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $result = DB::transaction(function () use ($planId, $slotBlockIds) {
            $removedActivities = 0;
            $removedGroups = 0;
            $createdGroups = 0;
            $createdActivities = 0;

            // 1) Remove all activities + groups that refer to slot blocks (via activity.extra_block)
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

            // 2) For each active slot block: create a group + activities for teams with a start time
            $blocks = ExtraBlock::query()
                ->where('plan', $planId)
                ->where('type', 'slot')
                ->where('active', true)
                ->get(['id', 'first_program', 'duration']);

            foreach ($blocks as $block) {
                $fp = (int) $block->first_program;
                $code = $this->activityCodeForFirstProgram($fp);
                $activityTypeDetailId = (int) (MActivityTypeDetail::where('code', $code)->value('id'));
                if ($activityTypeDetailId <= 0) {
                    throw new \RuntimeException("m_activity_type_detail code not found: {$code}");
                }

                // Create group first (same as free blocks)
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

                // Apply same team filtering rules as the UI/API listing
                if ($fp === FirstProgram::EXPLORE->value) {
                    $q->whereIn('t.first_program', [FirstProgram::DISCOVER->value, FirstProgram::EXPLORE->value]);
                } elseif ($fp === FirstProgram::CHALLENGE->value) {
                    $q->where('t.first_program', FirstProgram::CHALLENGE->value);
                }

                $rows = $q->select(['sbt.team as team_id', 'sbt.start as start'])
                    ->orderBy('sbt.start')
                    ->get();

                $dur = (int) $block->duration;
                $tz = new DateTimeZone('UTC'); // treat as wall time; do not apply local DST rules

                $insert = [];
                foreach ($rows as $r) {
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
                        'slot_team' => (int) $r->team_id,
                        'explore_group' => null,
                    ];
                }

                if (! empty($insert)) {
                    Activity::insert($insert);
                    $createdActivities += count($insert);
                }
            }

            return [
                'removed_activities' => $removedActivities,
                'removed_groups' => $removedGroups,
                'created_groups' => $createdGroups,
                'created_activities' => $createdActivities,
            ];
        });

        return response()->json($result);
    }
}
