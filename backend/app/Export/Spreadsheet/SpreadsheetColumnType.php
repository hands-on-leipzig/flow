<?php

namespace App\Export\Spreadsheet;

enum SpreadsheetColumnType: string
{
    case String = 'string';
    case Number = 'number';
    case Bool = 'bool';
    case Date = 'date';
}
