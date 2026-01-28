<?php

namespace App\Services;

use TCPDF;
use Illuminate\Support\Facades\Log;

class LabelPdfService
{
    /**
     * Generate name tags PDF using TCPDF for precise positioning
     * 
     * Specification (Avery L4785) - EXACT measurements:
     * - Page: 210mm × 297mm (A4 portrait)
     * - Margins: top 13.5mm, bottom 13.5mm, left 17.5mm, right 17.5mm
     * - Label: 80mm × 50mm
     * - Column gap: 15mm (between label edges)
     * - Row gap: 5mm (between label edges)
     * - Label padding: top 5mm, left 2mm, right 2mm (content area: 76mm wide)
     * 
     * TCPDF uses absolute coordinates from top-left (0,0) in mm units.
     * All coordinates are calculated exactly per specification.
     * 
     * @param array $nameTags Array of name tag data
     * @param string|null $seasonLogo Data URI or file path
     * @param array $organizerLogos Array of data URIs or file paths
     * @param array $programLogoCache Array of program logos (data URIs or file paths)
     * @param bool $showBorders Show label borders for debugging (normally false)
     * @param string|null $headerLeft Optional left text for top margin
     * @param string|null $headerRight Optional right text for top margin
     * @return string PDF binary data
     */
    public function generateNameTags(
        array $nameTags,
        ?string $seasonLogo,
        array $organizerLogos,
        array $programLogoCache,
        // Debug option: set to `true` to draw a thin rectangle (80mm × 50mm) around each label.
        // Useful for measuring alignment against Avery L4785 / print settings.
        bool $showBorders = false,
        ?string $headerLeft = null,
        ?string $headerRight = null
    ): string {
        if (empty($nameTags)) {
            throw new \InvalidArgumentException('No name tags provided');
        }
        
        // Start output buffering to catch any TCPDF errors
        ob_start();
        
        try {
            // Configure TCPDF paths - use TCPDF's own fonts directory
            if (!defined('K_PATH_MAIN')) {
                // Point to TCPDF's vendor directory
                // From app/Services: __DIR__ = backend/app/Services, so go up 2 levels to backend, then vendor
                $tcpdfPath = dirname(__DIR__, 2) . '/vendor/tecnickcom/tcpdf';
                define('K_PATH_MAIN', $tcpdfPath . '/');
            }
            if (!defined('K_PATH_URL')) {
                define('K_PATH_URL', '');
            }
            if (!defined('K_PATH_FONTS')) {
                // Use TCPDF's built-in fonts directory (contains dejavusans and other fonts)
                $tcpdfPath = dirname(__DIR__, 2) . '/vendor/tecnickcom/tcpdf';
                define('K_PATH_FONTS', $tcpdfPath . '/fonts/');
            }
            if (!defined('K_PATH_CACHE')) {
                // Use storage for cache
                $cachePath = dirname(__DIR__, 2) . '/storage/app/tcpdf/cache';
                define('K_PATH_CACHE', $cachePath . '/');
            }
            
            // Create cache directory if it doesn't exist
            if (!is_dir(K_PATH_CACHE)) {
                @mkdir(K_PATH_CACHE, 0755, true);
            }
            
            // Create PDF with EXACT A4 dimensions: 210mm × 297mm
            // TCPDF uses mm units natively - coordinates are exact millimeters
            $pdf = new TCPDF('P', 'mm', array(210, 297), true, 'UTF-8', false);
            
            // Set font immediately - dejavusans is built into TCPDF
            $pdf->SetFont('dejavusans', '', 10);
            
            // Set margins to 0 - we use absolute coordinates from page top-left (0,0)
            // This ensures Rect(), Image(), and SetXY() use exact mm coordinates
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false);
            $pdf->SetPrintHeader(false);
            $pdf->SetPrintFooter(false);
            
            // Disable any scaling - we want 1:1 mm measurements
            $pdf->setPageUnit('mm');
            
            $labelsPerPage = 10; // 2 columns × 5 rows
            $totalLabels = count($nameTags);
            $totalPages = ceil($totalLabels / $labelsPerPage);
            
            // Add first page (font must be set before this)
            $pdf->AddPage();
            
            // Ensure font is set again after AddPage (some TCPDF versions reset it)
            $pdf->SetFont('dejavusans', '', 10);
            
            for ($page = 0; $page < $totalPages; $page++) {
                if ($page > 0) {
                    $pdf->AddPage();
                    // Ensure font is set after each AddPage
                    $pdf->SetFont('dejavusans', '', 10);
                }
                
                $startIdx = $page * $labelsPerPage;
                $endIdx = min($startIdx + $labelsPerPage, $totalLabels);
                $pageLabels = array_slice($nameTags, $startIdx, $endIdx - $startIdx);
                
                // Render header text in top margin (0 to 13.5mm)
                if ($headerLeft !== null || $headerRight !== null) {
                    $this->renderHeaderText($pdf, $headerLeft, $headerRight);
                }
                
                // Render footer text in bottom margin (283.5mm to 297mm)
                $this->renderFooterText($pdf, $page + 1, $totalPages);
                
                foreach ($pageLabels as $index => $nameTag) {
                    // Calculate position: column (1 or 2), row (1-5)
                    $col = ($index % 2) + 1;
                    $row = floor($index / 2) + 1;
                    
                    // Calculate X position
                    // Column 1: left margin (17.5mm)
                    // Column 2: left margin (17.5mm) + label width (80mm) + gap (15mm) = 112.5mm
                    $x = $col === 1 ? 17.5 : 112.5;
                    
                    // Calculate Y position
                    // Row 1: top margin (13.5mm)
                    // Row 2: 13.5 + 50 + 5 = 68.5mm
                    // Row 3: 13.5 + (50+5)*2 = 123.5mm
                    // Row 4: 13.5 + (50+5)*3 = 178.5mm
                    // Row 5: 13.5 + (50+5)*4 = 233.5mm
                    $y = 13.5 + (($row - 1) * 55); // 50mm label + 5mm gap
                    
                    // Draw label border for debugging (enable via $showBorders parameter)
                    if ($showBorders) {
                        $pdf->Rect($x, $y, 80, 50, 'D');
                    }
                    
                    // Render label content
                    $this->renderLabel($pdf, $nameTag, $x, $y, $seasonLogo, $organizerLogos, $programLogoCache);
                }
            }
            
            // Return PDF as string
            $pdfData = $pdf->Output('', 'S');
            
            // Clear any output buffer content
            $bufferContent = ob_get_clean();
            if (!empty($bufferContent)) {
                Log::warning('TCPDF output buffer had content', ['content' => substr($bufferContent, 0, 200)]);
            }
            
            if (empty($pdfData) || strlen($pdfData) < 100) {
                Log::error('TCPDF generated empty or invalid PDF', [
                    'size' => strlen($pdfData ?? ''),
                    'nameTags_count' => count($nameTags),
                    'buffer_content' => substr($bufferContent ?? '', 0, 200)
                ]);
                throw new \Exception('Failed to generate PDF: output is empty or too small. Size: ' . strlen($pdfData ?? ''));
            }
            
            return $pdfData;
        } catch (\Throwable $e) {
            // Clean output buffer
            $bufferContent = ob_get_clean();
            if (!empty($bufferContent)) {
                Log::warning('TCPDF error output buffer content', ['content' => substr($bufferContent, 0, 500)]);
            }
            
            Log::error('Error in LabelPdfService::generateNameTags', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * Render a single label
     */
    private function renderLabel(
        TCPDF $pdf,
        array $nameTag,
        float $x,
        float $y,
        ?string $seasonLogo,
        array $organizerLogos,
        array $programLogoCache
    ): void {
        // Label dimensions
        $labelWidth = 80;
        $labelHeight = 50;
        $paddingTop = 5;
        $paddingLeft = 5;
        $paddingRight = 5;
        $paddingBottom = 5;
        $contentWidth = $labelWidth - $paddingLeft - $paddingRight; // 70mm
        
        // Set position for content (with padding)
        // Use absolute coordinates (margins are 0, so SetXY uses absolute coords)
        $contentX = $x + $paddingLeft;
        $contentY = $y + $paddingTop;
        
        // Person name (large, bold, top)
        // SetXY uses absolute coordinates when margins are 0
        $pdf->SetXY($contentX, $contentY);
        $pdf->SetFont('dejavusans', 'B', 18);
        $pdf->SetTextColor(0, 0, 0);
        // Use MultiCell with simpler parameters
        $pdf->MultiCell($contentWidth, 8, $nameTag['person_name'], 0, 'L', false, 1);
        
        // Get current Y after person name
        $currentY = $pdf->GetY();
        
        // Team name (smaller, below person name)
        $pdf->SetXY($contentX, $currentY);
        $pdf->SetFont('dejavusans', '', 12);
        $pdf->SetTextColor(51, 51, 51);
        // Use MultiCell with simpler parameters
        $pdf->MultiCell($contentWidth, 6, $nameTag['team_name'], 0, 'L', false, 1);
        
        // Logos at bottom of label - positioned at bottom padding (5mm from bottom)
        $logoMaxHeight = 15;
        $logoMaxWidth = 20;
        
        // Get program logo
        $programLogo = $programLogoCache[$nameTag['program']] ?? null;
        
        // Collect all valid logos with their paths
        $logoPaths = [];
        $tempFiles = []; // Track temp files for cleanup
        
        $logos = array_filter([
            $programLogo,
            $seasonLogo,
            ...$organizerLogos
        ]);
        
        foreach ($logos as $logo) {
            if (!$logo) {
                continue;
            }
            
            try {
                $imagePath = null;
                
                // Check if it's a data URI or file path
                if (strpos($logo, 'data:image') === 0) {
                    // Data URI - extract base64 data and write to temp file
                    $imageData = $this->extractImageFromDataUri($logo);
                    if ($imageData) {
                        // Create temporary file for TCPDF
                        $tempFile = tempnam(sys_get_temp_dir(), 'tcpdf_img_');
                        file_put_contents($tempFile, $imageData);
                        $imagePath = $tempFile;
                        $tempFiles[] = $tempFile;
                    }
                } else {
                    // File path
                    if (file_exists($logo)) {
                        $imagePath = $logo;
                    }
                }
                
                if ($imagePath) {
                    $logoPaths[] = $imagePath;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to prepare logo for label', [
                    'logo' => substr($logo, 0, 50) . '...',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Calculate dimensions for all logos and distribute them horizontally
        $logoData = [];
        $totalWidth = 0;
        
        foreach ($logoPaths as $imagePath) {
            try {
                // Get image dimensions to calculate aspect ratio
                $imageInfo = @getimagesize($imagePath);
                if ($imageInfo && $imageInfo[0] > 0 && $imageInfo[1] > 0) {
                    $originalWidth = $imageInfo[0];
                    $originalHeight = $imageInfo[1];
                    $aspectRatio = $originalWidth / $originalHeight;
                    
                    // Calculate dimensions maintaining aspect ratio
                    // Constrain by max width first, then check if height exceeds max
                    $calculatedWidth = $logoMaxWidth;
                    $calculatedHeight = $logoMaxWidth / $aspectRatio;
                    
                    // If height exceeds max, constrain by height instead
                    if ($calculatedHeight > $logoMaxHeight) {
                        $calculatedHeight = $logoMaxHeight;
                        $calculatedWidth = $logoMaxHeight * $aspectRatio;
                    }
                    
                    $logoData[] = [
                        'path' => $imagePath,
                        'width' => $calculatedWidth,
                        'height' => $calculatedHeight
                    ];
                    $totalWidth += $calculatedWidth;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get logo dimensions', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Distribute logos evenly across available width
        $logoCount = count($logoData);
        if ($logoCount > 0) {
            // Find maximum logo height to align all logos at bottom
            $maxLogoHeight = 0;
            foreach ($logoData as $logo) {
                if ($logo['height'] > $maxLogoHeight) {
                    $maxLogoHeight = $logo['height'];
                }
            }
            
            // Position logos so their bottom edges are at bottom padding (5mm from bottom)
            $logoY = $y + $labelHeight - $paddingBottom - $maxLogoHeight;
            
            $availableWidth = $contentWidth; // 70mm (with 5mm padding on each side)
            $totalLogoWidth = $totalWidth;
            $remainingSpace = $availableWidth - $totalLogoWidth;
            $gapBetweenLogos = $logoCount > 1 ? $remainingSpace / ($logoCount - 1) : 0;
            
            $currentX = $contentX;
            
            foreach ($logoData as $index => $logo) {
                // Render logo (bottom edges align at 5mm from bottom)
                $pdf->Image($logo['path'], $currentX, $logoY + ($maxLogoHeight - $logo['height']), $logo['width'], $logo['height'], '', '', '', false, 300, '', false, false, 0);
                
                // Move to next position (logo width + gap)
                $currentX += $logo['width'] + $gapBetweenLogos;
            }
        }
        
        // Clean up temp files
        foreach ($tempFiles as $tempFile) {
            @unlink($tempFile);
        }
    }
    
    /**
     * Extract image binary data from data URI
     */
    private function extractImageFromDataUri(string $dataUri): ?string
    {
        if (strpos($dataUri, 'data:image') !== 0) {
            return null;
        }
        
        // Extract base64 data
        $parts = explode(',', $dataUri, 2);
        if (count($parts) !== 2) {
            return null;
        }
        
        $base64Data = $parts[1];
        $imageData = base64_decode($base64Data, true);
        
        if ($imageData === false) {
            return null;
        }
        
        return $imageData;
    }
    
    /**
     * Render header text in top margin (0 to 13.5mm)
     * Uses same left/right margins as labels (17.5mm)
     */
    private function renderHeaderText(TCPDF $pdf, ?string $leftText, ?string $rightText): void
    {
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(0, 0, 0); // Black color
        
        // Position at 6mm from top (middle of 13.5mm margin)
        $y = 6;
        
        // Use same margins as labels: left 17.5mm, right 17.5mm
        $leftMargin = 17.5;
        $rightMargin = 17.5;
        $contentWidth = 210 - $leftMargin - $rightMargin; // 175mm
        
        // Left text
        if ($leftText !== null) {
            $pdf->SetXY($leftMargin, $y);
            $pdf->Cell($contentWidth, 0, $leftText, 0, 0, 'L');
        }
        
        // Right text
        if ($rightText !== null) {
            $pdf->SetXY($leftMargin, $y);
            $pdf->Cell($contentWidth, 0, $rightText, 0, 0, 'R');
        }
        
        // Reset font size for labels
        $pdf->SetFont('dejavusans', '', 10);
    }
    
    /**
     * Render footer text in bottom margin (283.5mm to 297mm)
     * Uses same left/right margins as labels (17.5mm)
     */
    private function renderFooterText(TCPDF $pdf, int $pageNum, int $totalPages): void
    {
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetTextColor(0, 0, 0); // Black color
        
        // Position at 291mm from top (middle of bottom 13.5mm margin)
        $y = 291;
        
        // Use same margins as labels: left 17.5mm, right 17.5mm
        $leftMargin = 17.5;
        $rightMargin = 17.5;
        $contentWidth = 210 - $leftMargin - $rightMargin; // 175mm
        
        // Left: Page number
        $pageText = "Seite {$pageNum} von {$totalPages}";
        $pdf->SetXY($leftMargin, $y);
        $pdf->Cell($contentWidth, 0, $pageText, 0, 0, 'L');
        
        // Right: Timestamp
        $timestamp = now()->format('d.m.Y H:i');
        $timestampText = "Stand {$timestamp}";
        $pdf->SetXY($leftMargin, $y);
        $pdf->Cell($contentWidth, 0, $timestampText, 0, 0, 'R');
        
        // Reset font size for labels
        $pdf->SetFont('dejavusans', '', 10);
    }
}
