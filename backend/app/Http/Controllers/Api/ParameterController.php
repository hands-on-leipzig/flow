<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MParameter;
use App\Models\MParameterCondition;
use App\Models\SupportedPlan;
use App\Support\ProgramCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ParameterController extends Controller
{
    public function index()
    {
        $parameters = MParameter::all();
        return response()->json($parameters);
    }

    public function listConditions()
    {
        $conditions = MParameterCondition::all();
        return response()->json($conditions);
    }

    public function addCondition()
    {
        $condition = MParameterCondition::create();
        return response()->json($condition);
    }

    public function updateCondition(Request $request, $id)
    {
        $condition = MParameterCondition::findOrFail($id);

        $condition->update($request->only([
            'parameter',
            'if_parameter',
            'is',
            'value',
            'action',
        ]));

        return response()->json($condition);
    }

    public function deleteCondition($id)
    {
        MParameterCondition::destroy($id);
        return response()->json();
    }

    public function listLanesOptions()
    {
        $options = DB::table('m_supported_plan')->get();
        
        // Map database fields to expected frontend format
        $mappedOptions = $options->map(function ($option) {
            return [
                'first_program' => $option->first_program,
                'teams' => $option->teams,
                'lanes' => $option->lanes,
                'tables' => $option->tables,
                'note' => $option->note,
                'alert_level' => $option->alert_level ?? 0,
                'recommended' => $option->alert_level === 1, // alert_level 1 = recommended
                'suggested' => $option->alert_level === 1, // alert_level 1 = suggested
            ];
        });
        
        return response()->json($mappedOptions);
    }

    public function afternoonPrograms(): JsonResponse
    {
        return response()->json([
            'first_programs' => ProgramCatalog::afternoonFirstProgramIds(),
        ]);
    }

}
