<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventTeamField;
use App\Models\EventTeamFieldValue;
use App\Models\Plan;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TeamDataIndex
{
    /**
     * @return array{
     *   teams: list<array<string, mixed>>,
     *   columns: list<array<string, mixed>>,
     *   collect: array{meal: bool},
     *   meal_options: list<array<string, mixed>>
     * }
     */
    public static function payloadForEvent(Event $event): array
    {
        $event->loadMissing('programs.firstProgram');
        $collectMeal = VolunteerCollectOptions::collectsMeal($event);
        $customFields = TeamDataColumns::customFieldsForEvent($event->id);
        $peopleCounts = TeamPeopleCounts::countsByTeamIdForEvent($event);

        $mealOptions = VolunteerMealOptions::optionsForEvent($event->id);
        if ($mealOptions->isEmpty()) {
            VolunteerMealOptions::bootstrapForEvent($event->id);
            $mealOptions = VolunteerMealOptions::optionsForEvent($event->id);
        }

        $teams = self::loadTeams($event);
        $fieldValuesByTeam = self::fieldValuesByTeamId($teams->pluck('id')->all(), $customFields);
        $programMeta = self::programMeta($event);

        $payload = $teams->map(function (Team $team) use (
            $event,
            $collectMeal,
            $customFields,
            $peopleCounts,
            $fieldValuesByTeam,
            $programMeta,
        ) {
            $values = $fieldValuesByTeam[$team->id] ?? collect();

            return self::serializeTeam(
                $team,
                $event->id,
                $collectMeal,
                $customFields,
                $values,
                $peopleCounts[$team->id] ?? null,
                $programMeta,
            );
        })->values()->all();

        return [
            'teams' => $payload,
            'columns' => TeamDataColumns::tablePayloadForEvent($event->id),
            'collect' => ['meal' => $collectMeal],
            'meal_options' => VolunteerMealOptions::serializeList($mealOptions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function teamPayload(Event $event, Team $team): array
    {
        $event->loadMissing('programs.firstProgram');
        $collectMeal = VolunteerCollectOptions::collectsMeal($event);
        $customFields = TeamDataColumns::customFieldsForEvent($event->id);
        $peopleCounts = TeamPeopleCounts::countsByTeamIdForEvent($event);
        $values = self::fieldValuesByTeamId([$team->id], $customFields)[$team->id] ?? collect();
        $programMeta = self::programMeta($event);
        $loaded = self::loadTeams($event)->firstWhere('id', $team->id) ?? $team;

        return self::serializeTeam(
            $loaded,
            $event->id,
            $collectMeal,
            $customFields,
            $values,
            $peopleCounts[$team->id] ?? null,
            $programMeta,
        );
    }

    /**
     * @return Collection<int, Team>
     */
    public static function loadTeams(Event $event): Collection
    {
        $planId = null;
        if (Schema::hasTable('plan')) {
            $plan = Plan::query()->where('event', $event->id)->first();
            $planId = $plan?->id;
        }

        $query = Team::query()
            ->where('team.event', $event->id)
            ->select('team.*');

        if ($planId && Schema::hasTable('team_plan')) {
            $query->leftJoin('team_plan', function ($join) use ($planId) {
                $join->on('team_plan.team', '=', 'team.id')
                    ->where('team_plan.plan', '=', $planId);
            })->addSelect('team_plan.team_number_plan');
        } else {
            $query->addSelect(DB::raw('NULL as team_number_plan'));
        }

        $teams = $query->get();
        $programMeta = self::programMeta($event);

        return $teams->sort(function (Team $a, Team $b) use ($programMeta) {
            $seqA = $programMeta['sequences'][(int) $a->first_program] ?? 9999;
            $seqB = $programMeta['sequences'][(int) $b->first_program] ?? 9999;
            if ($seqA !== $seqB) {
                return $seqA <=> $seqB;
            }

            $planA = $a->team_number_plan !== null ? (int) $a->team_number_plan : PHP_INT_MAX;
            $planB = $b->team_number_plan !== null ? (int) $b->team_number_plan : PHP_INT_MAX;
            if ($planA !== $planB) {
                return $planA <=> $planB;
            }

            return strcasecmp((string) $a->name, (string) $b->name);
        })->values();
    }

    /**
     * @return array{labels: array<int, string>, sequences: array<int, int>}
     */
    public static function programMeta(Event $event): array
    {
        $labels = [];
        $sequences = [];

        foreach ($event->programs as $program) {
            $firstProgramId = (int) ($program->first_program ?? 0);
            if ($firstProgramId <= 0) {
                continue;
            }
            $labels[$firstProgramId] = (string) ($program->display_name ?? ProgramCatalog::displayName($program->name));
            $sequences[$firstProgramId] = (int) ($program->firstProgram?->sequence ?? 9999);
        }

        if ($labels === [] && Schema::hasTable('m_first_program')) {
            $rows = DB::table('m_first_program')->get(['id', 'name', 'sequence']);
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $labels[$id] = ProgramCatalog::displayName($row->name);
                $sequences[$id] = (int) ($row->sequence ?? 9999);
            }
        }

        return ['labels' => $labels, 'sequences' => $sequences];
    }

    /**
     * @param  list<int>  $teamIds
     * @param  Collection<int, EventTeamField>  $fields
     * @return array<int, Collection<int, EventTeamFieldValue>>
     */
    private static function fieldValuesByTeamId(array $teamIds, Collection $fields): array
    {
        if ($teamIds === [] || $fields->isEmpty()) {
            return [];
        }

        $rows = EventTeamFieldValue::query()
            ->whereIn('team', $teamIds)
            ->whereIn('event_team_field', $fields->pluck('id'))
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->team] ??= collect();
            $grouped[$row->team]->push($row);
        }

        return $grouped;
    }

    /**
     * @param  Collection<int, EventTeamFieldValue>  $values
     * @param  array{labels: array<int, string>, sequences: array<int, int>}  $programMeta
     * @return array<string, mixed>
     */
    private static function serializeTeam(
        Team $team,
        int $eventId,
        bool $collectMeal,
        Collection $customFields,
        Collection $values,
        ?int $peopleCount,
        array $programMeta,
    ): array {
        $firstProgramId = (int) $team->first_program;
        $custom = TeamDataCustomFields::apiValuesForTeam($customFields, $values);

        $customTouched = [];
        $valuesByFieldId = $values->keyBy('event_team_field');
        foreach ($customFields as $field) {
            if (in_array($field->type, ['boolean', 'select'], true)) {
                $customTouched[$field->field_key] = $valuesByFieldId->has($field->id);
            }
        }

        $payload = [
            'id' => $team->id,
            'name' => $team->name,
            'team_number_hot' => $team->team_number_hot,
            'team_number_plan' => $team->team_number_plan !== null ? (int) $team->team_number_plan : null,
            'first_program' => $firstProgramId ?: null,
            'program_label' => $programMeta['labels'][$firstProgramId] ?? '',
            'people_count' => $peopleCount,
            'custom' => $custom,
            'touched' => [
                'meal' => TeamMealCounts::isTouched($team->id),
                'custom' => $customTouched,
            ],
        ];

        if ($collectMeal) {
            $payload['meals'] = TeamMealCounts::mapForTeamWithCatalog($team->id, $eventId);
        }

        return $payload;
    }
}
