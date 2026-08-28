<?php

namespace App\Support;

use App\Models\EventVolunteerRoster;
use App\Models\EventVolunteerRosterDetail;

final class VolunteerRosterColumns
{
    public const EXPORT_ASSIGNMENT_PAIRS = 5;

    /**
     * @var list<array{key: string, label: string, table?: bool, export?: bool, sortable?: bool}>
     */
    private const TABLE_DEFINITIONS = [
        ['key' => 'name', 'label' => 'Name', 'table' => true, 'sortable' => true],
        ['key' => 'role', 'label' => 'Rolle', 'table' => true, 'sortable' => true],
        ['key' => 't_shirt', 'label' => 'T-Shirt Größe', 'table' => true],
        ['key' => 'meal', 'label' => 'Essen', 'table' => true, 'export' => true],
        ['key' => 'eve_meeting', 'label' => 'Vorabendtreffen', 'table' => true, 'export' => true],
        ['key' => 'notes', 'label' => 'Bemerkungen', 'table' => true, 'export' => true],
    ];

    /**
     * @return list<array{key: string, label: string, sortable: bool}>
     */
    public static function tablePayload(): array
    {
        return VolunteerColumnDefinition::tablePayload(self::TABLE_DEFINITIONS);
    }

    /**
     * @return list<array{key: string, label: string, table?: bool, export?: bool, sortable?: bool}>
     */
    public static function exportDefinitions(): array
    {
        $definitions = array_map(
            fn (array $column) => array_merge($column, ['table' => false, 'export' => true]),
            VolunteerPersonColumns::definitions(),
        );

        $definitions[] = ['key' => 'zuordnung_1_program', 'label' => 'Zuordnung 1 Programm', 'export' => true];
        $definitions[] = ['key' => 'zuordnung_1_role', 'label' => 'Zuordnung 1 Rolle', 'export' => true];
        $definitions[] = ['key' => 't_shirt_cut', 'label' => 'T-Shirt Schnitt', 'export' => true];
        $definitions[] = ['key' => 't_shirt_size', 'label' => 'T-Shirt Größe', 'export' => true];
        $definitions[] = ['key' => 'meal', 'label' => 'Essen', 'export' => true];
        $definitions[] = ['key' => 'eve_meeting', 'label' => 'Vorabendtreffen', 'export' => true];
        $definitions[] = ['key' => 'notes', 'label' => 'Bemerkungen', 'export' => true];

        for ($i = 2; $i <= self::EXPORT_ASSIGNMENT_PAIRS; $i++) {
            $definitions[] = ['key' => "zuordnung_{$i}_program", 'label' => "Zuordnung {$i} Programm", 'export' => true];
            $definitions[] = ['key' => "zuordnung_{$i}_role", 'label' => "Zuordnung {$i} Rolle", 'export' => true];
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    public static function exportLabels(): array
    {
        return VolunteerColumnDefinition::exportLabels(self::exportDefinitions());
    }

    /**
     * @param  list<array{first_program: ?int, is_local: bool, label: string}>  $assignments
     * @param  array<int, string>  $programNames
     * @return list<string>
     */
    public static function exportValues(
        EventVolunteerRoster $row,
        array $assignments,
        array $programNames,
    ): array {
        $person = $row->person;
        if (! $person) {
            return array_fill(0, count(self::exportLabels()), '');
        }

        /** @var EventVolunteerRosterDetail|null $detail */
        $detail = $row->detail;

        $values = VolunteerPersonColumns::exportValues($person);
        $values = array_merge($values, self::assignmentPairValues($assignments[0] ?? null, $programNames));
        $values = array_merge($values, [
            VolunteerRosterDetailFields::exportLabel($detail?->t_shirt_cut),
            $detail?->t_shirt_size ?? '',
            VolunteerRosterDetailFields::exportMealLabel($detail?->meal),
            VolunteerRosterDetailFields::exportEveMeeting($detail?->eve_meeting),
            $detail?->notes ?? '',
        ]);

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
