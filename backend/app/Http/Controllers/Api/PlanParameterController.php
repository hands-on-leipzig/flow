<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Plan;
use App\Models\PlanParamValue;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanParameterController extends Controller
{
    public function index($planId): JsonResponse
    {
        $params = PlanParamValue::with('parameter')
            ->where('plan', $planId)
            ->get()
            ->filter(fn($paramValue) => $paramValue->parameter)
            ->map(function ($paramValue) {
                $parameterData = $paramValue->parameter->toArray();
                $parameterData['value'] = $paramValue->set_value;
                return $parameterData;
            })
            ->values();

        return response()->json($params);
    }

    public function store(Request $request, $planId): JsonResponse
    {
        foreach ($request->input('parameters') as $param) {
            PlanParamValue::updateOrCreate(
                ['plan' => $planId, 'parameter' => $param['id']],
                ['set_value' => $param['value']]
            );
        }

        return response()->json(['status' => 'ok']);
    }

    public function getParametersForPlan($planId): JsonResponse
    {
        $plan = Plan::find($planId);
        $event = Event::find($plan->event);

        $paramValues = DB::table('plan_param_value')
            ->where('plan', $planId)
            ->pluck('set_value', 'parameter'); // [parameter_id => value]

        if ($paramValues->isEmpty()) {
            return $this->insertParamsFirst($planId);
        }

        // Fetch all parameters and values
        $parameters = DB::table('m_parameter')
            ->leftJoin('plan_param_value', function ($join) use ($planId) {
                $join->on('m_parameter.id', '=', 'plan_param_value.parameter')
                    ->where('plan_param_value.plan', '=', $planId);
            })
            ->leftJoin('m_first_program', 'm_parameter.first_program', '=', 'm_first_program.id')
            ->select(
                'm_parameter.*',
                'plan_param_value.set_value as value',
                'm_first_program.name as program_name'
            )
            ->where('m_parameter.context', '!=', "protected")
            ->get();

        $filtered = $parameters->filter(function ($param) use ($paramValues, $event) {
            if ($param->level > $event->level) return false;
            return true;
        })->values();

        return response()->json($filtered);
    }


    public function updateParameter(Request $request, $planId): JsonResponse

    {
        // Debug logging
        Log::info("updateParameter called", [
            "planId" => $planId,
            "requestData" => $request->all(),
            "headers" => $request->headers->all(),
            "method" => $request->method(),
            "url" => $request->url()
        ]);

        // Handle both single parameter and batch updates
        if ($request->has('parameters')) {
            // Batch update
            $validated = $request->validate([
                'parameters' => 'required|array',
                'parameters.*.id' => 'required|integer|exists:m_parameter,id',
                'parameters.*.value' => 'nullable|string',
            ]);

            // Update all parameters in the database
            foreach ($validated['parameters'] as $param) {
                DB::table('plan_param_value')->updateOrInsert(
                    [
                        'plan' => $planId,
                        'parameter' => $param['id'],
                    ],
                    [
                        'set_value' => $param['value'],
                    ]
                );
            }
        } else {
            // Single parameter update (backward compatibility)
            $validated = $request->validate([
                'id' => 'required|integer|exists:m_parameter,id',
                'value' => 'nullable|string',
            ]);

            DB::table('plan_param_value')->updateOrInsert(
                [
                    'plan' => $planId,
                    'parameter' => $validated['id'],
                ],
                [
                    'set_value' => $validated['value'],
                ]
            );
        }

        $groupIds = DB::table('activity_group')
            ->where('plan', $planId)
            ->pluck('id');

        DB::table('activity')
            ->whereIn('activity_group', $groupIds)
            ->delete();

        DB::table('activity_group')
            ->where('plan', $planId)
            ->delete();

        //\App\Jobs\GeneratePlan::dispatch($planId);
        // require_once base_path("legacy/generator/generator_main.php");
        // g_generator($planId);

        return response()->json(['status' => 'ok', 'queued' => true]);
    }

    public function insertParamsFirst($planId): JsonResponse
    {
        $parameters = DB::table('m_parameter')->select('id', 'value')->get();

        $insertData = [];
        foreach ($parameters as $param) {
            $insertData[] = [
                'plan' => $planId,
                'parameter' => $param->id,
                'set_value' => $param->value,
            ];
        }

        if (!empty($insertData)) {
            DB::table('plan_param_value')->insert($insertData);
        }

        return response()->json($planId, 201);
    }
}

