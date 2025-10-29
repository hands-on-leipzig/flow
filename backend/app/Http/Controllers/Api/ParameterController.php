<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MParameter;
use App\Models\MParameterCondition;
use App\Models\SupportedPlan;
use App\Enums\ExploreMode;
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
                'recommended' => $option->alert_level === 1, // alert_level 1 = recommended
                'suggested' => $option->alert_level === 1, // alert_level 1 = suggested
            ];
        });
        
        return response()->json($mappedOptions);
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

        for ($e = 0; $e <= 8; $e++) {
            for ($c = 0; $c <= 1; $c++) {
                $key = "e{$e}_c{$c}";

                // Standard: alles false
                $entry = array_fill_keys($fields, ['editable' => false]);

                // Ungültige Kombinationen → alles false, fertig
                if (in_array($e, [ExploreMode::NONE->value, ExploreMode::INTEGRATED_MORNING->value, ExploreMode::INTEGRATED_AFTERNOON->value, ExploreMode::HYBRID_MORNING->value, ExploreMode::HYBRID_AFTERNOON->value]) && $c === 0) {
                    $matrix[$key] = [
                        'e_mode' => $e,
                        'c_mode' => $c,
                        'fields' => $entry,
                    ];
                    continue;
                }

                if ( $c === 1) {
               
                    switch ($e) {
                        case ExploreMode::NONE->value:
                        case ExploreMode::DECOUPLED_MORNING->value:
                        case ExploreMode::DECOUPLED_AFTERNOON->value:
                        case ExploreMode::DECOUPLED_BOTH->value:

                            foreach (['c_start_opening','c_duration_opening','c_duration_awards'] as $f) {
                                $entry[$f]['editable'] = true;  
                            }
                            break;

                        case ExploreMode::INTEGRATED_MORNING->value:
                        case ExploreMode::HYBRID_MORNING->value:
                            foreach (['g_start_opening','g_duration_opening','c_duration_awards', 'e1_duration_awards'] as $f) {
                                $entry[$f]['editable'] = true;  
                            }
                            break;


                        case ExploreMode::INTEGRATED_AFTERNOON->value:
                        case ExploreMode::HYBRID_AFTERNOON->value:
                            foreach (['c_start_opening','c_duration_opening','g_duration_awards', 'e2_duration_opening'] as $f) {
                                $entry[$f]['editable'] = true;  
                            }
                            break;

                        case ExploreMode::HYBRID_BOTH->value:
                            foreach (['g_start_opening','g_duration_opening','e1_duration_awards',
                                        'e2_duration_opening','g_duration_awards'] as $f) {
                                $entry[$f]['editable'] = true;  
                            }
                            break;


                    }
                }    

                switch ($e) {
                    case ExploreMode::DECOUPLED_MORNING->value:
                        foreach (['e1_start_opening','e1_duration_opening','e1_duration_awards'] as $f) {
                            $entry[$f]['editable'] = true;  
                        }
                        break;

                    case ExploreMode::DECOUPLED_AFTERNOON->value:
                        foreach (['e2_start_opening','e2_duration_opening','e2_duration_awards'] as $f) {
                            $entry[$f]['editable'] = true;  
                        }
                        break;

                    case ExploreMode::DECOUPLED_BOTH->value:
                        foreach (['e1_start_opening','e1_duration_opening','e1_duration_awards',
                                  'e2_start_opening','e2_duration_opening','e2_duration_awards'] as $f) {
                            $entry[$f]['editable'] = true;  
                        }
                        break;

                }

                // Determine which columns to show based on editable fields
                // Columns left to right: Gemeinsam (g), Explore Vormittag (e1), Explore Nachmittag (e2), Challenge (c)
                $columns = [];
                
                // Check each column if it has any editable fields
                if ($entry['g_start_opening']['editable'] || 
                    $entry['g_duration_opening']['editable'] || 
                    $entry['g_duration_awards']['editable']) {
                    $columns[] = 'g';
                }
                
                if ($entry['e1_start_opening']['editable'] || 
                    $entry['e1_duration_opening']['editable'] || 
                    $entry['e1_duration_awards']['editable']) {
                    $columns[] = 'e1';
                }
                
                if ($entry['e2_start_opening']['editable'] || 
                    $entry['e2_duration_opening']['editable'] || 
                    $entry['e2_duration_awards']['editable']) {
                    $columns[] = 'e2';
                }
                
                if ($entry['c_start_opening']['editable'] || 
                    $entry['c_duration_opening']['editable'] || 
                    $entry['c_duration_awards']['editable']) {
                    $columns[] = 'c';
                }
                
                $matrix[$key] = [
                    'e_mode' => $e,
                    'c_mode' => $c,
                    'fields' => $entry,
                    'columns' => $columns,
                ];
            }
        }

        return response()->json(['matrix' => $matrix]);
    }

}