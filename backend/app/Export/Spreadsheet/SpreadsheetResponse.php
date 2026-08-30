<?php

namespace App\Export\Spreadsheet;

use App\Helpers\FlowFilename;
use Symfony\Component\HttpFoundation\Response;

final class SpreadsheetResponse
{
    public static function download(
        SpreadsheetDocument $document,
        ?SpreadsheetWriter $writer = null,
    ): Response {
        $writer ??= new PhpSpreadsheetWriter;
        $binary = $writer->write($document);
        $filename = FlowFilename::make($document->filenameStem, 'xlsx', $document->date);

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Filename' => $filename,
            'Access-Control-Expose-Headers' => 'X-Filename',
        ]);
    }
}
