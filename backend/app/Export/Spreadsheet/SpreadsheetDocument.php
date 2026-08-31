<?php

namespace App\Export\Spreadsheet;

use DateTimeInterface;

final class SpreadsheetDocument
{
    /**
     * @param  list<SpreadsheetSheet>  $sheets
     */
    public function __construct(
        public readonly string $filenameStem,
        public readonly DateTimeInterface|string|null $date,
        public readonly array $sheets,
        public readonly string $subject = '',
    ) {}
}
