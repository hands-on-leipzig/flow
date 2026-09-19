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

final class RoomSheetTcpdfRenderer
{
    public const PRIVATE_MARK = 'Nicht öffentlich';

    public const PRIVATE_SUFFIX = ' — Nicht öffentlich';

    /** @var array{0:int,1:int,2:int} */
    private const PRIVATE_RGB = [180, 40, 40];

    /**
     * @param  array{
     *     title_short?:string,
     *     title_long?:string,
     *     created_at?:string,
     *     public_url?:string,
     *     qr_base64?:?string,
     *     wifi_qr_base64?:?string,
     *     show_program_logos?:bool,
     *     sections?:list<array<string,mixed>>
     * }  $document
     */
    public function render(array $document): string
    {
        $pdf = new RoleSheetPdf('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('FLOW');
        $pdf->SetAuthor('FLOW');
        $pdf->SetTitle('Raumpläne');
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->setHeaderMargin(0);
        $pdf->setFooterMargin(12);
        $pdf->SetMargins(12, RoleSheetPdf::HEADER_BODY_MARGIN, 12);
        $pdf->SetAutoPageBreak(true, 22);

        NotoTcpdfFont::register($pdf);
        $pdf->regularFont = NotoTcpdfFont::regular();
        $pdf->boldFont = NotoTcpdfFont::bold();
        $pdf->italicFont = NotoTcpdfFont::italic();
        $pdf->eventTitle = (string) ($document['title_long'] ?? $document['title_short'] ?? '');
        $pdf->createdAt = (string) ($document['created_at'] ?? '');
        $pdf->hotPath = self::hotLogoPath();
        $pdf->qrPng = self::qrPng($document);
        $pdf->wifiQrPng = RoleSheetPdf::pngFromBase64($document['wifi_qr_base64'] ?? null);

        $showLogos = (bool) ($document['show_program_logos'] ?? false);
        $sections = $document['sections'] ?? [];
        if ($sections === []) {
            $pdf->colorHex = RoleSheetPdf::HOT_ORANGE;
            $pdf->logoPath = null;
            $pdf->noshowSubject = false;
            $pdf->sectionSubject = '';
            $pdf->AddPage();
        }

        foreach ($sections as $section) {
            $pdf->colorHex = (string) ($section['color_hex'] ?? RoleSheetPdf::HOT_ORANGE);
            $pdf->sectionSubject = (string) ($section['subject'] ?? '');
            $pdf->noshowSubject = false;
            $pdf->logoPath = null;
            $pdf->AddPage();

            $columns = $section['team_columns'] ?? null;
            if (is_array($columns) && $columns !== []) {
                $this->teamGrid($pdf, $columns);
                if ($pdf->GetY() > $pdf->getPageHeight() - 48) {
                    $pdf->AddPage();
                } else {
                    $pdf->Ln(3);
                }
            }

            $this->activityTable($pdf, $section['activities'] ?? [], $showLogos);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * @param  list<array{program_id?:int,display_name?:string,logo_stem?:?string,teams?:list<array{label?:string,noshow?:bool}>}>  $columns
     */
    private function teamGrid(RoleSheetPdf $pdf, array $columns): void
    {
        $n = count($columns);
        if ($n < 1) {
            return;
        }
        $usable = $pdf->getPageWidth() - 24;
        $colW = $usable / $n;
        $this->teamHeaders($pdf, $columns, $colW);

        $rowCount = 0;
        foreach ($columns as $column) {
            $rowCount = max($rowCount, count($column['teams'] ?? []));
        }
        for ($i = 0; $i < $rowCount; $i++) {
            $labels = [];
            $height = 6.0;
            foreach ($columns as $column) {
                $label = (string) ($column['teams'][$i]['label'] ?? '');
                $labels[] = $label;
                $height = max($height, $pdf->getStringHeight($colW, $label, false, true, '', 1));
            }
            if ($pdf->GetY() > $pdf->getPageHeight() - 28) {
                $pdf->AddPage();
                $this->teamHeaders($pdf, $columns, $colW);
            }
            $startY = $pdf->GetY();
            if ($i % 2 === 1) {
                $pdf->SetFillColor(245, 246, 248);
                $pdf->Rect(12, $startY, $usable, $height, 'F');
            }
            $pdf->SetFont($pdf->regularFont, '', 9);
            foreach ($columns as $c => $column) {
                $x = 12 + ($c * $colW);
                $label = $labels[$c];
                $pdf->SetXY($x, $startY);
                $pdf->MultiCell($colW, $height, $label, 0, 'L', false, 0);
                if (! empty($column['teams'][$i]['noshow']) && $label !== '') {
                    $mid = $startY + ($height / 2);
                    $pdf->SetLineWidth(0.4);
                    $pdf->Line($x, $mid, $x + min($pdf->GetStringWidth($label), $colW), $mid);
                    $pdf->SetLineWidth(0.2);
                }
            }
            $pdf->SetY($startY + $height);
        }
    }

    /**
     * @param  list<array{program_id?:int,display_name?:string}>  $columns
     */
    private function teamHeaders(RoleSheetPdf $pdf, array $columns, float $colW): void
    {
        $y = $pdf->GetY();
        $h = 8.0;
        $pdf->SetFont($pdf->boldFont, '', 9);
        foreach ($columns as $c => $column) {
            $x = 12 + ($c * $colW);
            $textX = $x;
            $programId = (int) ($column['program_id'] ?? 0);
            $logo = self::programLogoFile($programId, isset($column['logo_stem']) ? (string) $column['logo_stem'] : null);
            if ($logo !== null) {
                $pdf->Image($logo, $x, $y + 0.5, 7, 7, '', '', '', true, 300, '', false, false, 0, true);
                $textX = $x + 8.5;
            }
            $pdf->SetXY($textX, $y);
            $pdf->MultiCell(max(4.0, $x + $colW - $textX), $h, (string) ($column['display_name'] ?? ''), 0, 'L', false, 0);
        }
        $pdf->SetY($y + $h);
    }

    /**
     * @param  list<array{start?:string,end?:string,program_id?:?int,action?:string,strike?:list<string>,private?:bool}>  $rows
     */
    private function activityTable(RoleSheetPdf $pdf, array $rows, bool $showLogos): void
    {
        $usable = $pdf->getPageWidth() - 24;
        $wLogo = $showLogos ? 7.0 : 0.0;
        $wStart = 18.0;
        $wEnd = 18.0;
        $wAction = $usable - $wLogo - $wStart - $wEnd;
        $pdf->SetFont($pdf->regularFont, '', 9);

        if ($rows !== []) {
            $this->activityHeader($pdf, $showLogos, $usable, $wLogo, $wStart, $wEnd, $wAction);
        }

        foreach ($rows as $index => $row) {
            $start = (string) ($row['start'] ?? '');
            $end = (string) ($row['end'] ?? '');
            $action = (string) ($row['action'] ?? '');
            $strike = $row['strike'] ?? [];
            $private = ! empty($row['private']);
            $startY = $pdf->GetY();
            if ($startY > $pdf->getPageHeight() - 28) {
                $pdf->AddPage();
                $this->activityHeader($pdf, $showLogos, $usable, $wLogo, $wStart, $wEnd, $wAction);
                $startY = $pdf->GetY();
            }
            $h = self::actionRowHeight($pdf, $wAction, $action, $private);
            if ($index % 2 === 1) {
                $pdf->SetFillColor(245, 246, 248);
                $pdf->Rect(12, $startY, $usable, $h, 'F');
            }
            $x = 12.0;
            if ($showLogos) {
                $programId = isset($row['program_id']) ? (int) $row['program_id'] : 0;
                $logo = self::programLogoFile($programId, null);
                if ($logo !== null) {
                    $pdf->Image($logo, $x + 0.5, $startY + max(0.0, ($h - 6) / 2), 6, 6, '', '', '', true, 300, '', false, false, 0, true);
                }
                $x += $wLogo;
            }
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x, $startY);
            $pdf->MultiCell($wStart, $h, $start, 0, 'L', false, 0);
            $pdf->SetXY($x + $wStart, $startY);
            $pdf->MultiCell($wEnd, $h, $end, 0, 'L', false, 0);
            $actionX = $x + $wStart + $wEnd;
            $pdf->SetXY($actionX, $startY);
            $pdf->MultiCell($wAction, $h, $action, 0, 'L', false, 0);
            if (self::shouldStrike($action, $strike)) {
                self::strikeNames($pdf, $actionX, $startY, $h, $wAction, $action, $strike);
            }
            if ($private) {
                self::writePrivateMark($pdf, $actionX, $startY, $wAction, $action);
            }
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont($pdf->regularFont, '', 9);
            $pdf->SetY($startY + $h);
        }
    }

    private function activityHeader(
        RoleSheetPdf $pdf,
        bool $showLogos,
        float $usable,
        float $wLogo,
        float $wStart,
        float $wEnd,
        float $wAction,
    ): void {
        $headerY = $pdf->GetY();
        $pdf->SetFillColor(236, 238, 241);
        $pdf->Rect(12, $headerY, $usable, 6, 'F');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont($pdf->boldFont, '', 9);
        $x = 12.0;
        if ($showLogos) {
            $x += $wLogo;
        }
        $pdf->SetXY($x, $headerY);
        $pdf->Cell($wStart, 6, 'Start', 0, 0, 'L');
        $pdf->Cell($wEnd, 6, 'Ende', 0, 0, 'L');
        $pdf->Cell($wAction, 6, 'Aktion', 0, 0, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->Line(12, $headerY + 6, 12 + $usable, $headerY + 6);
        $pdf->SetFont($pdf->regularFont, '', 9);
        $pdf->SetY($headerY + 6);
    }

    private static function actionRowHeight(RoleSheetPdf $pdf, float $wAction, string $action, bool $private): float
    {
        $base = max(6.0, $pdf->getStringHeight($wAction, $action !== '' ? $action : ' ', false, true, '', 1));
        if (! $private) {
            return $base;
        }
        $mark = $action === '' ? self::PRIVATE_MARK : self::PRIVATE_SUFFIX;
        if ($action === '') {
            return max(6.0, $pdf->getStringHeight($wAction, $mark, false, true, '', 1));
        }
        if (self::suffixFitsSameLine($pdf, $wAction, $action, $mark)) {
            return $base;
        }

        return $base + $pdf->getStringHeight($wAction, $mark, false, true, '', 1);
    }

    private static function suffixFitsSameLine(RoleSheetPdf $pdf, float $wAction, string $action, string $suffix): bool
    {
        if (method_exists($pdf, 'getNumLines') && $pdf->getNumLines($action, $wAction) > 1) {
            return false;
        }

        return ($pdf->GetStringWidth($action) + $pdf->GetStringWidth($suffix)) <= $wAction;
    }

    private static function writePrivateMark(
        RoleSheetPdf $pdf,
        float $x,
        float $y,
        float $width,
        string $action,
    ): void {
        $mark = $action === '' ? self::PRIVATE_MARK : self::PRIVATE_SUFFIX;
        $markH = $pdf->getStringHeight($width, $mark, false, true, '', 1);
        $pdf->SetTextColor(...self::PRIVATE_RGB);
        $pdf->SetFont($pdf->regularFont, '', 9);
        if ($action !== '' && self::suffixFitsSameLine($pdf, $width, $action, $mark)) {
            $pdf->SetXY($x + $pdf->GetStringWidth($action), $y);
            $pdf->MultiCell($width - $pdf->GetStringWidth($action), $markH, $mark, 0, 'L', false, 0);
        } else {
            $actionH = $action === ''
                ? 0.0
                : $pdf->getStringHeight($width, $action, false, true, '', 1);
            $pdf->SetXY($x, $y + $actionH);
            $pdf->MultiCell($width, $markH, $mark, 0, 'L', false, 0);
        }
        $pdf->SetTextColor(0, 0, 0);
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

    private static function programLogoFile(int $programId, ?string $stem): ?string
    {
        if (! function_exists('public_path')) {
            return null;
        }
        try {
            $key = (is_string($stem) && $stem !== '') ? $stem : ($programId > 0 ? $programId : null);
            if ($key === null) {
                return null;
            }
            $path = ProgramCatalog::logoPath($key, 'v');

            return is_file($path) ? $path : null;
        } catch (\Throwable) {
            return null;
        }
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
        $stored = RoleSheetPdf::pngFromBase64($document['qr_base64'] ?? null);
        if ($stored !== null) {
            return $stored;
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
