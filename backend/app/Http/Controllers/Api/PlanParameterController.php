<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MParameter;
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

        // Fetch all parameters using Eloquent relationships
        $parameters = MParameter::with(['planParamValues' => function($query) use ($planId) {
                $query->where('plan', $planId);
            }, 'firstProgram'])
            ->where('context', '!=', 'protected')
            ->get()
            ->map(function ($param) {
                // Get the user-set value if available
                $userValue = $param->planParamValues->first();
                $setValue = $userValue ? $userValue->set_value : null;

                // Set the final value: user-set value if available, otherwise default value
                $param->set_value = $setValue;
                $param->default_value = $param->value;
                $param->value = $setValue !== null ? $setValue : $param->value;
                $param->program_name = $param->firstProgram->name ?? null;

                // Remove the relationship objects to clean up the response
                unset($param->planParamValues, $param->firstProgram);

                return $param;
        });

        $filtered = $parameters->filter(function ($param) use ($event) {
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

            // Update all parameters in the database using Eloquent
            foreach ($validated['parameters'] as $param) {
                PlanParamValue::updateOrCreate(
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

            PlanParamValue::updateOrCreate(
                [
                    'plan' => $planId,
                    'parameter' => $validated['id'],
                ],
                [
                    'set_value' => $validated['value'],
                ]
            );
        }

        return response()->json(['status' => 'ok', 'queued' => true]);
        // return response()->json(['status' => 'ok']);
    }


    

}

