<?php

namespace App\Export\Spreadsheet;

interface SpreadsheetWriter
{
    /**
     * @return string Binary .xlsx contents
     */
    public function write(SpreadsheetDocument $document): string;
}
