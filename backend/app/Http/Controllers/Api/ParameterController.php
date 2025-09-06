<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MParameter;
use App\Models\MParameterCondition;
use App\Models\SupportedPlan;
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
        return response()->json($options);
    }

}