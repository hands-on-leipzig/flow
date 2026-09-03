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
        $columns = [
            [
                'key' => 'photo_consent',
                'label' => 'Fotoerlaubnis',
                'kind' => 'photo',
                'editor' => 'count_set',
                'boolean_keys' => TeamPhotoCounts::BUCKETS,
                'sortable' => false,
            ],
        ];

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
            'boolean' => 'boolean',
            'select' => 'select',
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
            'public_form' => (bool) ($field->public_form ?? false),
            'check_in_show' => (bool) ($field->check_in_show ?? false),
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
            ['key' => 'team_number_hot', 'label' => 'Nr', 'export' => true],
            ['key' => 'team_name', 'label' => 'Teamname', 'export' => true],
            ['key' => 'organization', 'label' => 'Organisation', 'export' => true],
            ['key' => 'people_count', 'label' => 'Personen', 'export' => true],
        ];

        foreach (['unknown' => '?', 'yes' => 'Ja', 'no' => 'Nein'] as $bucket => $label) {
            $definitions[] = [
                'key' => 'photo_consent:'.$bucket,
                'label' => 'Fotoerlaubnis: '.$label,
                'export' => true,
            ];
        }

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
        if ($key === 'team_number_hot') {
            return $team['team_number_hot'] ?? '';
        }
        if ($key === 'organization') {
            return $team['organization'] ?? '';
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
        if (str_starts_with($key, 'photo_consent:')) {
            $bucket = substr($key, 14);
            $photo = is_array($team['photo_consent'] ?? null) ? $team['photo_consent'] : [];

            return (int) ($photo[$bucket] ?? 0);
        }
        if (! str_starts_with($key, 'custom:')) {
            return '';
        }

        $fieldKey = substr($key, 7);
        $custom = is_array($team['custom'] ?? null) ? $team['custom'] : [];
        $value = $custom[$fieldKey] ?? null;

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }

        return $value;
    }

    public static function collectsMeal(Event $event): bool
    {
        return VolunteerCollectOptions::collectsMeal($event);
    }
}
