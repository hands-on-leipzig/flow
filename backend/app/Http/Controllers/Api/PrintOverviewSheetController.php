<?php

namespace App\Http\Controllers\Api;

use App\Helpers\FlowFilename;
use App\Http\Controllers\Controller;
use App\Print\GotenbergChromium;
use App\Print\OverviewSheetAudience;
use App\Print\OverviewSheetPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrintOverviewSheetController extends Controller
{
    public function __construct(
        private GotenbergChromium $gotenberg,
        private OverviewSheetPdf $pdf,
    ) {}

    public function start(Request $request, int $eventId)
    {
        $planId = DB::table('plan')->where('event', $eventId)->value('id');
        if ($planId === null) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $roleId = $request->has('role_id')
            ? (int) $request->input('role_id')
            : OverviewSheetAudience::DEFAULT_ROLE_ID;
        if (! OverviewSheetAudience::allows($roleId)) {
            return response()->json(['error' => 'Kein Publikum-Plan für diese Auswahl.'], 422);
        }

        $paper = strtolower(trim((string) $request->input('paper', 'a4')));
        if ($paper === '') {
            $paper = 'a4';
        }
        if (! in_array($paper, ['a4', 'a3'], true)) {
            return response()->json(['error' => 'Ungültiges Format.'], 422);
        }

        if (! $this->gotenberg->configured()) {
            return response()->json(['error' => 'PDF-Dienst nicht erreichbar.'], 503);
        }

        $id = (string) Str::uuid();
        $date = DB::table('event')->where('id', $eventId)->value('date');
        $filename = FlowFilename::make(
            $paper === 'a3' ? 'Uebersichtsplan_A3' : 'Uebersichtsplan',
            'pdf',
            $date,
        );

        $this->pdf->remember($id, [
            'status' => 'pending',
            'event_id' => $eventId,
            'plan_id' => (int) $planId,
            'role_id' => $roleId,
            'paper' => $paper,
            'filename' => $filename,
        ]);
        $this->pdf->spawn($id);

        return response()->json([
            'id' => $id,
            'filename' => $filename,
        ], 202);
    }

    public function show(int $eventId, string $jobId)
    {
        $payload = $this->pdf->payload($jobId);
        if (! is_array($payload) || (int) ($payload['event_id'] ?? 0) !== $eventId) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $status = (string) ($payload['status'] ?? '');
        if ($status === 'pending') {
            return response()->json(['status' => 'pending'], 202);
        }
        if ($status === 'failed') {
            return response()->json([
                'error' => (string) ($payload['error'] ?? 'PDF-Erzeugung fehlgeschlagen.'),
            ], 502);
        }

        $bytes = base64_decode((string) ($payload['pdf'] ?? ''), true);
        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF')) {
            return response()->json(['error' => 'PDF-Erzeugung fehlgeschlagen.'], 502);
        }

        $filename = (string) ($payload['filename'] ?? FlowFilename::make('Uebersichtsplan', 'pdf'));

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Filename' => $filename,
            'Access-Control-Expose-Headers' => 'X-Filename',
        ]);
    }
}
