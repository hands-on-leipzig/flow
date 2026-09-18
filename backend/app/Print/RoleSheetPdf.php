<?php

declare(strict_types=1);

namespace App\Print;

use TCPDF;

final class RoleSheetPdf extends TCPDF
{
    public const HOT_ORANGE = 'F78B1F';

    public string $eventTitle = '';

    public string $createdAt = '';

    public string $colorHex = self::HOT_ORANGE;

    public ?string $logoPath = null;

    public ?string $hotPath = null;

    public ?string $qrPng = null;

    public string $sectionSubject = '';

    public bool $noshowSubject = false;

    public string $regularFont = 'helvetica';

    public string $boldFont = 'helvetica';

    public string $italicFont = 'helvetica';

    public function Header(): void
    {
        $rgb = self::rgb($this->colorHex);
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->Rect(0, 0, $this->getPageWidth(), 3, 'F');

        $icon = 16.0;
        $top = 4.0;
        $left = 12.0;
        $pageW = $this->getPageWidth();
        $right = $pageW - 12.0 - $icon;
        $gap = 2.0;
        $midX = $left + $icon + $gap;
        $midW = $right - $gap - $midX;

        if ($this->hotPath !== null && is_file($this->hotPath)) {
            $this->Image($this->hotPath, $left, $top, $icon, $icon, '', '', '', true, 300, '', false, false, 0, true);
        }
        if (is_string($this->qrPng) && $this->qrPng !== '') {
            $this->Image('@'.$this->qrPng, $right, $top, $icon, $icon, 'PNG', '', '', true, 300, '', false, false, 0, true);
        }

        $this->SetTextColor(0, 0, 0);
        $this->SetFont($this->regularFont, '', 9);
        $this->SetXY($midX, $top);
        $this->MultiCell($midW, 5, $this->eventTitle, 0, 'L', false, 1);

        $row2Y = $top + 8.0;
        $prog = 7.0;
        $textX = $midX;
        if ($this->logoPath !== null && is_file($this->logoPath)) {
            $this->Image($this->logoPath, $midX, $row2Y, $prog, $prog, '', '', '', true, 300, '', false, false, 0, true);
            $textX = $midX + $prog + 1.5;
        }
        $this->SetFont($this->boldFont, '', 12);
        $this->SetXY($textX, $row2Y);
        $subjectW = $midX + $midW - $textX;
        $this->Cell($subjectW, $prog, $this->sectionSubject, 0, 0, 'L');
        if ($this->noshowSubject && $this->sectionSubject !== '') {
            $width = min($this->GetStringWidth($this->sectionSubject), $subjectW);
            $this->SetLineWidth(0.4);
            $this->Line($textX, $row2Y + ($prog / 2), $textX + $width, $row2Y + ($prog / 2));
            $this->SetLineWidth(0.2);
        }
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont($this->regularFont, '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 8, $this->createdAt, 0, 0, 'L');
        $this->Cell(0, 8, (string) $this->getPage(), 0, 0, 'R');
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (! preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
            $hex = self::HOT_ORANGE;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
