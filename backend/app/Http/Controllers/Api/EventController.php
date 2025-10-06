<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MSeason;
use App\Models\RegionalPartner;
use App\Models\Slide;
use App\Models\TableEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Encoding\Encoding;


class EventController extends Controller
{
    public function getEvent($id)
    {
        $event = Event::with(['seasonRel', 'levelRel', 'tableNames'])->findOrFail($id);
        $event->wifi_password = isset($event->wifi_password) ? Crypt::decryptString($event->wifi_password) : "";

        return response()->json($event);
    }

    public function getSelectableEvents()
    {
        $user = Auth::user();
        $season = MSeason::latest('year')->first();

        // Get user roles from JWT token
        $roles = $user->getRoles();
        $isAdmin = in_array('flow-admin', $roles) || in_array('flow_admin', $roles);

        if ($isAdmin) {
            // Admin users can see all events
            $regionalPartners = RegionalPartner::whereHas('events', function ($query) use ($season) {
                    $query->where('season', $season->id);
                })
                ->with(['events' => function ($query) use ($season) {
                    $query->where('season', $season->id)
                        ->orderBy('date')
                        ->with(['seasonRel', 'levelRel']);
                }])
                ->orderBy('name')
                ->get();
        } else {
            // Non-admin users can only see their regional partner events
            $regionalPartners = $user->regionalPartners()
                ->whereHas('events', function ($query) use ($season) {
                    $query->where('season', $season->id);
                })
                ->with(['events' => function ($query) use ($season) {
                    $query->where('season', $season->id)
                        ->orderBy('date')
                        ->with(['seasonRel', 'levelRel']);
                }])
                ->get();
        }
        ini_set('max_execution_time', 300);
        return $regionalPartners->map(function ($rp) {
            return [
                'regional_partner' => [
                    'id' => $rp->id,
                    'name' => $rp->name,
                    'region' => $rp->region,
                ],
                'events' => $rp->events->map(function ($event) {
                    return [
                        'id' => $event->id,
                        'name' => $event->name,
                        'date' => $event->date,
                        'slug' => $event->slug,
                        'season' => [
                            'id' => $event->seasonRel?->id,
                            'name' => $event->seasonRel?->name,
                            'year' => $event->seasonRel?->year,
                        ],
                        'level' => [
                            'id' => $event->levelRel?->id,
                            'name' => $event->levelRel?->name,
                        ],
                        'event_explore' => $event->event_explore,
                        'event_challenge' => $event->event_challenge,
                    ];
                }),
            ];
        });
    }

    public function update(Request $request, int $eventId)
    {
        $updatableFields = ['wifi_ssid', 'wifi_password', 'wifi_instruction'];
        $data = $request->only($updatableFields);

        // Passwort verschlüsseln
        if (!empty($data['wifi_password'])) {
            $data['wifi_password'] = Crypt::encryptString($data['wifi_password']);
        }

        // Update nur für dieses Event
        DB::table('event')->where('id', $eventId)->update($data);

        // QR-Code nur erzeugen, wenn SSID oder Passwort geändert wurden
        if (!empty($data['wifi_ssid']) || !empty($data['wifi_password'])) {
            $event = DB::table('event')
                ->where('id', $eventId)
                ->select('wifi_ssid', 'wifi_password')
                ->first();

            if ($event) {
                // Passwort entschlüsseln (oder unverschlüsselt übernehmen)
                try {
                    $wifiPassword = Crypt::decryptString($event->wifi_password);
                } catch (\Exception $e) {
                    $wifiPassword = $event->wifi_password;
                }

                if (!empty($wifiPassword)) {
                    $wifiQrContent = "WIFI:T:WPA;S:{$event->wifi_ssid};P:{$wifiPassword};;";
                } else {
                    $wifiQrContent = "WIFI:T:nopass;S:{$event->wifi_ssid};;";
                }

                $wifiQr = new \Endroid\QrCode\QrCode(
                    $wifiQrContent,
                    new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
                    \Endroid\QrCode\ErrorCorrectionLevel::High,
                    300,
                    10,
                    \Endroid\QrCode\RoundBlockSizeMode::Margin,
                    new \Endroid\QrCode\Color\Color(0, 0, 0),
                    new \Endroid\QrCode\Color\Color(255, 255, 255)
                );


                $writer = new \Endroid\QrCode\Writer\PngWriter();
                $wifiLogoPath = public_path('flow/wifi.png');
                $wifiLogo = file_exists($wifiLogoPath)
                    ? new \Endroid\QrCode\Logo\Logo($wifiLogoPath, 100)
                    : null;

                $wifiResult = $writer->write($wifiQr, $wifiLogo);
                $wifiQrcodeRaw = base64_encode($wifiResult->getString());

                DB::table('event')
                    ->where('id', $eventId)
                    ->update(['wifi_qrcode' => $wifiQrcodeRaw]);
            }
        }

        return response()->json(['success' => true]);
    }


    public function getTableNames(int $eventId)
    {
        $tables = TableEvent::where('event', $eventId)
            ->orderBy('table_number')
            ->get(['table_number', 'table_name']);

        return response()->json([
            'table_names' => $tables,
        ]);
    }

    public function updateTableNames(Request $request, int $eventId)
    {
        $tables = $request->input('table_names');

        if (!is_array($tables)) {
            return response()->json(['error' => 'Ungültiges Format'], 422);
        }

        DB::transaction(function () use ($tables, $eventId) {

            // Alte Tischnamen löschen
            TableEvent::where('event', $eventId)->delete();

            // Neue einfügen
            foreach ($tables as $entry) {
                if (!isset($entry['table_number']) || !isset($entry['table_name'])) {
                    continue;
                }

                TableEvent::create([
                    'event'         => $eventId,
                    'table_number'  => (int) $entry['table_number'],
                    'table_name'    => $entry['table_name'],
                ]);
            }
        });

        return response()->json(['success' => true]);
    }

}
