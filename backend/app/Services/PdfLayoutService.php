<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

class PdfLayoutService
{
    /**
     * Baut das vollständige HTML-Dokument mit Header, Content (Mittelteil) und Footer.
     */
    public function renderLayout(object $event, string $contentHtml, string $title = 'Dokument'): string
    {
        // Headerdaten
        $header = $this->buildHeaderData($event);

        // Footerlogos
        $footerLogos = $this->buildFooterLogos($event->id);

        return View::make('pdf.layout', [
            'title'       => $title,
            'header'      => $header,
            'footerLogos' => $footerLogos,
            'contentHtml' => $contentHtml,
        ])->render();
    }

    private function buildHeaderData(object $event): array
    {
        $formattedDate = '';
        if (!empty($event->date)) {
            try {
                $formattedDate = Carbon::parse($event->date)->format('d.m.Y');
            } catch (\Throwable $e) {
                $formattedDate = (string) $event->date;
            }
        }

        $leftLogos = [];
        if (!empty($event->event_explore)) {
            $leftLogos[] = $this->toDataUri(public_path('flow/fll_explore_hs.png'));
        }
        if (!empty($event->event_challenge)) {
            $leftLogos[] = $this->toDataUri(public_path('flow/fll_challenge_hs.png'));
        }
        $leftLogos = array_values(array_filter($leftLogos));

        $rightLogo = $this->toDataUri(public_path('flow/hot.png'));

        return [
            'leftLogos'       => $leftLogos,
            'centerTitleTop'  => 'FIRST LEGO League Wettbewerb',
            'centerTitleMain' => trim(($event->name ?? '') . ' ' . $formattedDate),
            'rightLogo'       => $rightLogo,
        ];
    }

    private function buildFooterLogos(int $eventId): array
    {
        $logos = DB::table('logo')
            ->join('event_logo', 'event_logo.logo', '=', 'logo.id')
            ->where('event_logo.event', $eventId)
            ->select('logo.path')
            ->get();

        $dataUris = [];
        foreach ($logos as $logo) {
            $path = storage_path('app/public/' . $logo->path);
            $uri  = $this->toDataUri($path);
            if ($uri) {
                $dataUris[] = $uri;
            }
        }

        return $dataUris;
    }

    private function toDataUri(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $mime = mime_content_type($path) ?: 'image/png';
        $data = @file_get_contents($path);
        if ($data === false) {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}