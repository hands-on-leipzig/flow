<?php

namespace App\Support;

use App\Models\VolunteerPerson;

final class VolunteerPersonColumns
{
    /**
     * @var list<array{key: string, label: string, table?: bool, export?: bool, sortable?: bool}>
     */
    private const DEFINITIONS = [
        ['key' => 'first_name', 'label' => 'Vorname', 'table' => true, 'export' => true, 'sortable' => true],
        ['key' => 'last_name', 'label' => 'Nachname', 'table' => true, 'export' => true, 'sortable' => true],
        ['key' => 'email', 'label' => 'E-Mail', 'table' => true, 'export' => true],
        ['key' => 'mobile', 'label' => 'Mobil', 'table' => true, 'export' => true],
        ['key' => 'organization', 'label' => 'Organisation', 'table' => true, 'export' => true],
        ['key' => 'updated_at', 'label' => 'Letzte Änderung', 'table' => true, 'export' => true, 'type' => 'datetime'],
    ];

    /**
     * @return list<array{key: string, label: string, table?: bool, export?: bool, sortable?: bool}>
     */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool}>
     */
    public static function tablePayload(): array
    {
        return VolunteerColumnDefinition::tablePayload(self::DEFINITIONS);
    }

    /**
     * @return list<string>
     */
    public static function exportLabels(): array
    {
        return VolunteerColumnDefinition::exportLabels(self::DEFINITIONS);
    }

    /**
     * @return list<mixed>
     */
    public static function exportValues(VolunteerPerson $person): array
    {
        return [
            $person->first_name,
            $person->last_name,
            $person->email,
            $person->mobile ?? '',
            $person->organization ?? '',
            $person->updated_at,
        ];
    }
}
