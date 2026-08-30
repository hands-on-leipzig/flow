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
     * @param  list<string>  $exceptKeys
     * @return list<mixed>
     */
    public static function exportValues(VolunteerPerson $person, array $exceptKeys = []): array
    {
        $byKey = [
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'email' => $person->email,
            'mobile' => $person->mobile ?? '',
            'organization' => $person->organization ?? '',
            'updated_at' => $person->updated_at,
        ];

        $values = [];
        foreach (self::DEFINITIONS as $definition) {
            if (! ($definition['export'] ?? false)) {
                continue;
            }
            $key = $definition['key'];
            if (in_array($key, $exceptKeys, true)) {
                continue;
            }
            $values[] = $byKey[$key] ?? '';
        }

        return $values;
    }
}
