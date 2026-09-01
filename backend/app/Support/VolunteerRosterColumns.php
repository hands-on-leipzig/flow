<?php

namespace App\Support;

use App\Models\EventVolunteerField;
use App\Models\EventVolunteerRoster;
use App\Models\EventVolunteerRosterDetail;
use Illuminate\Support\Collection;

final class VolunteerRosterColumns
{
    public const EXPORT_ASSIGNMENT_PAIRS = 5;

    /**
     * @var list<array{key: string, label: string, table?: bool, export?: bool, sortable?: bool, kind?: string, editor?: string}>
     */
    private const FIXED_TABLE_START = [
        ['key' => 'name', 'label' => 'Name', 'table' => true, 'sortable' => true, 'kind' => 'fixed'],
        ['key' => 'role', 'label' => 'Rolle', 'table' => true, 'sortable' => true, 'kind' => 'fixed'],
        ['key' => 'photo_consent', 'label' => 'Foto Erlaubnis', 'table' => true, 'export' => true, 'kind' => 'fixed', 'editor' => 'photo_consent', 'public_form' => false],
        ['key' => 't_shirt', 'label' => 'T-Shirt Größe', 'table' => true, 'kind' => 'fixed', 'editor' => 't_shirt'],
        ['key' => 'meal', 'label' => 'Essen', 'table' => true, 'export' => true, 'kind' => 'fixed', 'editor' => 'meal'],
    ];

    /**
     * @var list<array{key: string, label: string, table?: bool, export?: bool, kind?: string, editor?: string, public_form?: bool}>
     */
    private const FIXED_BEFORE_NOTES = [
    ];

    /**
     * @var list<array{key: string, label: string, table?: bool, export?: bool, kind?: string, editor?: string}>
     */
    private const FIXED_TABLE_END = [
        ['key' => 'notes', 'label' => 'Bemerkungen', 'table' => true, 'export' => true, 'kind' => 'fixed', 'editor' => 'text'],
    ];

    /**
     * @return Collection<int, EventVolunteerField>
     */
    public static function customFieldsForEvent(int $eventId): Collection
    {
        return EventVolunteerField::query()
            ->where('event', $eventId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function tablePayloadForEvent(int $eventId): array
    {
        $columns = self::FIXED_TABLE_START;
        foreach (self::customFieldsForEvent($eventId) as $field) {
            $columns[] = VolunteerRosterCustomFields::serializeColumn($field);
        }
        foreach (self::FIXED_BEFORE_NOTES as $column) {
            $columns[] = $column;
        }
        foreach (self::FIXED_TABLE_END as $column) {
            $columns[] = $column;
        }

        return array_values(array_map(function (array $column) {
            return [
                'key' => $column['key'],
                'label' => $column['label'],
                'sortable' => (bool) ($column['sortable'] ?? false),
                'kind' => $column['kind'] ?? 'fixed',
                'type' => $column['type'] ?? null,
                'editor' => $column['editor'] ?? null,
                'field_key' => $column['field_key'] ?? null,
                'options' => $column['options'] ?? [],
                'public_form' => $column['public_form'] ?? true,
            ];
        }, $columns));
    }

    /**
     * @return list<string>
     */
    public static function exportLabelsForEvent(int $eventId): array
    {
        return VolunteerColumnDefinition::exportLabels(self::exportDefinitionsForEvent($eventId));
    }

    /**
     * @return list<array{key: string, label: string, table?: bool, export?: bool}>
     */
    public static function exportDefinitionsForEvent(int $eventId): array
    {
        $definitions = [];
        foreach (VolunteerPersonColumns::definitions() as $column) {
            if (($column['key'] ?? '') === 'updated_at') {
                continue;
            }
            $definitions[] = array_merge($column, ['table' => false, 'export' => true]);
        }

        $definitions[] = ['key' => 'zuordnung_1_program', 'label' => 'Zuordnung 1 Programm', 'export' => true];
        $definitions[] = ['key' => 'zuordnung_1_role', 'label' => 'Zuordnung 1 Rolle', 'export' => true];
        $definitions[] = ['key' => 'photo_consent', 'label' => 'Foto Erlaubnis', 'export' => true];
        $definitions[] = ['key' => 't_shirt_cut', 'label' => 'T-Shirt Schnitt', 'export' => true];
        $definitions[] = ['key' => 't_shirt_size', 'label' => 'T-Shirt Größe', 'export' => true];
        $definitions[] = ['key' => 'meal', 'label' => 'Essen', 'export' => true];

        foreach (self::customFieldsForEvent($eventId) as $field) {
            $definitions[] = [
                'key' => 'custom:'.$field->field_key,
                'label' => $field->label,
                'export' => true,
            ];
        }

        $definitions[] = ['key' => 'notes', 'label' => 'Bemerkungen', 'export' => true];

        for ($i = 2; $i <= self::EXPORT_ASSIGNMENT_PAIRS; $i++) {
            $definitions[] = ['key' => "zuordnung_{$i}_program", 'label' => "Zuordnung {$i} Programm", 'export' => true];
            $definitions[] = ['key' => "zuordnung_{$i}_role", 'label' => "Zuordnung {$i} Rolle", 'export' => true];
        }

        return $definitions;
    }

    /**
     * @param  list<array{first_program: ?int, is_local: bool, label: string}>  $assignments
     * @param  array<int, string>  $programNames
     * @param  array<string, mixed>  $customValues
     * @param  Collection<int, EventVolunteerField>|null  $customFields  When null, loaded for the event
     * @param  array<string, string>  $mealLabelMap
     * @return list<string>
     */
    public static function exportValuesForEvent(
        int $eventId,
        EventVolunteerRoster $row,
        array $assignments,
        array $programNames,
        array $customValues,
        ?Collection $customFields = null,
        array $mealLabelMap = [],
    ): array {
        $person = $row->person;
        if (! $person) {
            return array_fill(0, count(self::exportLabelsForEvent($eventId)), '');
        }

        /** @var EventVolunteerRosterDetail|null $detail */
        $detail = $row->detail;
        $customFields ??= self::customFieldsForEvent($eventId);

        $values = VolunteerPersonColumns::exportValues($person, ['updated_at']);
        $values = array_merge($values, self::assignmentPairValues($assignments[0] ?? null, $programNames));
        $values[] = VolunteerRosterDetailFields::exportPhotoConsentLabel(
            $detail?->photo_consent !== null ? (bool) $detail->photo_consent : null
        );
        $values = array_merge($values, [
            VolunteerRosterDetailFields::exportLabel($detail?->t_shirt_cut),
            $detail?->t_shirt_size ?? '',
            VolunteerRosterDetailFields::exportMealLabel($detail?->meal, $mealLabelMap),
        ]);

        foreach ($customFields as $field) {
            $apiValue = $customValues[$field->field_key] ?? null;
            $stored = $apiValue === null ? null : (string) (is_bool($apiValue) ? ($apiValue ? '1' : '0') : $apiValue);
            $values[] = VolunteerRosterCustomFields::exportValue($field, $stored);
        }

        $values[] = $detail?->notes ?? '';

        for ($i = 1; $i < self::EXPORT_ASSIGNMENT_PAIRS; $i++) {
            $values = array_merge($values, self::assignmentPairValues($assignments[$i] ?? null, $programNames));
        }

        return $values;
    }

    /**
     * @param  array{first_program: ?int, is_local: bool, label: string}|null  $assignment
     * @param  array<int, string>  $programNames
     * @return list<string>
     */
    private static function assignmentPairValues(?array $assignment, array $programNames): array
    {
        if (! $assignment) {
            return ['', ''];
        }

        return [
            self::assignmentProgramLabel($assignment, $programNames),
            $assignment['label'],
        ];
    }

    /**
     * @param  array{first_program: ?int, is_local: bool}  $assignment
     * @param  array<int, string>  $programNames
     */
    private static function assignmentProgramLabel(array $assignment, array $programNames): string
    {
        if ($assignment['is_local']) {
            return 'Zusätzlich';
        }
        if ($assignment['first_program'] === null) {
            return 'Übergreifend';
        }

        return $programNames[$assignment['first_program']] ?? (string) $assignment['first_program'];
    }
}
