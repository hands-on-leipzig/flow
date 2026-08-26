<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MParameter;
use App\Models\MParameterCondition;
use App\Models\SupportedPlan;
use App\Enums\ExploreMode;
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


    public function visibility(): \Illuminate\Http\JsonResponse
    {
        $fields = [
            'c_start_opening', 'c_duration_opening', 'c_duration_awards',
            'f8_start_opening', 'f8_duration_opening', 'f8_duration_awards',
            'g_start_opening', 'g_duration_opening', 'g_duration_awards',
            'e1_start_opening', 'e1_duration_opening', 'e1_duration_awards',
            'e2_start_opening', 'e2_duration_opening', 'e2_duration_awards',
        ];

        $exploreIntegratedOrOff = [
            ExploreMode::NONE->value,
            ExploreMode::INTEGRATED_MORNING->value,
            ExploreMode::INTEGRATED_AFTERNOON->value,
        ];

        $matrix = [];

        for ($e = 0; $e <= 8; $e++) {
            for ($c = 0; $c <= 1; $c++) {
                for ($f8 = 0; $f8 <= 1; $f8++) {
                    $entry = array_fill_keys($fields, ['editable' => false]);
                    $invalidLead = in_array($e, $exploreIntegratedOrOff, true) && $c === 0 && $f8 === 0;

                    if (! $invalidLead) {
                        if ($c === 1) {
                            $this->enableLeadTimes($entry, $e, 'c');
                        }
                        if ($f8 === 1) {
                            $this->enableLeadTimes($entry, $e, 'f8');
                        }
                        // Dual Challenge-shaped: awards are always joint (g_awards), same as the generator.
                        if ($c === 1 && $f8 === 1) {
                            $this->forceJointAwards($entry);
                        }
                        $this->enableExploreTimes($entry, $e);
                    }

                    $payload = [
                        'e_mode' => $e,
                        'c_mode' => $c,
                        'f8_mode' => $f8,
                        'fields' => $entry,
                        'columns' => $this->timeColumns($entry),
                    ];

                    $matrix["e{$e}_c{$c}_f8{$f8}"] = $payload;
                    if ($f8 === 0) {
                        $matrix["e{$e}_c{$c}"] = $payload;
                    }
                }
            }
        }

        return response()->json(['matrix' => $matrix]);
    }

    /**
     * Challenge-shaped opening/awards for prefix c or f8, matching Explore mode.
     *
     * @param  array<string, array{editable: bool}>  $entry
     */
    private function enableLeadTimes(array &$entry, int $e, string $prefix): void
    {
        $own = [
            "{$prefix}_start_opening",
            "{$prefix}_duration_opening",
            "{$prefix}_duration_awards",
        ];

        switch ($e) {
            case ExploreMode::NONE->value:
            case ExploreMode::DECOUPLED_MORNING->value:
            case ExploreMode::DECOUPLED_AFTERNOON->value:
            case ExploreMode::DECOUPLED_BOTH->value:
                foreach ($own as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;

            case ExploreMode::INTEGRATED_MORNING->value:
                foreach (['g_start_opening', 'g_duration_opening', "{$prefix}_duration_awards", 'e1_duration_awards'] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;

            case ExploreMode::INTEGRATED_AFTERNOON->value:
                foreach (["{$prefix}_start_opening", "{$prefix}_duration_opening", 'g_duration_awards', 'e2_duration_opening'] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;

            case ExploreMode::HYBRID_BOTH->value:
                foreach (['g_start_opening', 'g_duration_opening', 'e1_duration_awards', 'e2_duration_opening', 'g_duration_awards'] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;
        }
    }

    /**
     * When Challenge and Future are both on, Preisverleihung uses g_duration_awards (not c_/f8_).
     *
     * @param  array<string, array{editable: bool}>  $entry
     */
    private function forceJointAwards(array &$entry): void
    {
        $entry['c_duration_awards']['editable'] = false;
        $entry['f8_duration_awards']['editable'] = false;
        $entry['g_duration_awards']['editable'] = true;
    }

    /**
     * @param  array<string, array{editable: bool}>  $entry
     */
    private function enableExploreTimes(array &$entry, int $e): void
    {
        switch ($e) {
            case ExploreMode::DECOUPLED_MORNING->value:
                foreach (['e1_start_opening', 'e1_duration_opening', 'e1_duration_awards'] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;

            case ExploreMode::DECOUPLED_AFTERNOON->value:
                foreach (['e2_start_opening', 'e2_duration_opening', 'e2_duration_awards'] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;

            case ExploreMode::DECOUPLED_BOTH->value:
                foreach ([
                    'e1_start_opening', 'e1_duration_opening', 'e1_duration_awards',
                    'e2_start_opening', 'e2_duration_opening', 'e2_duration_awards',
                ] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;
        }
    }

    /**
     * @param  array<string, array{editable: bool}>  $entry
     * @return list<string>
     */
    private function timeColumns(array $entry): array
    {
        $columns = [];
        foreach (['g', 'e1', 'e2', 'c', 'f8'] as $prefix) {
            if (
                ($entry["{$prefix}_start_opening"]['editable'] ?? false)
                || ($entry["{$prefix}_duration_opening"]['editable'] ?? false)
                || ($entry["{$prefix}_duration_awards"]['editable'] ?? false)
            ) {
                $columns[] = $prefix;
            }
        }

        return $columns;
    }

}
