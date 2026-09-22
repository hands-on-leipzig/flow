<?php

namespace App\Http\Controllers\Api;

use App\Helpers\FlowFilename;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Print\RoleSheetAssembler;
use App\Print\RoleSheetCatalog;
use App\Print\RoleSheetTcpdfRenderer;
use App\Services\EventSlugService;
use App\Services\PdfDownloadRecorder;
use Illuminate\Http\Request;

class PrintRoleSheetController extends Controller
{
    public function __construct(
        private RoleSheetCatalog $catalog,
        private RoleSheetAssembler $assembler,
        private RoleSheetTcpdfRenderer $renderer,
        private EventSlugService $slugs,
        private PdfDownloadRecorder $downloads,
    ) {}

    public function catalog(int $eventId)
    {
        $payload = $this->catalog->forEvent($eventId);
        if ($payload === null) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        return response()->json($payload);
    }

    public function download(Request $request, int $eventId)
    {
        $payload = $this->catalog->forEvent($eventId);
        if ($payload === null) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $roleIds = $request->input('role_ids');
        if (! is_array($roleIds) || $roleIds === []) {
            return response()->json(['error' => 'Bitte mindestens eine Rolle wählen.'], 422);
        }

        $allowed = [];
        foreach ($payload['roles'] as $role) {
            $allowed[(int) $role['id']] = true;
        }

        $ids = [];
        foreach ($roleIds as $id) {
            $intId = (int) $id;
            if ($intId < 1 || ! isset($allowed[$intId])) {
                return response()->json(['error' => 'Bitte mindestens eine Rolle wählen.'], 422);
            }
            $ids[] = $intId;
        }

        $document = $this->assembler->assemble((int) $payload['plan_id'], $ids);
        $event = Event::find($eventId);
        $document['created_at'] = now('Europe/Berlin')->format('d.m.Y H:i');
        $document['public_url'] = $this->publicUrl($event);
        $document['qr_base64'] = is_string($event?->qrcode) && $event->qrcode !== ''
            ? $event->qrcode
            : null;
        $document['wifi_qr_base64'] = self::wifiQrBase64($event);
        $bytes = $this->renderer->render($document);
        $filename = FlowFilename::make('Rollenplaene', 'pdf', $event?->date);

        $this->downloads->record($eventId, 'role_sheets', $request->user()?->id);

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
