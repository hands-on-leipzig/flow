<?php

declare(strict_types=1);

namespace App\Print;

use App\Support\ProgramCatalog;
use TCPDF;

final class RoleSheetTcpdfRenderer
{
    /**
     * @param  array{title_short?:string,sections?:list<array<string,mixed>>}  $document
     */
    public function render(array $document): string
    {
        $pdf = new RoleSheetPdf('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('FLOW');
        $pdf->SetAuthor('FLOW');
        $pdf->SetTitle('Rollenpläne');
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->setHeaderMargin(0);
        $pdf->setFooterMargin(12);
        $pdf->SetMargins(12, 16, 12);
        $pdf->SetAutoPageBreak(true, 22);

        NotoTcpdfFont::register($pdf);
        $pdf->regularFont = NotoTcpdfFont::regular();
        $pdf->boldFont = NotoTcpdfFont::bold();
        $pdf->titleShort = (string) ($document['title_short'] ?? '');

        $sections = $document['sections'] ?? [];
        if ($sections === []) {
            $pdf->sectionStart = true;
            $pdf->AddPage();
        }

        foreach ($sections as $section) {
            $pdf->sectionStart = true;
            $pdf->colorHex = (string) ($section['color_hex'] ?? '888888');
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
     * @param  list<array{start?:string,end?:string,room?:string,action?:string,strike?:list<string>}>  $rows
     */
    private function table(RoleSheetPdf $pdf, string $heading, array $rows): void
    {
        $usable = $pdf->getPageWidth() - 24;
        $wStart = 18;
        $wEnd = 18;
        $wRoom = 52;
        $wAction = $usable - $wStart - $wEnd - $wRoom;

        $pdf->SetFont($pdf->boldFont, '', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($usable, 6, $heading, 0, 1, 'L');

        $pdf->SetFont($pdf->boldFont, '', 9);
        $pdf->Cell($wStart, 6, 'Start', 0, 0, 'L');
        $pdf->Cell($wEnd, 6, 'Ende', 0, 0, 'L');
        $pdf->Cell($wRoom, 6, 'Raum', 0, 0, 'L');
        $pdf->Cell($wAction, 6, 'Aktion', 0, 1, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->Line(12, $pdf->GetY(), 12 + $usable, $pdf->GetY());

        $pdf->SetFont($pdf->regularFont, '', 9);
        foreach ($rows as $row) {
            $start = (string) ($row['start'] ?? '');
            $end = (string) ($row['end'] ?? '');
            $room = (string) ($row['room'] ?? '');
            $action = (string) ($row['action'] ?? '');
            $strike = $row['strike'] ?? [];

            $startY = $pdf->GetY();
            if ($startY > $pdf->getPageHeight() - 28) {
                $pdf->AddPage();
                $startY = $pdf->GetY();
            }

            $hRoom = $pdf->getStringHeight($wRoom, $room, false, true, '', 1);
            $hAction = $pdf->getStringHeight($wAction, $action, false, true, '', 1);
            $h = max(6.0, $hRoom, $hAction);

            $pdf->SetXY(12, $startY);
            $pdf->MultiCell($wStart, $h, $start, 0, 'L', false, 0);
            $pdf->SetXY(12 + $wStart, $startY);
            $pdf->MultiCell($wEnd, $h, $end, 0, 'L', false, 0);
            $pdf->SetXY(12 + $wStart + $wEnd, $startY);
            $pdf->MultiCell($wRoom, $h, $room, 0, 'L', false, 0);
            $pdf->SetXY(12 + $wStart + $wEnd + $wRoom, $startY);
            $pdf->MultiCell($wAction, $h, $action, 0, 'L', false, 1);

            if (self::shouldStrike($action, $strike)) {
                $mid = $startY + ($h / 2);
                $pdf->SetLineWidth(0.4);
                $pdf->Line(12 + $wStart + $wEnd + $wRoom, $mid, 12 + $usable, $mid);
                $pdf->SetLineWidth(0.2);
            }

            $pdf->SetY($startY + $h);
        }
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

    private static function logoFile(mixed $stem): ?string
    {
        if (! is_string($stem) || $stem === '') {
            return null;
        }
        $path = ProgramCatalog::logoPath($stem, 'h');

        return is_file($path) ? $path : null;
    }
}

/**
 * @internal
 */
final class RoleSheetPdf extends TCPDF
{
    public string $titleShort = '';

    public string $colorHex = '888888';

    public ?string $logoPath = null;

    public string $sectionSubject = '';

    public bool $noshowSubject = false;

    public bool $sectionStart = true;

    public string $regularFont = 'helvetica';

    public string $boldFont = 'helvetica';

    public function Header(): void
    {
        $rgb = self::rgb($this->colorHex);
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->Rect(0, 0, $this->getPageWidth(), 3, 'F');

        if (! $this->sectionStart) {
            return;
        }
        $this->sectionStart = false;

        $x = 12.0;
        $y = 4.5;
        if ($this->logoPath !== null && is_file($this->logoPath)) {
            $this->Image($this->logoPath, $x, $y, 0, 10);
            $x += 16;
        }

        $this->SetFont($this->boldFont, '', 12);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($x, $y + 1.5);
        $this->Cell(0, 8, $this->sectionSubject, 0, 0, 'L');
        if ($this->noshowSubject && $this->sectionSubject !== '') {
            $width = $this->GetStringWidth($this->sectionSubject);
            $this->SetLineWidth(0.4);
            $this->Line($x, $y + 5.7, $x + $width, $y + 5.7);
            $this->SetLineWidth(0.2);
        }
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont($this->regularFont, '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 8, $this->titleShort, 0, 0, 'L');
        $this->Cell(0, 8, (string) $this->getPage(), 0, 0, 'R');
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (! preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
            $hex = '888888';
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
