<?php

namespace App\Export\Spreadsheet;

final class SpreadsheetColumn
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly SpreadsheetColumnType $type = SpreadsheetColumnType::String,
    ) {}
}
