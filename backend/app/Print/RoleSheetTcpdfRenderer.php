<?php

declare(strict_types=1);

namespace App\Print;

use App\Support\ProgramCatalog;

final class RoleSheetTcpdfRenderer
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
        $pdf = EventPrintPdf::make('Rollenpläne');
        $pdf->loadChrome($document);

        $sections = $document['sections'] ?? [];
        if ($sections === []) {
            $pdf->AddPage();
        }

        foreach ($sections as $section) {
            $pdf->colorHex = (string) ($section['color_hex'] ?? EventPrintPdf::HOT_ORANGE);
            $pdf->sectionSubject = (string) ($section['subject'] ?? '');
            $pdf->noshowSubject = (bool) ($section['noshow'] ?? false);
            $pdf->logoPath = self::logoFile($section['logo_stem'] ?? null);
            $pdf->AddPage();

            $this->table($pdf, 'Ablauf', $section['ablauf'] ?? []);
            if (isset($section['zusaetzlich']) && is_array($section['zusaetzlich']) && $section['zusaetzlich'] !== []) {
                $pdf->Ln(3);
                $this->table($pdf, 'Zusätzlich', $section['zusaetzlich']);
            }
        }

        return $pdf->Output('', 'S');
    }

    /**
     * @param  list<array{start?:string,end?:string,room?:string,action?:string,strike?:list<string>,italic?:list<string>}>  $rows
     */
    private function table(EventPrintPdf $pdf, string $heading, array $rows): void
    {
        $usable = $pdf->getPageWidth() - (EventPrintPdf::MARGIN * 2);
        $wStart = 18;
        $wEnd = 18;
        $wRoom = 52;
        $wAction = $usable - $wStart - $wEnd - $wRoom;

        $firstH = 6.0;
        if ($rows !== []) {
            $firstRoom = (string) ($rows[0]['room'] ?? '');
            $firstAction = (string) ($rows[0]['action'] ?? '');
            $firstH = max(
                6.0,
                $pdf->getStringHeight($wRoom, $firstRoom, false, true, '', 1),
                $pdf->getStringHeight($wAction, $firstAction, false, true, '', 1),
            );
        }
        $pdf->ensureSpace(12.0 + $firstH);
        $pdf->SetFont($pdf->boldFont, '', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($usable, 6, $heading, 0, 1, 'L');
        $this->columnHeaders($pdf, $usable, $wStart, $wEnd, $wRoom, $wAction);

        $pdf->SetFont($pdf->regularFont, '', 9);
        foreach ($rows as $index => $row) {
            $start = (string) ($row['start'] ?? '');
            $end = (string) ($row['end'] ?? '');
            $room = (string) ($row['room'] ?? '');
            $action = (string) ($row['action'] ?? '');
            $strike = $row['strike'] ?? [];
            $italic = $row['italic'] ?? [];

            $hRoom = $pdf->getStringHeight($wRoom, $room, false, true, '', 1);
            $hAction = $pdf->getStringHeight($wAction, $action, false, true, '', 1);
            $h = max(6.0, $hRoom, $hAction);
            if ($pdf->overflows($h)) {
                $pdf->AddPage();
                $this->columnHeaders($pdf, $usable, $wStart, $wEnd, $wRoom, $wAction);
                $pdf->SetFont($pdf->regularFont, '', 9);
            }

            $startY = $pdf->GetY();
            $stripe = $index % 2 === 1;
            if ($stripe) {
                $pdf->SetFillColor(245, 246, 248);
                $pdf->Rect(EventPrintPdf::MARGIN, $startY, $usable, $h, 'F');
            }

            $pdf->SetXY(EventPrintPdf::MARGIN, $startY);
            $pdf->MultiCell($wStart, $h, $start, 0, 'L', false, 0);
            $pdf->SetXY(EventPrintPdf::MARGIN + $wStart, $startY);
            $pdf->MultiCell($wEnd, $h, $end, 0, 'L', false, 0);
            $pdf->SetXY(EventPrintPdf::MARGIN + $wStart + $wEnd, $startY);
            $pdf->MultiCell($wRoom, $h, $room, 0, 'L', false, 0);
            $this->writeAction($pdf, EventPrintPdf::MARGIN + $wStart + $wEnd + $wRoom, $startY, $h, $wAction, $action, $italic);

            if (self::shouldStrike($action, $strike)) {
                self::strikeNames($pdf, EventPrintPdf::MARGIN + $wStart + $wEnd + $wRoom, $startY, $h, $wAction, $action, $strike);
            }

            $pdf->SetFont($pdf->regularFont, '', 9);
            $pdf->SetY($startY + $h);
        }
    }

    private function columnHeaders(
        EventPrintPdf $pdf,
        float $usable,
        float $wStart,
        float $wEnd,
        float $wRoom,
        float $wAction,
    ): void {
        $headerY = $pdf->GetY();
        $pdf->SetFillColor(236, 238, 241);
        $pdf->Rect(EventPrintPdf::MARGIN, $headerY, $usable, 6, 'F');
        $pdf->SetXY(EventPrintPdf::MARGIN, $headerY);
        $pdf->SetFont($pdf->boldFont, '', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($wStart, 6, 'Start', 0, 0, 'L');
        $pdf->Cell($wEnd, 6, 'Ende', 0, 0, 'L');
        $pdf->Cell($wRoom, 6, 'Raum', 0, 0, 'L');
        $pdf->Cell($wAction, 6, 'Aktion', 0, 1, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->Line(EventPrintPdf::MARGIN, $pdf->GetY(), EventPrintPdf::MARGIN + $usable, $pdf->GetY());
    }

    /**
     * @param  list<string>  $italic
     */
    private function writeAction(
        EventPrintPdf $pdf,
        float $x,
        float $y,
        float $h,
        float $width,
        string $action,
        array $italic,
    ): void {
        $parts = self::italicParts($action, $italic);
        $needsItalic = false;
        foreach ($parts as $part) {
            if ($part['italic'] && $part['text'] !== '') {
                $needsItalic = true;
                break;
            }
        }

        $pdf->SetFont($pdf->regularFont, '', 9);
        if (! $needsItalic) {
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($width, $h, $action, 0, 'L', false, 1);

            return;
        }

        $html = '';
        foreach ($parts as $part) {
            $text = htmlspecialchars($part['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= $part['italic'] ? '<i>'.$text.'</i>' : $text;
        }
        $pdf->writeHTMLCell($width, $h, $x, $y, $html, 0, 0, false, true, 'L', true);
    }

    /**
     * @param  list<string>  $italic
     * @return list<array{text: string, italic: bool}>
     */
    private static function italicParts(string $action, array $italic): array
    {
        $names = [];
        foreach ($italic as $name) {
            if (is_string($name) && $name !== '' && str_contains($action, $name) && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        usort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        if ($action === '' || $names === []) {
            return [['text' => $action, 'italic' => false]];
        }

        $parts = [['text' => $action, 'italic' => false]];
        foreach ($names as $name) {
            $next = [];
            foreach ($parts as $part) {
                if ($part['italic'] || ! str_contains($part['text'], $name)) {
                    $next[] = $part;

                    continue;
                }
                $pos = mb_strpos($part['text'], $name);
                if ($pos === false) {
                    $next[] = $part;

                    continue;
                }
                $before = mb_substr($part['text'], 0, $pos);
                $after = mb_substr($part['text'], $pos + mb_strlen($name));
                if ($before !== '') {
                    $next[] = ['text' => $before, 'italic' => false];
                }
                $next[] = ['text' => $name, 'italic' => true];
                if ($after !== '') {
                    $next[] = ['text' => $after, 'italic' => false];
                }
            }
            $parts = $next;
        }

        return $parts;
    }

    /**
     * @param  list<string>  $strike
     */
    private static function shouldStrike(string $action, array $strike): bool
    {
        if ($action === '') {
            return false;
        }
        foreach ($strike as $name) {
            if (is_string($name) && $name !== '' && str_contains($action, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $strike
     */
    private static function strikeNames(
        EventPrintPdf $pdf,
        float $x,
        float $y,
        float $h,
        float $width,
        string $action,
        array $strike,
    ): void {
        $mid = $y + ($h / 2);
        $pdf->SetLineWidth(0.4);
        $names = [];
        foreach ($strike as $name) {
            if (is_string($name) && $name !== '' && str_contains($action, $name) && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        $singleLine = method_exists($pdf, 'getNumLines') ? $pdf->getNumLines($action, $width) <= 1 : true;
        if (! $singleLine || $names === []) {
            $pdf->Line($x, $mid, $x + $width, $mid);
            $pdf->SetLineWidth(0.2);

            return;
        }

        foreach ($names as $name) {
            $pos = mb_strpos($action, $name);
            if ($pos === false) {
                continue;
            }
            $prefix = mb_substr($action, 0, $pos);
            $start = $x + $pdf->GetStringWidth($prefix);
            $pdf->Line($start, $mid, $start + $pdf->GetStringWidth($name), $mid);
        }
        $pdf->SetLineWidth(0.2);
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
