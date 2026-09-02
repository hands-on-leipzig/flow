<?php

namespace App\Services;

use App\Enums\FirstProgram;
use App\Models\MCeremony;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use App\Support\TimeColumnVisibility;
use Illuminate\Support\Facades\DB;

final class CeremonyTimesService
{
    public const CATALOG_INCOMPLETE_MESSAGE =
        'Zeiten können nicht geladen werden — die Zeremonie-Konfiguration (m_ceremonies) fehlt oder ist unvollständig.';

    /**
     * @return array<string, mixed>
     */
    public function forPlan(int $planId): array
    {
        if (MCeremony::query()->count() === 0) {
            return $this->catalogIncompletePayload();
        }

        if ($this->planHasUncataloguedCeremonies($planId)) {
            return $this->catalogIncompletePayload();
        }

        $params = PlanParameter::load($planId);
        $eventId = (int) (DB::table('plan')->where('id', $planId)->value('event') ?? 0);
        $eMode = (int) $params->get('e_mode', 0);
        $cMode = (int) $params->get('c_mode', 0);
        $f8Mode = (int) $params->get('f8_mode', 0);
        $visibilityFields = TimeColumnVisibility::fieldsForModes($eMode, $cMode, $f8Mode);

        $paramValues = $this->planParameterValues($planId);

        $rows = DB::table('activity as a')
            ->join('activity_group as ag', 'ag.id', '=', 'a.activity_group')
            ->join('m_activity_type_detail as atd', 'atd.id', '=', 'a.activity_type_detail')
            ->join('m_ceremonies as mc', 'mc.activity_type_detail', '=', 'atd.id')
            ->leftJoin('m_parameter as sp', 'sp.id', '=', 'mc.start_parameter')
            ->join('m_parameter as dp', 'dp.id', '=', 'mc.duration_parameter')
            ->where('ag.plan', $planId)
            ->orderBy('a.start')
            ->get([
                'a.id as activity_id',
                'atd.code',
                'atd.name as label',
                'atd.first_program',
                'a.explore_group',
                'a.start',
                'a.end',
                'mc.kind',
                'mc.start_parameter as start_parameter_id',
                'sp.name as start_parameter_name',
                'mc.duration_parameter as duration_parameter_id',
                'dp.name as duration_parameter_name',
            ]);

        $attachedPrograms = $this->attachedProgramsForEvent($eventId);

        $ceremonies = [];
        foreach ($rows as $row) {
            $durationMin = max(0, (int) ((strtotime((string) $row->end) - strtotime((string) $row->start)) / 60));
            $startEditable = $row->start_parameter_id !== null
                && ($visibilityFields[$row->start_parameter_name]['editable'] ?? false);

            $ceremonies[] = [
                'activity_id' => (int) $row->activity_id,
                'code' => (string) $row->code,
                'kind' => (string) $row->kind,
                'label' => (string) $row->label,
                'explore_group' => $row->explore_group !== null ? (int) $row->explore_group : null,
                'start' => (string) $row->start,
                'duration_min' => $durationMin,
                'programs' => $this->programsForCeremony($row, $attachedPrograms),
                'start_editable' => $startEditable,
                'start_parameter_id' => $row->start_parameter_id !== null ? (int) $row->start_parameter_id : null,
                'duration_parameter_id' => (int) $row->duration_parameter_id,
                'duration_value' => isset($paramValues[$row->duration_parameter_name])
                    ? (int) $paramValues[$row->duration_parameter_name]
                    : $durationMin,
            ];
        }

        return [
            'catalog_incomplete' => false,
            'ceremonies' => $ceremonies,
        ];
    }

    private function planHasUncataloguedCeremonies(int $planId): bool
    {
        $catalogTypeIds = MCeremony::query()->pluck('activity_type_detail')->all();
        $catalogLookup = array_fill_keys($catalogTypeIds, true);

        $planRows = DB::table('activity as a')
            ->join('activity_group as ag', 'ag.id', '=', 'a.activity_group')
            ->join('m_activity_type_detail as atd', 'atd.id', '=', 'a.activity_type_detail')
            ->where('ag.plan', $planId)
            ->select(['atd.id as activity_type_detail', 'atd.code'])
            ->distinct()
            ->get();

        foreach ($planRows as $row) {
            if (! $this->isOneDayCeremonyCode((string) $row->code)) {
                continue;
            }
            if (! isset($catalogLookup[(int) $row->activity_type_detail])) {
                return true;
            }
        }

        return false;
    }

    private function isOneDayCeremonyCode(string $code): bool
    {
        if ($code === 'c_opening_day_1') {
            return false;
        }

        return str_ends_with($code, '_opening') || str_ends_with($code, '_awards');
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogIncompletePayload(): array
    {
        return [
            'catalog_incomplete' => true,
            'error' => self::CATALOG_INCOMPLETE_MESSAGE,
            'ceremonies' => [],
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function planParameterValues(int $planId): array
    {
        $rows = DB::table('plan_param_value as ppv')
            ->join('m_parameter as mp', 'mp.id', '=', 'ppv.parameter')
            ->where('ppv.plan', $planId)
            ->get(['mp.name', 'ppv.set_value', 'mp.value as default_value']);

        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row->name] = $row->set_value ?? $row->default_value;
        }

        return $values;
    }

    /**
     * @return list<array{id: int, name: string, display_name: string|null, color_hex: string|null}>
     */
    private function attachedProgramsForEvent(int $eventId): array
    {
        if ($eventId <= 0) {
            return [];
        }

        $ids = ProgramPresence::attachedProgramIds($eventId);
        if ($ids === []) {
            return [];
        }

        return DB::table('m_first_program')
            ->whereIn('id', $ids)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['id', 'name', 'display_name', 'color_hex'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'display_name' => $row->display_name !== null ? (string) $row->display_name : null,
                'color_hex' => $row->color_hex !== null ? (string) $row->color_hex : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  object{first_program: int|null}  $row
     * @param  list<array{id: int, name: string, display_name: string|null, color_hex: string|null}>  $attachedPrograms
     * @return list<array{id: int, name: string, display_name: string|null, color_hex: string|null}>
     */
    private function programsForCeremony(object $row, array $attachedPrograms): array
    {
        if ($row->first_program === null || (int) $row->first_program === FirstProgram::JOINT->value) {
            return $attachedPrograms;
        }

        $programId = (int) $row->first_program;
        foreach ($attachedPrograms as $program) {
            if ($program['id'] === $programId) {
                return [$program];
            }
        }

        $fallback = DB::table('m_first_program')
            ->where('id', $programId)
            ->first(['id', 'name', 'display_name', 'color_hex']);

        if ($fallback === null) {
            return [];
        }

        return [[
            'id' => (int) $fallback->id,
            'name' => (string) $fallback->name,
            'display_name' => $fallback->display_name !== null ? (string) $fallback->display_name : null,
            'color_hex' => $fallback->color_hex !== null ? (string) $fallback->color_hex : null,
        ]];
    }
}
