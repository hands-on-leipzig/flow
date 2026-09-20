<?php

declare(strict_types=1);

namespace App\Print;

use App\Support\ProgramCatalog;

final class TeamlisteTcpdfRenderer
{
    /**
     * @param  array{
     *     title_short?:string,
     *     title_long?:string,
     *     created_at?:string,
     *     public_url?:string,
     *     qr_base64?:?string,
     *     wifi_qr_base64?:?string,
     *     sections?:list<array<string,mixed>>
     * }  $document
     */
    public function render(array $document): string
    {
        $pdf = EventPrintPdf::make('Teamliste');
        $pdf->loadChrome($document);

        $sections = $document['sections'] ?? [];
        if ($sections === []) {
            $pdf->colorHex = EventPrintPdf::HOT_ORANGE;
            $pdf->logoPath = null;
            $pdf->noshowSubject = false;
            $pdf->sectionSubject = 'Teamliste';
            $pdf->AddPage();
        }

        foreach ($sections as $section) {
            $pdf->colorHex = EventPrintPdf::HOT_ORANGE;
            $pdf->sectionSubject = 'Teamliste';
            $pdf->noshowSubject = false;
            $pdf->logoPath = null;
            $pdf->AddPage();
            $this->programHeading($pdf, $section);
            $this->table($pdf, $section);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function programHeading(EventPrintPdf $pdf, array $section): void
    {
        $usable = $pdf->getPageWidth() - (EventPrintPdf::MARGIN * 2);
        $pdf->ensureSpace(10.0);
        $y = $pdf->GetY();
        $x = EventPrintPdf::MARGIN;
        $logo = self::logoFile($section['logo_stem'] ?? null);
        if ($logo !== null) {
            $pdf->Image($logo, $x, $y, 6.0, 6.0, 'PNG');
            $x += 8.0;
        }
        $pdf->SetXY($x, $y);
        $pdf->SetFont($pdf->boldFont, '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($usable - ($x - EventPrintPdf::MARGIN), 6, (string) ($section['display_name'] ?? ''), 0, 1, 'L');
        $pdf->Ln(2);
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function table(EventPrintPdf $pdf, array $section): void
    {
        $usable = $pdf->getPageWidth() - (EventPrintPdf::MARGIN * 2);
        $widths = self::columnWidths($usable);
        $headers = [
            'Nummer',
            'Name',
            'Raum',
            (string) ($section['group_header'] ?? 'Jury-Gruppe'),
            'Coaches',
            'Mobil',
            'Coaches',
            'Kinder',
        ];
        $rows = $section['rows'] ?? [];
        $this->columnHeaders($pdf, $usable, $widths, $headers);

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $cells = [
                (string) ($row['number'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['room'] ?? ''),
                (string) ($row['group'] ?? ''),
                (string) ($row['coaches'] ?? ''),
                (string) ($row['phone'] ?? ''),
                (string) ($row['coach_count'] ?? ''),
                (string) ($row['player_count'] ?? ''),
            ];
            $h = 6.0;
            foreach ($cells as $i => $text) {
                $font = $i === 1 ? $pdf->boldFont : $pdf->regularFont;
                $pdf->SetFont($font, '', 9);
                $h = max($h, $pdf->getStringHeight($widths[$i], $text !== '' ? $text : ' ', false, true, '', 1));
            }
            if ($pdf->overflows($h)) {
                $pdf->AddPage();
                $this->programHeading($pdf, $section);
                $this->columnHeaders($pdf, $usable, $widths, $headers);
            }

            $startY = $pdf->GetY();
            if ($index % 2 === 1) {
                $pdf->SetFillColor(245, 246, 248);
                $pdf->Rect(EventPrintPdf::MARGIN, $startY, $usable, $h, 'F');
            }

            $x = EventPrintPdf::MARGIN;
            foreach ($cells as $i => $text) {
                $font = $i === 1 ? $pdf->boldFont : $pdf->regularFont;
                $pdf->SetFont($font, '', 9);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetXY($x, $startY);
                $pdf->MultiCell($widths[$i], $h, $text, 0, 'L', false, 0);
                if ($i === 1 && ! empty($row['noshow']) && $text !== '') {
                    $mid = $startY + ($h / 2);
                    $pdf->SetLineWidth(0.4);
                    $pdf->Line($x, $mid, $x + min($widths[$i], $pdf->GetStringWidth($text) + 0.4), $mid);
                    $pdf->SetLineWidth(0.2);
                }
                $x += $widths[$i];
            }
            $pdf->SetY($startY + $h);
        }
    }

    /**
     * @param  list<float>  $widths
     * @param  list<string>  $headers
     */
    private function columnHeaders(EventPrintPdf $pdf, float $usable, array $widths, array $headers): void
    {
        $pdf->SetFont($pdf->boldFont, '', 8);
        $h = 6.0;
        foreach ($headers as $i => $label) {
            $h = max($h, $pdf->getStringHeight($widths[$i], $label, false, true, '', 1));
        }
        $pdf->ensureSpace($h);
        $headerY = $pdf->GetY();
        $pdf->SetFillColor(236, 238, 241);
        $pdf->Rect(EventPrintPdf::MARGIN, $headerY, $usable, $h, 'F');
        $x = EventPrintPdf::MARGIN;
        $pdf->SetTextColor(0, 0, 0);
        foreach ($headers as $i => $label) {
            $pdf->SetXY($x, $headerY);
            $pdf->MultiCell($widths[$i], $h, $label, 0, 'L', false, 0);
            $x += $widths[$i];
        }
        $pdf->SetY($headerY + $h);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(EventPrintPdf::MARGIN, $pdf->GetY(), EventPrintPdf::MARGIN + $usable, $pdf->GetY());
        $pdf->SetFont($pdf->regularFont, '', 9);
    }

    /**
     * @return list<float>
     */
    private static function columnWidths(float $usable): array
    {
        $number = 16.0;
        $room = 22.0;
        $group = 24.0;
        $phone = 24.0;
        $coachCount = 14.0;
        $kids = 12.0;
        $name = 32.0;
        $coaches = $usable - $number - $name - $room - $group - $phone - $coachCount - $kids;

        return [$number, $name, $room, $group, $coaches, $phone, $coachCount, $kids];
    }

    private static function logoFile(mixed $stem): ?string
    {
        if (! is_string($stem) || $stem === '') {
            return null;
        }
        $path = ProgramCatalog::logoPath($stem, 'v');

        return is_file($path) ? $path : null;
    }
}
