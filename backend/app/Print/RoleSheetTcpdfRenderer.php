<?php

declare(strict_types=1);

namespace App\Print;

use App\Support\ProgramCatalog;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

final class RoleSheetTcpdfRenderer
{
    /**
     * @param  array{
     *     title_short?:string,
     *     title_long?:string,
     *     created_at?:string,
     *     public_url?:string,
     *     qr_base64?:?string,
     *     sections?:list<array<string,mixed>>
     * }  $document
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
        $pdf->SetMargins(12, 24, 12);
        $pdf->SetAutoPageBreak(true, 22);

        NotoTcpdfFont::register($pdf);
        $pdf->regularFont = NotoTcpdfFont::regular();
        $pdf->boldFont = NotoTcpdfFont::bold();
        $pdf->italicFont = NotoTcpdfFont::italic();
        $pdf->eventTitle = (string) ($document['title_long'] ?? $document['title_short'] ?? '');
        $pdf->createdAt = (string) ($document['created_at'] ?? '');
        $pdf->hotPath = self::hotLogoPath();
        $pdf->qrPng = self::qrPng($document);

        $sections = $document['sections'] ?? [];
        if ($sections === []) {
            $pdf->AddPage();
        }

        foreach ($sections as $section) {
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
     * @param  list<array{start?:string,end?:string,room?:string,action?:string,strike?:list<string>,italic?:list<string>}>  $rows
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

        $headerY = $pdf->GetY();
        $pdf->SetFillColor(236, 238, 241);
        $pdf->Rect(12, $headerY, $usable, 6, 'F');
        $pdf->SetXY(12, $headerY);
        $pdf->SetFont($pdf->boldFont, '', 9);
        $pdf->Cell($wStart, 6, 'Start', 0, 0, 'L');
        $pdf->Cell($wEnd, 6, 'Ende', 0, 0, 'L');
        $pdf->Cell($wRoom, 6, 'Raum', 0, 0, 'L');
        $pdf->Cell($wAction, 6, 'Aktion', 0, 1, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->Line(12, $pdf->GetY(), 12 + $usable, $pdf->GetY());

        $pdf->SetFont($pdf->regularFont, '', 9);
        foreach ($rows as $index => $row) {
            $start = (string) ($row['start'] ?? '');
            $end = (string) ($row['end'] ?? '');
            $room = (string) ($row['room'] ?? '');
            $action = (string) ($row['action'] ?? '');
            $strike = $row['strike'] ?? [];
            $italic = $row['italic'] ?? [];

            $startY = $pdf->GetY();
            if ($startY > $pdf->getPageHeight() - 28) {
                $pdf->AddPage();
                $startY = $pdf->GetY();
            }

            $hRoom = $pdf->getStringHeight($wRoom, $room, false, true, '', 1);
            $hAction = $pdf->getStringHeight($wAction, $action, false, true, '', 1);
            $h = max(6.0, $hRoom, $hAction);
            $stripe = $index % 2 === 1;
            if ($stripe) {
                $pdf->SetFillColor(245, 246, 248);
                $pdf->Rect(12, $startY, $usable, $h, 'F');
            }

            $pdf->SetXY(12, $startY);
            $pdf->MultiCell($wStart, $h, $start, 0, 'L', false, 0);
            $pdf->SetXY(12 + $wStart, $startY);
            $pdf->MultiCell($wEnd, $h, $end, 0, 'L', false, 0);
            $pdf->SetXY(12 + $wStart + $wEnd, $startY);
            $pdf->MultiCell($wRoom, $h, $room, 0, 'L', false, 0);
            $this->writeAction($pdf, 12 + $wStart + $wEnd + $wRoom, $startY, $h, $wAction, $action, $italic);

            if (self::shouldStrike($action, $strike)) {
                self::strikeNames($pdf, 12 + $wStart + $wEnd + $wRoom, $startY, $h, $wAction, $action, $strike);
            }

            $pdf->SetFont($pdf->regularFont, '', 9);
            $pdf->SetY($startY + $h);
        }
    }

    /**
     * @param  list<string>  $italic
     */
    private function writeAction(
        RoleSheetPdf $pdf,
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
        RoleSheetPdf $pdf,
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

    private static function hotLogoPath(): ?string
    {
        if (! function_exists('app') || ! function_exists('public_path')) {
            return null;
        }
        $app = app();
        if (! is_object($app) || ! method_exists($app, 'publicPath')) {
            return null;
        }
        $path = public_path('flow/hot.png');

        return is_file($path) ? $path : null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function qrPng(array $document): ?string
    {
        $stored = $document['qr_base64'] ?? null;
        if (is_string($stored) && $stored !== '') {
            $raw = base64_decode($stored, true);
            if (is_string($raw) && strlen($raw) > 50) {
                return $raw;
            }
        }

        $url = trim((string) ($document['public_url'] ?? ''));
        if ($url === '') {
            return null;
        }
        if (! str_contains($url, '?')) {
            $url .= '?source=qr';
        }

        try {
            $qr = new QrCode(
                $url,
                new Encoding('UTF-8'),
                ErrorCorrectionLevel::High,
                300,
                10,
                RoundBlockSizeMode::Margin,
                new Color(0, 0, 0),
                new Color(255, 255, 255),
            );

            return (new PngWriter)->write($qr)->getString();
        } catch (\Throwable) {
            return null;
        }
    }
}

/**
 * @internal
 */
final class RoleSheetPdf extends TCPDF
{
    public string $eventTitle = '';

    public string $createdAt = '';

    public string $colorHex = '888888';

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
