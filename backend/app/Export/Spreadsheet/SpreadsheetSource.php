<?php

namespace App\Export\Spreadsheet;

interface SpreadsheetSource
{
    public function document(): SpreadsheetDocument;
}
