<?php

declare(strict_types=1);

namespace App\Print;

final class MatchPlanScoreTcpdfRenderer
{
    private const BLUE = [37, 99, 235];

    private const GRAY = [209, 213, 219];

    private const GRAY_TEXT = [55, 65, 81];

    private const HEAD_FILL = [243, 244, 246];

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
        $pdf = EventPrintPdf::make('Match-Plan SCORE');
        $pdf->loadChrome($document);
        $pdf->colorHex = EventPrintPdf::HOT_ORANGE;
        $pdf->logoPath = null;
        $pdf->noshowSubject = false;
        $pdf->sectionSubject = 'Match-Plan SCORE';
        $pdf->AddPage();

        $section = $document['sections'][0] ?? [];
        $rounds = is_array($section['rounds'] ?? null) ? $section['rounds'] : [];
        $first = true;
        foreach ($rounds as $round) {
            if (! is_array($round)) {
                continue;
            }
            if (! $first) {
                $pdf->Ln(3);
            }
            $first = false;
            $this->round($pdf, $round);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * @param  array<string, mixed>  $round
     */
    private function round(EventPrintPdf $pdf, array $round): void
    {
        $usable = $pdf->getPageWidth() - (EventPrintPdf::MARGIN * 2);
        $label = mb_strtoupper((string) ($round['label'] ?? ''), 'UTF-8');
        $pdf->ensureSpace(14.0);
        $y = $pdf->GetY();
        $pdf->SetFillColor(...self::HEAD_FILL);
        $pdf->RoundedRect(EventPrintPdf::MARGIN, $y, $usable, 7.0, 1.0, '1111', 'F');
        $pdf->SetXY(EventPrintPdf::MARGIN + 2.5, $y);
        $pdf->SetFont($pdf->boldFont, '', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($usable - 5.0, 7.0, $label, 0, 1, 'L');
        $pdf->Ln(1.5);

        $gap = 1.5;
        $col = ($usable - $gap) / 2;
        $matches = is_array($round['matches'] ?? null) ? $round['matches'] : [];
        foreach ($matches as $match) {
            if (! is_array($match)) {
                continue;
            }
            $team1 = is_array($match['team_1'] ?? null) ? $match['team_1'] : null;
            $team2 = is_array($match['team_2'] ?? null) ? $match['team_2'] : null;
            $h = max(
                $this->boxHeight($pdf, $col, $team1),
                $this->boxHeight($pdf, $col, $team2),
            );
            if ($pdf->overflows($h)) {
                $pdf->AddPage();
            }
            $startY = $pdf->GetY();
            $this->teamBox($pdf, EventPrintPdf::MARGIN, $startY, $col, $h, $team1);
            $this->teamBox($pdf, EventPrintPdf::MARGIN + $col + $gap, $startY, $col, $h, $team2);
            $pdf->SetY($startY + $h + 1.0);
        }
    }

    /**
     * @param  array{name?:mixed,hot_number?:mixed,noshow?:bool}|null  $team
     */
    private function boxHeight(EventPrintPdf $pdf, float $width, ?array $team): float
    {
        $pdf->SetFont($pdf->boldFont, '', 9);
        $label = self::label($team);

        return max(8.0, $pdf->getStringHeight($width - 5.0, $label, false, true, '', 1) + 2.0);
    }

    /**
     * @param  array{name?:mixed,hot_number?:mixed,noshow?:bool}|null  $team
     */
    private function teamBox(EventPrintPdf $pdf, float $x, float $y, float $w, float $h, ?array $team): void
    {
        $empty = $team === null;
        if ($empty) {
            $pdf->SetFillColor(...self::GRAY);
            $pdf->SetTextColor(...self::GRAY_TEXT);
        } else {
            $pdf->SetFillColor(...self::BLUE);
            $pdf->SetTextColor(255, 255, 255);
        }
        $pdf->RoundedRect($x, $y, $w, $h, 1.0, '1111', 'F');
        $label = self::label($team);
        $pdf->SetFont($pdf->boldFont, '', 9);
        $pdf->SetXY($x + 2.5, $y + 1.0);
        $pdf->MultiCell($w - 5.0, $h - 2.0, $label, 0, 'L', false, 0);
        if (! $empty && ! empty($team['noshow'])) {
            $mid = $y + ($h / 2);
            $pdf->SetDrawColor(255, 255, 255);
            $pdf->SetLineWidth(0.4);
            $pdf->Line($x + 2.5, $mid, $x + $w - 2.5, $mid);
            $pdf->SetLineWidth(0.2);
            $pdf->SetDrawColor(0, 0, 0);
        }
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * @param  array{name?:mixed,hot_number?:mixed,noshow?:bool}|null  $team
     */
    private static function label(?array $team): string
    {
        if ($team === null) {
            return 'Freier Slot';
        }
        $name = trim((string) ($team['name'] ?? ''));
        $hot = $team['hot_number'] ?? '';

        return $name.' ['.$hot.']';
    }
}
