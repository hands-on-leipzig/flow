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

        return View::make('pdf.layout_landscape', [
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

        // Determine competition type text dynamically
        $competitionType = $this->getCompetitionTypeText($event);

        return [
            'leftLogos'       => $leftLogos,
            'centerTitleTop'  => 'FIRST LEGO League ' . $competitionType,
            'centerTitleMain' => trim(($event->name ?? '') . ' ' . $formattedDate),
            'rightLogo'       => $rightLogo,
        ];
    }

    /**
     * Determine the competition type text based on event configuration
     */
    private function getCompetitionTypeText(object $event): string
    {
        $hasExplore = !empty($event->event_explore);
        $hasChallenge = !empty($event->event_challenge);
        $level = (int)($event->level ?? 0);

        // Both Explore and Challenge Regio (level 1)
        if ($hasExplore && $hasChallenge && $level === 1) {
            return 'Ausstellung und Regionalwettbewerb';
        }

        // Only Explore
        if ($hasExplore && !$hasChallenge) {
            return 'Ausstellung';
        }

        // Only Challenge - check level
        if ($hasChallenge && !$hasExplore) {
            return match ($level) {
                1 => 'Regionalwettbewerb',
                2 => 'Qualifikationswettbewerb',
                3 => 'Finale',
                default => 'Wettbewerb',
            };
        }

        // Fallback
        return 'Wettbewerb';
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