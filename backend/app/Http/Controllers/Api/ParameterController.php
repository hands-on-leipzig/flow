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

    //
    // functions to edit m_parameters
    //

    public function listMparameter(Request $req)
    {
        $q = DB::table('m_parameter')
            ->select([
                'id','name','context','level','type','value','min','max','step',
                'first_program','sequence','ui_label','ui_description'
            ])
            ->orderBy('sequence');

        if ($req->filled('context'))        $q->where('context', $req->string('context'));
        if ($req->filled('level'))          $q->where('level',   $req->integer('level'));
        if ($req->filled('first_program'))  $q->where('first_program', $req->integer('first_program'));

        return response()->json(['items' => $q->get()]);
    }

    public function updateMparameter(Request $req, int $id)
    {
        $data = $req->validate([
            'name'           => 'nullable|string|max:255',
            'ui_label'       => 'nullable|string|max:255',
            'ui_description' => 'nullable|string',
            'context'        => 'nullable|in:input,expert,protected,finale',
            'level'          => 'required|integer',
            'type'           => 'nullable|in:integer,decimal,time,date,boolean',
            'first_program'  => 'nullable|integer',
            'value'          => 'nullable|string|max:255',
            'min'            => 'nullable|string|max:255',
            'max'            => 'nullable|string|max:255',
            'step'           => 'nullable|string|max:255',
        ]);

        DB::table('m_parameter')->where('id', $id)->update($data);
        $row = DB::table('m_parameter')->where('id', $id)->first();

        return response()->json($row);
    }

    public function reorderMparameter(Request $req)
    {
        $payload = $req->validate([
            // Array von IDs in der gewünschten Reihenfolge
            'ordered_ids'   => 'required|array|min:1',
            'ordered_ids.*' => 'integer',
        ]);

        // Sequence neu durchzählen (1..n)
        DB::transaction(function () use ($payload) {
            foreach ($payload['ordered_ids'] as $i => $id) {
                DB::table('m_parameter')->where('id', $id)->update(['sequence' => $i + 1]);
            }
        });

        return response()->json(['status' => 'ok']);
    }

}


