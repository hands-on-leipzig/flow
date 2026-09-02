<?php

namespace App\Support;

final class TeamsPeopleColumns
{
    /**
     * @var list<array{key: string, label: string, export?: bool, type?: string}>
     */
    private const DEFINITIONS = [
        ['key' => 'program', 'label' => 'Programm', 'export' => true],
        ['key' => 'team_number', 'label' => 'Team-Nr', 'export' => true],
        ['key' => 'team_name', 'label' => 'Teamname', 'export' => true],
        ['key' => 'role', 'label' => 'Rolle', 'export' => true],
        ['key' => 'first_name', 'label' => 'Vorname', 'export' => true],
        ['key' => 'last_name', 'label' => 'Nachname', 'export' => true],
        ['key' => 'gender', 'label' => 'Geschlecht', 'export' => true],
        ['key' => 'birthday', 'label' => 'Geburtstag', 'export' => true],
        ['key' => 'email', 'label' => 'E-Mail', 'export' => true],
        ['key' => 'phone', 'label' => 'Telefon', 'export' => true],
        ['key' => 'organization', 'label' => 'Organisation', 'export' => true],
    ];

    /**
     * @return list<array{key: string, label: string, export?: bool, type?: string}>
     */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    public static function exportValues(array $row): array
    {
        $values = [];
        foreach (self::DEFINITIONS as $definition) {
            if (! ($definition['export'] ?? false)) {
                continue;
            }
            $values[] = $row[$definition['key']] ?? '';
        }

        return $values;
    }

    public static function formatBirthday(mixed $timestamp): string
    {
        if ($timestamp === null || $timestamp === false || $timestamp === '') {
            return '';
        }
        $ts = is_numeric($timestamp) ? (int) $timestamp : strtotime((string) $timestamp);
        if ($ts <= 0) {
            return '';
        }

        return date('d.m.Y', $ts);
    }
}
