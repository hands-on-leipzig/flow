<?php

namespace App\Http\Controllers\Api;

use App\Helpers\FlowFilename;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Print\RoleSheetAssembler;
use App\Print\RoleSheetCatalog;
use App\Print\RoleSheetTcpdfRenderer;
use Illuminate\Http\Request;

class PrintRoleSheetController extends Controller
{
    public function __construct(
        private RoleSheetCatalog $catalog,
        private RoleSheetAssembler $assembler,
        private RoleSheetTcpdfRenderer $renderer,
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
        $bytes = $this->renderer->render($document);

        $event = Event::find($eventId);
        $filename = FlowFilename::make('Rollenplaene', 'pdf', $event?->date);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Filename' => $filename,
            'Access-Control-Expose-Headers' => 'X-Filename',
        ]);
    }
}
