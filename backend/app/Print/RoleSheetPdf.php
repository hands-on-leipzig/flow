<?php

declare(strict_types=1);

namespace App\Print;

use TCPDF;

final class RoleSheetPdf extends TCPDF
{
    public const HOT_ORANGE = 'F78B1F';

    public const HEADER_BODY_MARGIN = 28.0;

    private const QR_SIZE = 16.0;

    private const QR_GAP = 2.0;

    private const COL_GAP = 2.0;

    private const CAPTION_H = 3.8;

    public string $eventTitle = '';

    public string $createdAt = '';

    public string $colorHex = self::HOT_ORANGE;

    public ?string $logoPath = null;

    public ?string $hotPath = null;

    public ?string $qrPng = null;

    public ?string $wifiQrPng = null;

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

        $top = 4.0;
        $margin = 12.0;
        $pageW = $this->getPageWidth();
        $sideW = (self::QR_SIZE * 2.0) + self::QR_GAP;
        $leftX = $margin;
        $rightX = $pageW - $margin - $sideW;
        $midX = $leftX + $sideW + self::COL_GAP;
        $midW = $rightX - self::COL_GAP - $midX;

        $this->drawHotLogo($leftX, $top, $sideW);
        $this->drawQrColumn($rightX, $top);

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

    public static function pngFromBase64(mixed $stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        $raw = base64_decode($stored, true);
        if (is_string($raw) && strlen($raw) > 50) {
            return $raw;
        }

        return null;
    }

    private function drawHotLogo(float $x, float $top, float $sideW): void
    {
        if ($this->hotPath === null || ! is_file($this->hotPath)) {
            return;
        }
        $logoW = $sideW;
        $logoH = $sideW;
        $info = @getimagesize($this->hotPath);
        if (is_array($info) && ($info[0] ?? 0) > 0) {
            $logoH = $sideW * ((float) $info[1] / (float) $info[0]);
        }
        $columnH = self::QR_SIZE + self::CAPTION_H;
        $y = $top + max(0.0, ($columnH - $logoH) / 2.0);
        $this->Image($this->hotPath, $x, $y, $logoW, $logoH, '', '', '', true, 300, '', false, false, 0, true);
    }

    private function drawQrColumn(float $x, float $top): void
    {
        $wifiX = $x;
        $publicX = $x + self::QR_SIZE + self::QR_GAP;
        $this->drawQrSlot($wifiX, $top, $this->wifiQrPng, 'WLAN');
        $this->drawQrSlot($publicX, $top, $this->qrPng, 'Online-Plan');
    }

    private function drawQrSlot(float $x, float $top, ?string $png, string $caption): void
    {
        if (is_string($png) && $png !== '') {
            $this->Image('@'.$png, $x, $top, self::QR_SIZE, self::QR_SIZE, 'PNG', '', '', true, 300, '', false, false, 0, true);
        }
        $this->SetTextColor(0, 0, 0);
        $this->SetFont($this->regularFont, '', 7);
        $this->SetXY($x, $top + self::QR_SIZE);
        $this->Cell(self::QR_SIZE, self::CAPTION_H, $caption, 0, 0, 'C');
    }
}
