<?php

namespace App\Http\Controllers\Api;

use App\Helpers\FlowFilename;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Print\TeamlisteAssembler;
use App\Print\TeamlisteTcpdfRenderer;
use App\Services\EventSlugService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrintTeamlisteController extends Controller
{
    public function __construct(
        private TeamlisteAssembler $assembler,
        private TeamlisteTcpdfRenderer $renderer,
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
        $document['wifi_qr_base64'] = self::wifiQrBase64($event);
        $bytes = $this->renderer->render($document);
        $filename = FlowFilename::make('Teamliste', 'pdf', $event?->date);

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

    private static function wifiQrBase64(?Event $event): ?string
    {
        if (! $event) {
            return null;
        }
        if (! is_string($event->wifi_ssid) || trim($event->wifi_ssid) === '') {
            return null;
        }
        if (! is_string($event->wifi_qrcode) || $event->wifi_qrcode === '') {
            return null;
        }

        return $event->wifi_qrcode;
    }
}
