<?php

namespace App\Http\Controllers\Api;

use App\Enums\FirstProgram;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSlotExtraBlockRequest;
use App\Http\Requests\UpdateSlotExtraBlockRequest;
use App\Http\Requests\UpdateSlotTeamStartRequest;
use App\Models\ExtraBlock;
use App\Models\MInsertPoint;
use App\Models\Plan;
use App\Models\SlotBlockTeam;
use App\Services\EventAttentionService;
use App\Services\ExtraBlockCleanupService;
use App\Services\SlotBlockPlanSyncService;
use App\Support\PlanParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ExtraBlockController extends Controller
{
    public function __construct(
        private ExtraBlockCleanupService $extraBlockCleanup,
        private SlotBlockPlanSyncService $slotBlockPlanSync,
    ) {}

    public function getInsertPoints(Request $request)
    {
        $eventLevel = $request->query('level');

        if ($eventLevel !== null) {
            $insert_points = MInsertPoint::where('level', '<=', $eventLevel)->get();
        } else {
            $insert_points = MInsertPoint::all();
        }

        return response()->json($insert_points);
    }

    /**
     * List extra blocks. Use ?type=inserted|free|slot to narrow; omit for legacy inserted+free (excludes slot).
     */
    public function getBlocksForPlan(Request $request, int $planId): JsonResponse
    {
        $type = $request->query('type');

        if ($type === 'slot') {
            return $this->slotIndex($planId);
        }

        $q = ExtraBlock::query()->where('plan', $planId);

        if ($type === 'inserted') {
            $q->where('type', 'inserted');
        } elseif ($type === 'free') {
            $q->where('type', 'free');
        } else {
            if ($type !== null && $type !== '') {
                abort(400, 'type must be inserted, free, slot, or omitted');
            }
            $q->whereIn('type', ['inserted', 'free']);
        }

        $blocks = $q->orderBy('insert_point')->orderBy('start')->get();

        return response()->json($blocks);
    }

    public function getBlocksForPlanWithRoomTypes(int $planId)
    {
        $blocks = ExtraBlock::query()
            ->with(['insertPoint.roomType'])
            ->where('plan', $planId)
            ->orderBy('insert_point')
            ->orderBy('start')
            ->get();

        return response()->json($blocks);
    }

    public function storeOrUpdate(Request $request, int $planId)
    {
        $allowedPrograms = implode(',', [
            FirstProgram::JOINT->value,
            FirstProgram::DISCOVER->value,
            FirstProgram::EXPLORE->value,
            FirstProgram::CHALLENGE->value,
        ]);

        $validated = $request->validate([
            'id' => 'nullable|integer|exists:extra_block,id',
            'first_program' => "nullable|integer|in:{$allowedPrograms}",
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'link' => 'nullable|string|max:255',
            'insert_point' => 'nullable|integer|exists:m_insert_point,id',
            'buffer_before' => 'nullable|integer|min:0',
            'duration' => 'nullable|integer|min:0',
            'buffer_after' => 'nullable|integer|min:0',
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
            'room' => 'nullable|integer|exists:room,id',
            'active' => 'nullable|boolean',
            'type' => 'nullable|string|in:inserted,free,slot',
            'skip_regeneration' => 'nullable|boolean',
        ]);

        if (($validated['type'] ?? '') === 'slot') {
            abort(422, 'Slot blocks must be created via POST /plans/{plan}/extra-blocks/slot');
        }

        if (! empty($validated['id'])) {
            $existing = ExtraBlock::find($validated['id']);
            if ($existing && $existing->type === 'slot') {
                abort(422, 'Slot blocks must be updated via PUT /plans/{plan}/extra-blocks/slot/{id}');
            }
        }

        $validated['plan'] = $planId;

        if (! isset($validated['type']) || $validated['type'] === '') {
            $validated['type'] = isset($validated['insert_point']) && $validated['insert_point'] !== null
                ? 'inserted'
                : 'free';
        }

        $skipRegeneration = $validated['skip_regeneration'] ?? false;
        unset($validated['skip_regeneration']);

        $block = ExtraBlock::updateOrCreate(
            ['id' => $validated['id'] ?? null],
            $validated
        );

        if (! $skipRegeneration) {
            try {
                $generator = app(PlanGeneratorController::class);
                $response = $generator->generateLite($planId);

                if ($response->getStatusCode() !== 200) {
                    $responseData = $response->getData(true);
                    Log::error("Fehler bei der Lite-Regeneration des Plans {$planId}", [
                        'status' => $response->getStatusCode(),
                        'error' => $responseData['error'] ?? 'Unknown error',
                        'details' => $responseData['details'] ?? null,
                    ]);

                    return response()->json([
                        'block' => $block,
                        'skip_regeneration' => $skipRegeneration,
                        'error' => $responseData['error'] ?? 'Fehler bei der Lite-Generierung',
                        'details' => $responseData['details'] ?? $responseData['message'] ?? null,
                    ], $response->getStatusCode());
                }
            } catch (\Throwable $e) {
                Log::error("Fehler bei der Regeneration des Plans {$planId}: ".$e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);

                $errorMessage = 'Fehler bei der Lite-Generierung';
                $details = $e->getMessage();

                if (str_contains($e->getMessage(), "Parameter '")) {
                    $errorMessage = 'Ungültiger Parameterwert';
                } elseif (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'existiert nicht')) {
                    $errorMessage = 'Fehlende Daten';
                } elseif (str_contains($e->getMessage(), 'FreeBlockGenerator') || str_contains($e->getMessage(), 'freien Aktivitäten')) {
                    $errorMessage = 'Fehler beim Einfügen der freien Blöcke';
                }

                return response()->json([
                    'block' => $block,
                    'skip_regeneration' => $skipRegeneration,
                    'error' => $errorMessage,
                    'details' => $details,
                ], 500);
            }
        }

        $plan = Plan::find($planId);
        if ($plan) {
            app(EventAttentionService::class)->updateEventAttentionStatus($plan->event);
        }

        return response()->json([
            'block' => $block,
            'skip_regeneration' => $skipRegeneration,
        ]);
    }

    public function delete(int $id)
    {
        $block = ExtraBlock::findOrFail($id);
        $planId = $block->plan;

        $this->extraBlockCleanup->beforeDelete($block);
        $block->delete();

        try {
            $generator = app(PlanGeneratorController::class);
            $response = $generator->generateLite($planId);

            if ($response->getStatusCode() !== 200) {
                $responseData = $response->getData(true);
                Log::error("Fehler bei der Lite-Regeneration des Plans {$planId} nach Block-Löschung", [
                    'status' => $response->getStatusCode(),
                    'error' => $responseData['error'] ?? 'Unknown error',
                    'details' => $responseData['details'] ?? null,
                ]);

                return response()->json([
                    'message' => 'Extra block deleted',
                    'error' => $responseData['error'] ?? 'Fehler bei der Lite-Generierung',
                    'details' => $responseData['details'] ?? $responseData['message'] ?? null,
                ], $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            Log::error("Fehler bei der Regeneration des Plans {$planId} nach Block-Löschung: ".$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $errorMessage = 'Fehler bei der Lite-Generierung';
            $details = $e->getMessage();

            if (str_contains($e->getMessage(), "Parameter '")) {
                $errorMessage = 'Ungültiger Parameterwert';
            } elseif (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'existiert nicht')) {
                $errorMessage = 'Fehlende Daten';
            } elseif (str_contains($e->getMessage(), 'FreeBlockGenerator') || str_contains($e->getMessage(), 'freien Aktivitäten')) {
                $errorMessage = 'Fehler beim Einfügen der freien Blöcke';
            }

            return response()->json([
                'message' => 'Extra block deleted',
                'error' => $errorMessage,
                'details' => $details,
            ], 500);
        }

        $plan = Plan::find($planId);
        if ($plan) {
            app(EventAttentionService::class)->updateEventAttentionStatus($plan->event);
        }

        return response()->json(['message' => 'Extra block deleted']);
    }

    // --- Slot blocks (type=slot) under unified extra-block API ---

    public function slotApplyToPlan(int $planId): JsonResponse
    {
        $result = $this->slotBlockPlanSync->applyToPlan($planId);

        return response()->json($result);
    }

    public function slotIndex(int $planId): JsonResponse
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

    public function slotStore(StoreSlotExtraBlockRequest $request, int $planId): JsonResponse
    {
        Plan::findOrFail($planId);

        $validated = $request->validated();
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

    public function slotUpdate(UpdateSlotExtraBlockRequest $request, int $planId, int $extraBlock): JsonResponse
    {
        $block = ExtraBlock::where('plan', $planId)->findOrFail($extraBlock);
        $this->assertSlotBlock($block, $planId);

        $validated = $request->validated();

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

    public function slotDestroy(int $planId, int $extraBlock): JsonResponse
    {
        $block = ExtraBlock::where('plan', $planId)->findOrFail($extraBlock);
        $this->assertSlotBlock($block, $planId);
        $this->extraBlockCleanup->beforeDelete($block);
        $block->delete();

        return response()->json(['message' => 'deleted']);
    }

    public function slotTeamAssignments(int $planId, int $extraBlock): JsonResponse
    {
        $block = ExtraBlock::where('plan', $planId)->findOrFail($extraBlock);
        $this->assertSlotBlock($block, $planId);
        $params = PlanParameter::load($planId);
        $eTransfer = (int) $params->get('e_duration_transfer');
        $cTransfer = (int) $params->get('c_duration_transfer');

        $fp = (int) $block->first_program;

        $assignments = SlotBlockTeam::query()
            ->where('extra_block', $extraBlock)
            ->get()
            ->keyBy(fn (SlotBlockTeam $row) => ((int) $row->first_program).':'.((int) $row->team_number_plan));

        $teamLookup = DB::table('team_plan as tp')
            ->join('team as t', 't.id', '=', 'tp.team')
            ->where('tp.plan', $planId)
            ->select([
                'tp.team_number_plan',
                't.id as team_id',
                't.team_number_hot',
                't.name as team_name',
                't.first_program',
            ])
            ->get()
            ->groupBy(function ($row) {
                $fp = (int) $row->first_program;
                $program = $fp === FirstProgram::CHALLENGE->value
                    ? FirstProgram::CHALLENGE->value
                    : FirstProgram::EXPLORE->value;

                return $program.':'.((int) $row->team_number_plan);
            })
            ->map(fn ($rows) => $rows->first());

        $rows = collect();
        foreach ($this->assignmentRangesForBlock($block, $params) as $range) {
            $program = (int) $range['program'];
            for ($teamNo = 1; $teamNo <= (int) $range['team_count']; $teamNo++) {
                $key = $program.':'.$teamNo;
                /** @var SlotBlockTeam|null $assignment */
                $assignment = $assignments->get($key);
                $lookup = $teamLookup->get($key);

                $start = null;
                if ($assignment?->start) {
                    $start = $assignment->start instanceof \Carbon\Carbon
                        ? $assignment->start->format('Y-m-d H:i:s')
                        : (string) $assignment->getRawOriginal('start');
                }

                $collision = null;
                if ($start !== null) {
                    $transfer = $this->transferDurationForProgram($program, $eTransfer, $cTransfer);
                    $collision = $this->evaluateTeamCollisionForSlot(
                        $planId,
                        $teamNo,
                        $program,
                        $start,
                        (int) $block->duration,
                        $transfer,
                        $extraBlock
                    );
                }

                $placeholderName = sprintf('T%02d !Platzhalter, weil nicht genügend Teams angemeldet sind!', $teamNo);
                $rows->push([
                    'row_key' => $key,
                    'team_id' => $lookup->team_id ?? null,
                    'team_number_plan' => $teamNo,
                    'team_number_hot' => $lookup->team_number_hot ?? null,
                    'team_name' => $lookup->team_name ?? $placeholderName,
                    'first_program' => $program,
                    'start' => $start,
                    'collision_status' => $collision['status'] ?? null,
                    'collision_gap_minutes' => $collision['min_gap_minutes'] ?? null,
                ]);
            }
        }

        $rows = $rows
            ->sort(function ($a, $b) {
                if (empty($a['start']) && empty($b['start'])) {
                    if ((int) $a['first_program'] !== (int) $b['first_program']) {
                        return (int) $a['first_program'] <=> (int) $b['first_program'];
                    }

                    return (int) $a['team_number_plan'] <=> (int) $b['team_number_plan'];
                }
                if (empty($a['start'])) {
                    return 1;
                }
                if (empty($b['start'])) {
                    return -1;
                }
                if ((string) $a['start'] === (string) $b['start']) {
                    if ((int) $a['first_program'] !== (int) $b['first_program']) {
                        return (int) $a['first_program'] <=> (int) $b['first_program'];
                    }

                    return (int) $a['team_number_plan'] <=> (int) $b['team_number_plan'];
                }

                return (string) $a['start'] <=> (string) $b['start'];
            })
            ->values();

        return response()->json([
            'teams' => $rows,
            'e_duration_transfer' => $eTransfer,
            'c_duration_transfer' => $cTransfer,
        ]);
    }

    public function slotUpdateTeamStart(UpdateSlotTeamStartRequest $request, int $planId, int $extraBlock, int $programId, int $teamNumberPlan): JsonResponse
    {
        $block = ExtraBlock::where('plan', $planId)->findOrFail($extraBlock);
        $this->assertSlotBlock($block, $planId);

        $validated = $request->validated();
        if (! in_array($programId, [FirstProgram::EXPLORE->value, FirstProgram::CHALLENGE->value], true)) {
            abort(422, 'Invalid program for slot assignment');
        }

        $params = PlanParameter::load($planId);
        $isAllowedProgram = $this->blockAllowsProgram((int) $block->first_program, $programId);
        if (! $isAllowedProgram) {
            abort(422, 'Program not applicable for this slot block');
        }
        $maxTeams = $programId === FirstProgram::CHALLENGE->value
            ? (int) $params->get('c_teams')
            : (int) $params->get('e_teams');
        if ($teamNumberPlan < 1 || $teamNumberPlan > $maxTeams) {
            abort(422, 'Team number out of configured plan range');
        }

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
                ->where('first_program', $programId)
                ->where('team_number_plan', $teamNumberPlan)
                ->delete();

            return response()->json([
                'first_program' => $programId,
                'team_number_plan' => $teamNumberPlan,
                'start' => null,
            ]);
        }

        SlotBlockTeam::updateOrCreate(
            [
                'extra_block' => $extraBlock,
                'first_program' => $programId,
                'team_number_plan' => $teamNumberPlan,
            ],
            ['start' => $startVal]
        );

        $row = SlotBlockTeam::where('extra_block', $extraBlock)
            ->where('first_program', $programId)
            ->where('team_number_plan', $teamNumberPlan)
            ->first();

        $startOut = $row->start instanceof \Carbon\Carbon
            ? $row->start->format('Y-m-d H:i:s')
            : (string) $row->getRawOriginal('start');

        $eTransfer = (int) $params->get('e_duration_transfer');
        $cTransfer = (int) $params->get('c_duration_transfer');
        $transfer = $this->transferDurationForProgram($programId, $eTransfer, $cTransfer);
        $collision = $this->evaluateTeamCollisionForSlot(
            $planId,
            $teamNumberPlan,
            $programId,
            $startOut,
            (int) $block->duration,
            $transfer,
            $extraBlock
        );

        return response()->json([
            'first_program' => $programId,
            'team_number_plan' => $teamNumberPlan,
            'start' => $startOut,
            'collision_status' => $collision['status'],
            'collision_gap_minutes' => $collision['min_gap_minutes'],
        ]);
    }

    public function slotTeamActivities(int $planId, int $extraBlock, int $programId, int $teamNumberPlan): JsonResponse
    {
        $block = ExtraBlock::where('plan', $planId)->findOrFail($extraBlock);
        $this->assertSlotBlock($block, $planId);

        if (! in_array($programId, [FirstProgram::EXPLORE->value, FirstProgram::CHALLENGE->value], true)) {
            abort(422, 'Invalid program for slot assignment');
        }
        if (! $this->blockAllowsProgram((int) $block->first_program, $programId)) {
            abort(422, 'Program not applicable for this slot block');
        }

        $params = PlanParameter::load($planId);
        $maxTeams = $programId === FirstProgram::CHALLENGE->value
            ? (int) $params->get('c_teams')
            : (int) $params->get('e_teams');
        if ($teamNumberPlan < 1 || $teamNumberPlan > $maxTeams) {
            abort(422, 'Team number out of configured plan range');
        }

        $transfer = $this->transferDurationForProgram(
            $programId,
            (int) $params->get('e_duration_transfer'),
            (int) $params->get('c_duration_transfer')
        );

        $assignment = SlotBlockTeam::query()
            ->where('extra_block', $extraBlock)
            ->where('first_program', $programId)
            ->where('team_number_plan', $teamNumberPlan)
            ->first();

        $slotStart = null;
        if ($assignment?->start) {
            $slotStart = $assignment->start instanceof \Carbon\Carbon
                ? $assignment->start->format('Y-m-d H:i:s')
                : (string) $assignment->getRawOriginal('start');
        }

        $rows = DB::table('activity as a')
            ->join('activity_group as ag', 'ag.id', '=', 'a.activity_group')
            ->join('m_activity_type_detail as atd', 'atd.id', '=', 'a.activity_type_detail')
            ->leftJoin('extra_block as eb', 'eb.id', '=', 'a.extra_block')
            ->where('ag.plan', $planId)
            ->where('atd.first_program', $programId)
            ->whereExists(function ($q) use ($programId) {
                $roleId = $programId === FirstProgram::CHALLENGE->value ? 3 : 8;
                $q->select(DB::raw(1))
                    ->from('m_visibility as mv')
                    ->whereColumn('mv.activity_type_detail', 'atd.id')
                    ->where('mv.role', $roleId);
            })
            ->where(function ($q) use ($teamNumberPlan) {
                $q->where('a.jury_team', $teamNumberPlan)
                    ->orWhere('a.table_1_team', $teamNumberPlan)
                    ->orWhere('a.table_2_team', $teamNumberPlan)
                    ->orWhere('a.slot_team', $teamNumberPlan);
            })
            ->where(function ($q) use ($extraBlock) {
                $q->whereNull('a.extra_block')
                    ->orWhere('a.extra_block', '!=', $extraBlock);
            })
            ->select([
                'a.id',
                'a.start',
                'a.end',
                'atd.name as activity_name',
                'atd.code as activity_code',
                'eb.name as extra_block_name',
            ])
            ->orderBy('a.start')
            ->orderBy('a.end')
            ->get()
            ->map(function ($row) use ($slotStart, $block, $transfer) {
                $start = is_string($row->start) ? $row->start : (string) $row->start;
                $end = is_string($row->end) ? $row->end : (string) $row->end;
                $start = preg_replace('/T/', ' ', $start, 1);
                $end = preg_replace('/T/', ' ', $end, 1);
                if (strlen($start) === 16) {
                    $start .= ':00';
                }
                if (strlen($end) === 16) {
                    $end .= ':00';
                }

                $status = null;
                $gap = null;
                if ($slotStart !== null) {
                    $comparison = $this->classifyActivityAgainstSlot(
                        $slotStart,
                        (int) $block->duration,
                        $transfer,
                        $start,
                        $end
                    );
                    $status = $comparison['status'];
                    $gap = $comparison['gap_minutes'];
                }

                return [
                    'id' => (int) $row->id,
                    'start' => $start,
                    'end' => $end,
                    'label' => str_contains((string) ($row->activity_code ?? ''), '_slot_block')
                        ? ((string) ($row->extra_block_name ?? '') !== '' ? $row->extra_block_name : ($row->activity_name ?: $row->activity_code))
                        : ($row->activity_name ?: $row->activity_code),
                    'status' => $status,
                    'gap_minutes' => $gap,
                ];
            })
            ->values();

        return response()->json([
            'first_program' => $programId,
            'team_number_plan' => $teamNumberPlan,
            'slot_start' => $slotStart,
            'slot_duration' => (int) $block->duration,
            'transfer_minutes' => $transfer,
            'activities' => $rows,
        ]);
    }

    private function blockAllowsProgram(int $blockFirstProgram, int $programId): bool
    {
        if ($blockFirstProgram === FirstProgram::JOINT->value) {
            return in_array($programId, [FirstProgram::EXPLORE->value, FirstProgram::CHALLENGE->value], true);
        }
        if ($blockFirstProgram === FirstProgram::EXPLORE->value) {
            return $programId === FirstProgram::EXPLORE->value;
        }
        if ($blockFirstProgram === FirstProgram::CHALLENGE->value) {
            return $programId === FirstProgram::CHALLENGE->value;
        }

        return false;
    }

    /**
     * @return array<int, array{program:int, team_count:int}>
     */
    private function assignmentRangesForBlock(ExtraBlock $block, PlanParameter $params): array
    {
        $fp = (int) $block->first_program;
        $eTeams = max(0, (int) $params->get('e_teams'));
        $cTeams = max(0, (int) $params->get('c_teams'));

        if ($fp === FirstProgram::JOINT->value) {
            return [
                ['program' => FirstProgram::EXPLORE->value, 'team_count' => $eTeams],
                ['program' => FirstProgram::CHALLENGE->value, 'team_count' => $cTeams],
            ];
        }
        if ($fp === FirstProgram::EXPLORE->value) {
            return [['program' => FirstProgram::EXPLORE->value, 'team_count' => $eTeams]];
        }
        if ($fp === FirstProgram::CHALLENGE->value) {
            return [['program' => FirstProgram::CHALLENGE->value, 'team_count' => $cTeams]];
        }

        return [];
    }

    /**
     * @return array{status: string, gap_minutes: ?int}
     */
    private function classifyActivityAgainstSlot(
        string $slotStart,
        int $slotDurationMinutes,
        int $transferMinutes,
        string $activityStart,
        string $activityEnd
    ): array {
        $slotStartDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $slotStart);
        $actStartDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $activityStart);
        $actEndDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $activityEnd);
        if (! $slotStartDt || ! $actStartDt || ! $actEndDt) {
            return ['status' => 'green', 'gap_minutes' => null];
        }
        $slotEndDt = $slotStartDt->modify('+'.$slotDurationMinutes.' minutes');

        if ($slotStartDt < $actEndDt && $slotEndDt > $actStartDt) {
            return ['status' => 'red', 'gap_minutes' => 0];
        }

        $gap = null;
        if ($slotEndDt <= $actStartDt) {
            $gap = (int) floor(($actStartDt->getTimestamp() - $slotEndDt->getTimestamp()) / 60);
        } elseif ($actEndDt <= $slotStartDt) {
            $gap = (int) floor(($slotStartDt->getTimestamp() - $actEndDt->getTimestamp()) / 60);
        }

        if ($gap !== null && $gap < $transferMinutes) {
            return ['status' => 'yellow', 'gap_minutes' => $gap];
        }

        return ['status' => 'green', 'gap_minutes' => $gap];
    }

    private function transferDurationForProgram(int $teamFirstProgram, int $eTransfer, int $cTransfer): int
    {
        return in_array($teamFirstProgram, [FirstProgram::DISCOVER->value, FirstProgram::EXPLORE->value], true)
            ? $eTransfer
            : $cTransfer;
    }

    private function visibilityRoleForProgram(int $teamFirstProgram): int
    {
        return in_array($teamFirstProgram, [FirstProgram::DISCOVER->value, FirstProgram::EXPLORE->value], true)
            ? 8 // Explore teams
            : 3; // Challenge teams
    }

    /**
     * @return array{status: string, min_gap_minutes: ?int}
     */
    private function evaluateTeamCollisionForSlot(
        int $planId,
        int $teamNumberPlan,
        int $teamFirstProgram,
        string $slotStart,
        int $slotDurationMinutes,
        int $transferMinutes,
        int $extraBlockId
    ): array {
        $slotStartDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $slotStart, new \DateTimeZone('UTC'));
        if (! $slotStartDt) {
            return ['status' => 'green', 'min_gap_minutes' => null];
        }
        $slotEndDt = $slotStartDt->modify('+'.$slotDurationMinutes.' minutes');

        $rows = DB::table('activity as a')
            ->join('activity_group as ag', 'ag.id', '=', 'a.activity_group')
            ->join('m_activity_type_detail as atd', 'atd.id', '=', 'a.activity_type_detail')
            ->where('ag.plan', $planId)
            ->where('atd.first_program', $teamFirstProgram)
            ->whereExists(function ($q) use ($teamFirstProgram) {
                $q->select(DB::raw(1))
                    ->from('m_visibility as mv')
                    ->whereColumn('mv.activity_type_detail', 'atd.id')
                    ->where('mv.role', $this->visibilityRoleForProgram($teamFirstProgram));
            })
            ->where(function ($q) use ($teamNumberPlan) {
                $q->where('a.jury_team', $teamNumberPlan)
                    ->orWhere('a.table_1_team', $teamNumberPlan)
                    ->orWhere('a.table_2_team', $teamNumberPlan)
                    ->orWhere('a.slot_team', $teamNumberPlan);
            })
            ->where(function ($q) use ($extraBlockId) {
                $q->whereNull('a.extra_block')
                    ->orWhere('a.extra_block', '!=', $extraBlockId);
            })
            ->select(['a.start', 'a.end'])
            ->get();

        $minGap = null;

        foreach ($rows as $row) {
            $aStart = is_string($row->start) ? $row->start : (string) $row->start;
            $aEnd = is_string($row->end) ? $row->end : (string) $row->end;
            $aStart = preg_replace('/T/', ' ', $aStart, 1);
            $aEnd = preg_replace('/T/', ' ', $aEnd, 1);
            if (strlen($aStart) === 16) {
                $aStart .= ':00';
            }
            if (strlen($aEnd) === 16) {
                $aEnd .= ':00';
            }

            $actStartDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $aStart, new \DateTimeZone('UTC'));
            $actEndDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $aEnd, new \DateTimeZone('UTC'));
            if (! $actStartDt || ! $actEndDt) {
                continue;
            }

            // Overlap: [slotStart, slotEnd) intersects [actStart, actEnd)
            if ($slotStartDt < $actEndDt && $slotEndDt > $actStartDt) {
                return ['status' => 'red', 'min_gap_minutes' => 0];
            }

            $gap = null;
            if ($slotEndDt <= $actStartDt) {
                $gap = (int) floor(($actStartDt->getTimestamp() - $slotEndDt->getTimestamp()) / 60);
            } elseif ($actEndDt <= $slotStartDt) {
                $gap = (int) floor(($slotStartDt->getTimestamp() - $actEndDt->getTimestamp()) / 60);
            }

            if ($gap !== null && ($minGap === null || $gap < $minGap)) {
                $minGap = $gap;
            }
        }

        if ($minGap !== null && $minGap < $transferMinutes) {
            return ['status' => 'yellow', 'min_gap_minutes' => $minGap];
        }

        return ['status' => 'green', 'min_gap_minutes' => $minGap];
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
}
