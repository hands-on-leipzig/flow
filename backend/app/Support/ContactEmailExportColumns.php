<?php

namespace App\Support;

use App\Export\Spreadsheet\SpreadsheetColumn;
use App\Export\Spreadsheet\SpreadsheetColumnType;

final class ContactEmailExportColumns
{
    /**
     * @var list<array{key: string, label: string}>
     */
    private const DEFINITIONS = [
        ['key' => 'first_name', 'label' => 'Vorname'],
        ['key' => 'last_name', 'label' => 'Nachname'],
        ['key' => 'email', 'label' => 'E-Mail'],
    ];

    /**
     * @return list<SpreadsheetColumn>
     */
    public static function spreadsheetColumns(): array
    {
        $columns = [];
        foreach (self::DEFINITIONS as $definition) {
            $columns[] = new SpreadsheetColumn(
                $definition['key'],
                $definition['label'],
                SpreadsheetColumnType::String,
            );
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public static function exportValues(array $row): array
    {
        $values = [];
        foreach (self::DEFINITIONS as $definition) {
            $values[] = (string) ($row[$definition['key']] ?? '');
        }

        return $values;
    }
}
