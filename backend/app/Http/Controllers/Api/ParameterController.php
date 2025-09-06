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


    public function visibility(): \Illuminate\Http\JsonResponse
    {
        // Alle 12 Felder
        $fields = [
            'c_start_opening', 'c_duration_opening', 'c_duration_awards',
            'g_start_opening', 'g_duration_opening', 'g_duration_awards',
            'e1_start_opening', 'e1_duration_opening', 'e1_duration_awards',
            'e2_start_opening', 'e2_duration_opening', 'e2_duration_awards',
        ];

        $matrix = [];

        for ($e = 0; $e <= 5; $e++) {
            for ($c = 0; $c <= 1; $c++) {
                $key = "e{$e}_c{$c}";

                // Standard: alles false
                $entry = array_fill_keys($fields, ['editable' => false]);

                // Ungültige Kombinationen → alles false, fertig
                if (in_array($e, [0,1,2]) && $c === 0) {
                    $matrix[$key] = [
                        'e_mode' => $e,
                        'c_mode' => $c,
                        'fields' => $entry,
                    ];
                    continue;
                }

                if ( $c === 1) {
               
                    switch ($e) {
                        case 0:
                        case 3:
                        case 4:
                        case 5:

                            foreach (['c_start_opening','c_duration_opening','c_duration_awards'] as $f) {
                                $entry[$f]['editable'] = true;  
                            }
                            break;

                        case 1:
                            foreach (['g_start_opening','g_duration_opening','c_duration_awards', 'e1_duration_awards'] as $f) {
                                $entry[$f]['editable'] = true;  
                            }
                            break;


                        case 2:
                            foreach (['c_start_opening','c_duration_opening','g_duration_awards', 'e2_duration_opening'] as $f) {
                                $entry[$f]['editable'] = true;  
                            }
                            break;

                    }
                }    

                switch ($e) {
                    case 3:
                        foreach (['e1_start_opening','e1_duration_opening','e1_duration_awards'] as $f) {
                            $entry[$f]['editable'] = true;  
                        }
                        break;

                    case 4:
                        foreach (['e2_start_opening','e2_duration_opening','e2_duration_awards'] as $f) {
                            $entry[$f]['editable'] = true;  
                        }
                        break;

                    case 5:
                        foreach (['e1_start_opening','e1_duration_opening','e1_duration_awards',
                                  'e2_start_opening','e2_duration_opening','e2_duration_awards'] as $f) {
                            $entry[$f]['editable'] = true;  
                        }
                        break;

                }

                // Möglicherweise noch Challenge dazu
                
                $matrix[$key] = [
                    'e_mode' => $e,
                    'c_mode' => $c,
                    'fields' => $entry,
                ];
            }
        }

        return response()->json(['matrix' => $matrix]);
    }

}