<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

class PdfLayoutService
{
    private EventTitleService $eventTitleService;

    public function __construct(EventTitleService $eventTitleService)
    {
        $this->eventTitleService = $eventTitleService;
    }
    /**
     * Baut das vollständige HTML-Dokument mit Header, Content (Mittelteil) und Footer im Querformat.
     * 
     * @param object $event Event object
     * @param string $contentHtml Content HTML
     * @param string $title Document title
     * @param bool $isQrCodePdf If true, logos are rendered in content area and footer is reduced to 40px
     * @return string Rendered HTML
     */
    public function renderLayout(object $event, string $contentHtml, string $title = 'Dokument', bool $isQrCodePdf = false): string
    {
        // Headerdaten
        $header = $this->buildHeaderData($event);

        // Footerlogos: For QR PDFs, logos are rendered in content area, so pass empty array
        $footerLogos = $isQrCodePdf ? [] : $this->buildFooterLogos($event->id);

        return View::make('pdf.layout_landscape', [
            'title'       => $title,
            'header'      => $header,
            'footerLogos' => $footerLogos,
            'contentHtml' => $contentHtml,
            'isQrCodePdf' => $isQrCodePdf,
        ])->render();
    }

    /**
     * Baut das vollständige HTML-Dokument mit Header, Content (Mittelteil) und Footer im Hochformat.
     */
    public function renderPortraitLayout(object $event, string $contentHtml, string $title = 'Dokument'): string
    {
        // Headerdaten
        $header = $this->buildHeaderData($event);

        // Footerlogos
        $footerLogos = $this->buildFooterLogos($event->id);

        return View::make('pdf.layout_portrait', [
            'title'       => $title,
            'header'      => $header,
            'footerLogos' => $footerLogos,
            'contentHtml' => $contentHtml,
        ])->render();
    }

    public function buildHeaderData(object $event): array
    {
        $formattedDate = '';
        if (!empty($event->date)) {
            try {
                $formattedDate = Carbon::parse($event->date)->format('d.m.Y');
            } catch (\Throwable $e) {
                $formattedDate = (string) $event->date;
            }
        }

        // Check if this is a multi-day event using event.days
        if (!empty($event->days) && $event->days > 1) {
            $startDate = Carbon::parse($event->date);
            $endDate = $startDate->copy()->addDays($event->days - 1);
            $formattedDate = $startDate->format('d.m.') . '-' . $endDate->format('d.m.Y');
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

        // Use EventTitleService for consistent title formatting
        $competitionType = $this->eventTitleService->getCompetitionTypeText($event);
        $cleanedEventName = $this->eventTitleService->cleanEventName($event);

        return [
            'leftLogos'       => $leftLogos,
            'centerTitleTop'  => 'FIRST LEGO League ' . $competitionType,
            'centerTitleMain' => trim($cleanedEventName . ' ' . $formattedDate),
            'rightLogo'       => $rightLogo,
        ];
    }

    public function buildFooterLogos(int $eventId): array
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


    public function toDataUri(string $path): ?string
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