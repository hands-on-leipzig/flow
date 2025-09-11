<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

use Carbon\Carbon;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Logo\Logo;




use Barryvdh\DomPDF\Facade\Pdf;        // composer require barryvdh/laravel-dompdf


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
                'qrcode' => 'data:image/png;base64,' . $event->qrcode,
            ]);
        }
    
        $region = DB::table('regional_partner')
            ->where('id', $event->regional_partner)
            ->value('region');

        if (!$region) {
            return response()->json(['error' => 'Region not found'], 404);
        }

        switch ($event->level) {

            case 1:

                $link =  $region;

                // Prüfen, ob mehrere Regio für diesen Regionalpartner existieren
                $eventCount = DB::table('event')
                    ->where('regional_partner', $event->regional_partner)
                    ->where('level', 1)
                    ->count();

                if ($eventCount > 1) {
                    if (!is_null($event->event_challenge)) {
                        $link .= "-challenge";
                    }
                    if (!is_null($event->event_explore)) {
                        $link .= "-explore";
                    }
                }
                break;

            case 2:    
                $link = "quali-". $region;
                break;

            case 3:
              $link = "finale"; // Region bewusst weggelassen
        }

        // Link "säubern"
        $link = trim(strtolower($link));
        $link = str_replace(array('ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß', '/', ' '), array('ae', 'oe', 'ue', 'AE', 'OE', 'UE', 'ss', '-', '-'), $link);

        $link = "https://flow.hands-on-technology.org/" . $link;


        // QR-Code mit Endroid erzeugen
        $qrCode = new QrCode(
            $link,
            new Encoding('UTF-8'),
            ErrorCorrectionLevel::High,
            300,
            10,
            RoundBlockSizeMode::Margin,
            new Color(0, 0, 0),        // schwarz
            new Color(255, 255, 255)   // weiß
        );

        $writer = new PngWriter();

        // Logo optional hinzufügen
        $logo = null;
        $logoPath = public_path("img/hot_logo_qr.png");
        if (file_exists($logoPath)) {
            $logo = new Logo($logoPath, 100); // 50px breit
        }

        // QR-Code schreiben
        $result = $writer->write($qrCode, $logo);
        $qrcodeRaw = base64_encode($result->getString()); // nur Base64

        // In DB speichern (ohne Prefix)
        DB::table('event')
            ->where('id', $event->id)
            ->update([
                'link'   => $link,
                'qrcode' => $qrcodeRaw,
            ]);

        // In Response Prefix hinzufügen
        return response()->json([
            'link' => $link,
            'qrcode' => 'data:image/png;base64,' . $qrcodeRaw,
        ]);
    }


public function PDFsingle(int $planId)
{
    $event = DB::table('event')
        ->join('plan', 'plan.event', '=', 'event.id')
        ->where('plan.id', $planId)
        ->select('event.*')
        ->first();

    if (!$event) {
        return response()->json(['error' => 'Event not found'], 404);
    }

    $html = $this->buildEventHtml($event);

    $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape');
    return $pdf->stream('FLOW_QR_Code_Plan.pdf');
}

public function PDFsinglePreview(int $planId)
{
    $event = DB::table('event')
        ->join('plan', 'plan.event', '=', 'event.id')
        ->where('plan.id', $planId)
        ->select('event.*')
        ->first();

    if (!$event) {
        return response()->json(['error' => 'Event not found'], 404);
    }

    // PDF wie bei PDFsingle erzeugen
    $html = $this->buildEventHtml($event);
    $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape');
    $pdfData = $pdf->output();

    // PDF -> PNG mit Imagick
    $imagick = new \Imagick();
    $imagick->setResolution(100, 100); // Vorschau-Auflösung
    $imagick->readImageBlob($pdfData);
    $imagick->setImageFormat('png');

    // Nur erste Seite als Preview
    $imagick->setIteratorIndex(0);
    $pngData = $imagick->getImageBlob();

    return response()->json([
        'preview' => 'data:image/png;base64,' . base64_encode($pngData)
    ]);
}


private function buildEventHtml($event): string
{
    // Datum formatieren
    $formattedDate = '';
    if (!empty($event->date)) {
        try {
            $formattedDate = Carbon::parse($event->date)->format('d.m.Y');
        } catch (\Exception $e) {
            $formattedDate = $event->date; // fallback, falls parsing schiefgeht
        }
    }

    $html = '
        <div style="text-align: center; font-family: sans-serif; width: 100%;">
            <h2 style="margin-bottom: 10px; font-size: 20px; font-weight: normal;">
                FIRST LEGO League Wettbewerb
            </h2>
            <h1 style="margin-bottom: 40px;">'
                . e($event->name) . ' ' . e($formattedDate) .
            '</h1>';

    if (!empty($event->qrcode)) {
        $html .= '<div style="margin-bottom: 30px;">
            <img src="data:image/png;base64,' . $event->qrcode . '" style="width:200px; height:200px;" />
        </div>';
    }

    if (!empty($event->link)) {
        $html .= '<div style="font-size: 16px; color: #333;">'
            . e($event->link) .
        '</div>';
    }

    $html .= '</div>';

    return $html;
}






}