<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MParameter;
use App\Models\Plan;
use App\Models\PlanParamValue;
use App\Services\EventAttentionService;
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
        /*
        // Debug logging
        Log::info("updateParameter called", [
            "planId" => $planId,
            "requestData" => $request->all(),
            "headers" => $request->headers->all(),
            "method" => $request->method(),
            "url" => $request->url()
        ]); */

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

        // Get event ID from plan to update attention status
        $plan = Plan::find($planId);
        if ($plan) {
            // Only update attention if team count parameters (c_teams, e_teams) were changed
            $teamParamNames = ['c_teams', 'e_teams'];
            $shouldUpdate = false;
            
            if ($request->has('parameters')) {
                foreach ($validated['parameters'] as $param) {
                    $paramName = DB::table('m_parameter')->where('id', $param['id'])->value('name');
                    if (in_array($paramName, $teamParamNames)) {
                        $shouldUpdate = true;
                        break;
                    }
                }
            } else {
                $paramName = DB::table('m_parameter')->where('id', $validated['id'])->value('name');
                if (in_array($paramName, $teamParamNames)) {
                    $shouldUpdate = true;
                }
            }
            
            if ($shouldUpdate) {
                app(EventAttentionService::class)->updateEventAttentionStatus($plan->event);
            }
        }

        return response()->json(['status' => 'ok', 'queued' => true]);
        // return response()->json(['status' => 'ok']);
    }

    /**
     * Get non-default parameters for a plan (only those that differ from default)
     * Returns: two lists - one for 'input' context and one for 'expert' context
     * For 'input': only includes parameters with "duration" or "start" in the name
     * Each list contains: name, ui_label, set_value, value (default), sorted by sequence
     */
    public function getNonDefaultParameter($planId): JsonResponse
    {
        $baseQuery = function ($context, $filterInput = false) use ($planId) {
            $query = DB::table('plan_param_value as ppv')
                ->join('m_parameter as mp', 'mp.id', '=', 'ppv.parameter')
                ->where('ppv.plan', $planId)
                ->where('mp.context', $context)
                ->where(function ($q) {
                    $q->whereRaw('ppv.set_value <> mp.value')
                    ->orWhere(function ($q2) {
                        $q2->whereNull('ppv.set_value')
                            ->whereNotNull('mp.value');
                    })
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('ppv.set_value')
                            ->whereNull('mp.value');
                    });
                });
            
            // For input context, only include parameters with "duration" or "start" in the name
            if ($filterInput && $context === 'input') {
                $query->where(function ($q) {
                    $q->where('mp.name', 'like', '%duration%')
                      ->orWhere('mp.name', 'like', '%start%');
                });
            }
            
            return $query->select(
                    'mp.name',
                    'mp.ui_label',
                    'ppv.set_value',
                    'mp.value as default_value',
                    'mp.sequence'
                )
                ->orderBy('mp.sequence')
                ->get();
        };

        $inputParameters = $baseQuery('input', true);
        $expertParameters = $baseQuery('expert', false);

        // Get overwritten table names for this plan's event
        $plan = DB::table('plan')->where('id', $planId)->first();
        $tableNames = [];
        if ($plan && $plan->event) {
            $tableNames = DB::table('table_event')
                ->where('event', $plan->event)
                ->whereNotNull('table_name')
                ->where('table_name', '!=', '')
                ->orderBy('table_number')
                ->get(['table_number', 'table_name'])
                ->map(function ($row) {
                    return [
                        'table_number' => $row->table_number,
                        'table_name' => $row->table_name,
                    ];
                })
                ->toArray();
        }

        return response()->json([
            'input' => $inputParameters,
            'expert' => $expertParameters,
            'table_names' => $tableNames,
        ]);
    }

    

}

