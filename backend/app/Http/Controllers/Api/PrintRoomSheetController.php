<?php

namespace App\Http\Controllers\Api;

use App\Helpers\FlowFilename;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Print\RoomSheetAssembler;
use App\Print\RoomSheetTcpdfRenderer;
use App\Services\EventSlugService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrintRoomSheetController extends Controller
{
    public function __construct(
        private RoomSheetAssembler $assembler,
        private RoomSheetTcpdfRenderer $renderer,
        private EventSlugService $slugs,
    ) {}

    public function download(Request $request, int $eventId)
    {
        $planId = DB::table('plan')->where('event', $eventId)->value('id');
        if ($planId === null) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $document = $this->assembler->assemble((int) $planId);
        if ($document === null) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $event = Event::find($eventId);
        $document['created_at'] = now('Europe/Berlin')->format('d.m.Y H:i');
        $document['public_url'] = $this->publicUrl($event);
        $document['qr_base64'] = is_string($event?->qrcode) && $event->qrcode !== ''
            ? $event->qrcode
            : null;
        $bytes = $this->renderer->render($document);
        $filename = FlowFilename::make('Raumplaene', 'pdf', $event?->date);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Filename' => $filename,
            'Access-Control-Expose-Headers' => 'X-Filename',
        ]);
    }

    private function publicUrl(?Event $event): string
    {
        if (! $event) {
            return '';
        }
        $link = trim((string) ($event->link ?? ''));
        if ($link !== '') {
            return $link;
        }

        return (string) ($this->slugs->url($event) ?? '');
    }
}
