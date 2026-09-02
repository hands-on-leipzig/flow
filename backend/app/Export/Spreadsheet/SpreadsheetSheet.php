<?php

namespace App\Export\Spreadsheet;

final class SpreadsheetSheet
{
    /**
     * @param  list<SpreadsheetColumn>  $columns
     * @param  iterable<int, list<mixed>>  $rows
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public readonly iterable $rows,
    ) {}
}
