<?php

namespace App\Http\Controllers\Api;

use App\Helpers\FlowFilename;
use App\Http\Controllers\Controller;
use App\Print\GotenbergChromium;
use RuntimeException;

class PrintGotenbergTestController extends Controller
{
    public function download(GotenbergChromium $gotenberg)
    {
        if (! $gotenberg->configured()) {
            return response()->json(['error' => 'PDF-Dienst nicht erreichbar.'], 503);
        }

        try {
            $bytes = $gotenberg->convertHtml(GotenbergChromium::HELLO_WORLD_HTML);
        } catch (RuntimeException $e) {
            $unavailable = str_contains($e->getMessage(), 'nicht erreichbar')
                || str_contains($e->getMessage(), 'nicht konfiguriert');

            return response()->json(['error' => $e->getMessage()], $unavailable ? 503 : 502);
        }

        $filename = FlowFilename::make('Gotenberg_Test', 'pdf');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Filename' => $filename,
            'Access-Control-Expose-Headers' => 'X-Filename',
        ]);
    }
}
