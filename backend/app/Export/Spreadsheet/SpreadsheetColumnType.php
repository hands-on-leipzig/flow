<?php

namespace App\Export\Spreadsheet;

enum SpreadsheetColumnType: string
{
    case String = 'string';
    case Number = 'number';
    case Bool = 'bool';
    case Date = 'date';

    public static function fromDefinition(mixed $type): self
    {
        if ($type instanceof self) {
            return $type;
        }

        return match ((string) ($type ?? 'string')) {
            'number' => self::Number,
            'bool', 'boolean' => self::Bool,
            'date', 'datetime' => self::Date,
            default => self::String,
        };
    }
}
