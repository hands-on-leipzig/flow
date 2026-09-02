<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventTeamField;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TeamDataColumns
{
    /**
     * @return Collection<int, EventTeamField>
     */
    public static function customFieldsForEvent(int $eventId): Collection
    {
        return EventTeamField::query()
            ->where('event', $eventId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{meal: bool}
     */
    private static function collectFlagsForEventId(int $eventId): array
    {
        if (! Schema::hasTable('event')) {
            return ['meal' => true];
        }

        $row = DB::table('event')->where('id', $eventId)->first();
        if (! $row) {
            return ['meal' => true];
        }

        $meal = $row->collect_meal ?? $row->volunteer_collect_meal ?? true;

        return ['meal' => (bool) $meal];
    }

    public static function collectsMealForEventId(int $eventId): bool
    {
        return self::collectFlagsForEventId($eventId)['meal'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function tablePayloadForEvent(int $eventId): array
    {
        $collectMeal = self::collectsMealForEventId($eventId);
        $columns = [];

        if ($collectMeal) {
            $columns[] = [
                'key' => 'meal',
                'label' => 'Essen',
                'kind' => 'meal',
                'editor' => 'meal_counts',
                'sortable' => false,
            ];
        }

        foreach (self::customFieldsForEvent($eventId) as $field) {
            $columns[] = self::serializeColumn($field);
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeColumn(EventTeamField $field): array
    {
        $editor = match ($field->type) {
            'text' => 'text',
            'number' => 'number',
            'boolean', 'select' => 'count_set',
            default => 'text',
        };

        return [
            'key' => 'custom:'.$field->field_key,
            'label' => $field->label,
            'kind' => 'custom',
            'type' => $field->type,
            'editor' => $editor,
            'field_key' => $field->field_key,
            'options' => $field->type === 'select' ? ($field->options ?? []) : [],
            'boolean_keys' => $field->type === 'boolean' ? TeamDataCustomFields::BOOLEAN_KEYS : [],
            'public_form' => (bool) ($field->public_form ?? false),
            'sortable' => false,
        ];
    }

    /**
     * @return list<array{key: string, label: string, export: bool}>
     */
    public static function exportDefinitionsForEvent(int $eventId): array
    {
        $definitions = [
            ['key' => 'program_label', 'label' => 'Programm', 'export' => true],
            ['key' => 'team_name', 'label' => 'Teamname', 'export' => true],
            ['key' => 'team_number_plan', 'label' => 'Nr', 'export' => true],
            ['key' => 'people_count', 'label' => 'Personen', 'export' => true],
        ];

        if (self::collectsMealForEventId($eventId)) {
            $mealOptions = VolunteerMealOptions::optionsForEvent($eventId);
            if ($mealOptions->isEmpty()) {
                VolunteerMealOptions::bootstrapForEvent($eventId);
                $mealOptions = VolunteerMealOptions::optionsForEvent($eventId);
            }
            foreach ($mealOptions as $option) {
                $definitions[] = [
                    'key' => 'meal:'.$option->value,
                    'label' => 'Essen: '.$option->label,
                    'export' => true,
                ];
            }
        }

        foreach (self::customFieldsForEvent($eventId) as $field) {
            if ($field->type === 'boolean') {
                foreach (['unknown' => '?', 'yes' => 'Ja', 'no' => 'Nein'] as $bKey => $bLabel) {
                    $definitions[] = [
                        'key' => 'custom:'.$field->field_key.':'.$bKey,
                        'label' => $field->label.': '.$bLabel,
                        'export' => true,
                    ];
                }
                continue;
            }
            if ($field->type === 'select') {
                foreach ($field->options ?? [] as $option) {
                    $value = (string) ($option['value'] ?? '');
                    $label = (string) ($option['label'] ?? $value);
                    if ($value === '') {
                        continue;
                    }
                    $definitions[] = [
                        'key' => 'custom:'.$field->field_key.':'.$value,
                        'label' => $field->label.': '.$label,
                        'export' => true,
                    ];
                }
                continue;
            }
            $definitions[] = [
                'key' => 'custom:'.$field->field_key,
                'label' => $field->label,
                'export' => true,
            ];
        }

        return $definitions;
    }

    /**
     * @param  list<array{key: string, label: string, export: bool}>  $definitions
     * @param  array<string, mixed>  $team
     * @return list<mixed>
     */
    public static function exportRowValues(array $definitions, array $team): array
    {
        $values = [];
        foreach ($definitions as $definition) {
            if (! ($definition['export'] ?? false)) {
                continue;
            }
            $key = (string) $definition['key'];
            $values[] = self::exportCellValue($key, $team);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $team
     */
    private static function exportCellValue(string $key, array $team): mixed
    {
        if ($key === 'program_label') {
            return $team['program_label'] ?? '';
        }
        if ($key === 'team_name') {
            return $team['name'] ?? '';
        }
        if ($key === 'team_number_plan') {
            return $team['team_number_plan'] ?? '';
        }
        if ($key === 'people_count') {
            $count = $team['people_count'] ?? null;

            return $count === null ? '' : (int) $count;
        }
        if (str_starts_with($key, 'meal:')) {
            $mealValue = substr($key, 5);
            $meals = is_array($team['meals'] ?? null) ? $team['meals'] : [];

            return (int) ($meals[$mealValue] ?? 0);
        }
        if (! str_starts_with($key, 'custom:')) {
            return '';
        }

        $rest = substr($key, 7);
        $parts = explode(':', $rest, 2);
        $fieldKey = $parts[0];
        $custom = is_array($team['custom'] ?? null) ? $team['custom'] : [];
        $value = $custom[$fieldKey] ?? null;

        if (count($parts) === 1) {
            return $value === null ? '' : $value;
        }

        $subKey = $parts[1];
        if (! is_array($value)) {
            return 0;
        }

        return (int) ($value[$subKey] ?? 0);
    }

    public static function collectsMeal(Event $event): bool
    {
        return VolunteerCollectOptions::collectsMeal($event);
    }
}
