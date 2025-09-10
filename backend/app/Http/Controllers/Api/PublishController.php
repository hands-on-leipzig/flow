<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\MSupportedPlan;
use App\Models\PlanParamValue;
use App\Models\Team;
use App\Models\TeamPlan;
use App\Models\FirstProgram;
use App\Services\PreviewMatrix;
use App\Services\GeneratePlan;
use App\Jobs\GeneratePlanJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Http\Request;

use SimpleSoftwareIO\QrCode\Facades\QrCode; // Falls nicht installiert: composer require simplesoftwareio/simple-qrcode

class PublishController extends Controller
{
    public function linkAndQRcode(int $planId): JsonResponse
    {
        // Plan → Event
        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.*')
            ->first();

        if (!$event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        // Wenn bereits gesetzt → zurückgeben
        if (!empty($event->link) && !empty($event->qrcode)) {
            return response()->json([
                'link' => $event->link,
                'qrcode' => $event->qrcode,
            ]);
        }

        // Basislink aus Region
        $region = DB::table('regional_partner')
            ->where('id', $event->regional_partner)
            ->value('region');

        if (!$region) {
            return response()->json(['error' => 'Region not found'], 404);
        }

        $baseLink = "https://flow.hands-on-technology.org/" . $region;

        // Prüfen, ob mehrere Events für diesen Regionalpartner existieren
        $eventCount = DB::table('event')
            ->where('regional_partner', $event->regional_partner)
            ->count();

        if ($eventCount > 1) {
            if (!is_null($event->event_challenge)) {
                $baseLink .= "-challenge";
            }
            if (!is_null($event->event_explore)) {
                $baseLink .= "-explore";
            }
        }

        // QR-Code als Base64 generieren (inkl. Data-URL Prefix)
        $qrcode = 'data:image/png;base64,' . base64_encode(
            QrCode::format('png')->size(200)->generate($baseLink)
        );

        // In DB speichern
        DB::table('event')
            ->where('id', $event->id)
            ->update([
                'link'   => $baseLink,
                'qrcode' => $qrcode,
            ]);

        return response()->json([
            'link' => $baseLink,
            'qrcode' => $qrcode,
        ]);
    }
}