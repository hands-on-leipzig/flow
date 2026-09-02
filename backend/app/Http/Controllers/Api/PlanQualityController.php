<?php

namespace App\Http\Controllers\Api;

use App\Enums\FirstProgram;
use App\Http\Controllers\Controller;
use App\Models\QPlan;
use App\Services\QualityEvaluatorService;
use App\Services\SeasonService;
use App\Support\ChallengeShapedParamMap;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanQualityController extends Controller
{
    private const PROGRAM_LABELS = [
        FirstProgram::CHALLENGE->value => 'Challenge',
        FirstProgram::FUTURE_8->value => 'Future 8+',
    ];

    public function __construct(
        private readonly QualityEvaluatorService $evaluator,
    ) {}

    public function listEvents(Request $request): JsonResponse
    {
        $seasonId = (int) ($request->query('season') ?: SeasonService::currentSeasonId());

        $rows = DB::table('event')
            ->leftJoin('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
            ->leftJoin('plan', 'plan.event', '=', 'event.id')
            ->where('event.season', $seasonId)
            ->where('regional_partner.name', 'not like', '%QPlan RP%')
            ->orderBy('event.date')
            ->orderBy('event.name')
            ->get([
                'event.id as event_id',
                'event.name as event_name',
                'event.date as event_date',
                'regional_partner.name as regional_partner_name',
                'regional_partner.id as regional_partner_id',
                'plan.id as plan_id',
                'plan.last_change as plan_last_change',
            ]);

        $planIds = $rows->pluck('plan_id')->filter()->unique()->values()->all();

        $qPlansByPlanProgram = [];
        if ($planIds !== []) {
            $qPlanRows = DB::table('q_plan')
                ->whereIn('plan', $planIds)
                ->where('calculated', 1)
                ->orderByDesc('id')
                ->get();

            foreach ($qPlanRows as $qp) {
                $key = $qp->plan.'_'.$qp->first_program;
                if (! isset($qPlansByPlanProgram[$key])) {
                    $qPlansByPlanProgram[$key] = $qp;
                }
            }
        }

        $events = [];
        foreach ($rows as $row) {
            $planId = $row->plan_id ? (int) $row->plan_id : null;
            $status = 'no_plan';
            $programs = [];

            if ($planId !== null) {
                $pp = PlanParameter::load($planId);
                $presence = ProgramPresence::forPlan($planId, $pp);
                $challengePrograms = $presence->challengeShapedOnIds();

                if ($challengePrograms === []) {
                    continue;
                }

                $status = 'evaluable';
                $planObj = (object) [
                    'last_change' => $row->plan_last_change,
                ];

                foreach ($challengePrograms as $programId) {
                    $key = $planId.'_'.$programId;
                    $qplanRow = $qPlansByPlanProgram[$key] ?? null;
                    $stale = $this->evaluator->isQPlanStale($planObj, $qplanRow);
                    $staleReason = null;
                    if ($stale) {
                        $staleReason = $qplanRow === null ? 'missing' : 'plan_changed';
                    }

                    $programs[] = [
                        'first_program' => $programId,
                        'label' => self::PROGRAM_LABELS[$programId] ?? "Program {$programId}",
                        'q_plan' => $qplanRow ? $this->serializeQPlan($qplanRow) : null,
                        'stale' => $stale,
                        'stale_reason' => $staleReason,
                    ];
                }
            }

            $events[] = [
                'event_id' => (int) $row->event_id,
                'event_name' => $row->event_name,
                'event_date' => $row->event_date,
                'regional_partner_name' => $row->regional_partner_name,
                'regional_partner_id' => $row->regional_partner_id ? (int) $row->regional_partner_id : null,
                'plan_id' => $planId,
                'status' => $status,
                'programs' => $programs,
            ];
        }

        return response()->json([
            'season_id' => $seasonId,
            'events' => $events,
        ]);
    }

    public function evaluatePlan(Request $request, int $planId): JsonResponse
    {
        $validated = $request->validate([
            'first_program' => 'required|integer|in:3,8',
            'force' => 'sometimes|boolean',
        ]);

        $firstProgram = (int) $validated['first_program'];
        $force = (bool) ($validated['force'] ?? false);

        if (! ChallengeShapedParamMap::isSupported($firstProgram)) {
            return response()->json(['message' => 'first_program must be Challenge or Future 8+'], 422);
        }

        try {
            $qPlan = $this->evaluator->ensureEvaluatedForPlan($planId, $firstProgram, $force);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($qPlan);
    }

    private function serializeQPlan(object $row): array
    {
        $model = QPlan::find($row->id);

        return $model ? $model->toArray() : (array) $row;
    }
}
