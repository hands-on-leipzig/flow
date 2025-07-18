<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlanParamValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        // Fetch all parameters with any matching plan-specific value
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
            ) // include value from plan_param_value
            ->get();

        return response()->json($parameters);
    }

    public function updateParameter(Request $request, $planId): JsonResponse
    {
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

        $groupIds = DB::table('activity_group')
            ->where('plan', $planId)
            ->pluck('id');

        DB::table('activity')
            ->whereIn('activity_group', $groupIds)
            ->delete();

        DB::table('activity_group')
            ->where('plan', $planId)
            ->delete();

        require_once base_path('legacy/generator/generator_functions.php');
        require_once base_path('legacy/generator/generator_db.php');

        ob_start();
        db_connect_persistent();
        global $DEBUG;
        $DEBUG = 1;
        g_generator($planId);
        ob_end_clean();
        return response()->json(['status' => 'ok']);
    }
}

