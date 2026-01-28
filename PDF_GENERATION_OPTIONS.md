# PDF Generation Options for Precise Label Positioning

## Problem Statement
DomPDF (currently used) has known issues with precise mm-based positioning, especially with absolute positioning and table layouts. The Avery L4785 label template requires exact positioning:
- Page: 210mm × 297mm
- Margins: 13.5mm (top/bottom), 17.5mm (left/right)
- Labels: 80mm × 50mm
- Column gap: 15mm
- Row gap: 5mm

## Current Setup
- **Library**: `barryvdh/laravel-dompdf` (wraps `dompdf/dompdf`)
- **Approach**: HTML/CSS via Blade templates
- **Issue**: Inaccurate positioning despite correct CSS values

## Research Findings

### Option 1: TCPDF (Recommended for Precise Positioning)
**Library**: `tecnickcom/tcpdf` or `laravel-labels` (TCPDF wrapper)

**Pros:**
- ✅ **Native mm unit support** - designed for precise positioning
- ✅ **Direct coordinate-based API** - `SetXY(x, y)` in millimeters
- ✅ **Excellent for labels** - many label generation examples
- ✅ **No HTML/CSS parsing** - direct PDF generation = more reliable
- ✅ **Active development** - well-maintained
- ✅ **Laravel wrapper available**: `codedge/laravel-labels` or `rpungello/laravel-labels`

**Cons:**
- ❌ Requires rewriting from Blade template to PHP code
- ❌ More verbose code (but more precise)
- ❌ Need to handle image embedding manually

**Example Code Structure:**
```php
use TCPDF;

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
$pdf->SetMargins(17.5, 13.5, 17.5); // left, top, right
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// Label 1, Row 1, Column 1
$pdf->SetXY(17.5, 13.5); // x, y in mm
$pdf->SetCell(80, 50, $personName, 1, 0, 'L', false, '', 0, false, 'T', 'M');
// ... etc
```

**Installation:**
```bash
composer require tecnickcom/tcpdf
# OR
composer require codedge/laravel-labels
```

---

### Option 2: FPDF / setasign/fpdf
**Library**: `setasign/fpdf` (already in composer.lock!)

**Pros:**
- ✅ **Already available** in project (`setasign/fpdf` in composer.lock)
- ✅ **Simple API** - lightweight and straightforward
- ✅ **Native mm support** - default unit is millimeters
- ✅ **Direct positioning** - `SetXY(x, y)` for exact placement
- ✅ **No dependencies** - pure PHP, no external tools

**Cons:**
- ❌ Less feature-rich than TCPDF
- ❌ Basic image support (may need additional libraries for advanced image handling)
- ❌ Requires rewriting from Blade template

**Example Code Structure:**
```php
use setasign\Fpdi\Fpdf\Fpdf;

$pdf = new Fpdf('P', 'mm', 'A4');
$pdf->SetMargins(17.5, 13.5, 17.5);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// Label positioning
$pdf->SetXY(17.5, 13.5);
$pdf->SetFont('Arial', 'B', 18);
$pdf->Cell(80, 50, $personName, 1, 0, 'L');
```

**Installation:**
```bash
# Already available! Just use it.
```

---

### Option 3: mPDF
**Library**: `mpdf/mpdf`

**Pros:**
- ✅ **HTML/CSS support** - can keep Blade templates
- ✅ **Better CSS support** than DomPDF
- ✅ **More reliable positioning** than DomPDF

**Cons:**
- ❌ Still HTML-based (may have similar issues)
- ❌ Larger library size
- ❌ May still have positioning quirks

**Installation:**
```bash
composer require mpdf/mpdf
```

---

### Option 4: Keep DomPDF but Use Different Approach
**Library**: Continue with `barryvdh/laravel-dompdf`

**Possible Solutions:**
1. **Convert mm to points (pt)** - DomPDF may handle points better
   - 1mm = 2.83465pt
   - Example: 17.5mm = 49.606pt

2. **Use fixed positioning instead of absolute**
   - May work better in DomPDF

3. **Generate SVG first, then embed in PDF**
   - More complex but potentially more precise

**Pros:**
- ✅ No library changes needed
- ✅ Keep existing Blade template approach

**Cons:**
- ❌ May still have precision issues
- ❌ Workarounds may be fragile

---

## Recommendation

### **Primary Recommendation: TCPDF**

**Why:**
1. **Purpose-built for precise positioning** - designed for labels, forms, and exact layouts
2. **Native mm support** - no unit conversion issues
3. **Proven track record** - widely used for label generation
4. **Direct API** - no HTML/CSS parsing layer = more reliable
5. **Laravel wrapper available** - `codedge/laravel-labels` or similar

**Implementation Approach:**
1. Create a new service class: `LabelPdfService`
2. Replace Blade template with PHP code using TCPDF API
3. Use direct coordinate positioning:
   ```php
   // Column 1, Row 1
   $pdf->SetXY(17.5, 13.5);
   $pdf->Cell(80, 50, $content, 1, 0, 'L');
   
   // Column 2, Row 1
   $pdf->SetXY(112.5, 13.5); // 17.5 + 80 + 15
   $pdf->Cell(80, 50, $content, 1, 0, 'L');
   ```

**Migration Effort:**
- Medium effort (2-4 hours)
- Rewrite template logic to PHP
- Test positioning accuracy
- Handle image embedding (data URIs or file paths)

---

### **Alternative: FPDF (if you want to use existing dependency)**

Since `setasign/fpdf` is already in composer.lock, this is a viable quick option:

**Pros:**
- ✅ Already available
- ✅ Simple API
- ✅ Native mm support

**Cons:**
- ❌ Less feature-rich than TCPDF
- ❌ May need additional libraries for advanced image handling

---

## Implementation Plan (TCPDF)

### Step 1: Install TCPDF
```bash
cd backend
composer require tecnickcom/tcpdf
```

### Step 2: Create Service Class
Create `backend/app/Services/LabelPdfService.php`:
- Methods for label positioning
- Image embedding
- Text rendering
- Multi-page handling

### Step 3: Update Controller
Modify `LabelController::nameTagsPdf()`:
- Replace Blade template rendering
- Use `LabelPdfService` instead
- Keep same data preparation logic

### Step 4: Test & Verify
- Generate PDF
- Measure actual positions
- Adjust coordinates if needed
- Remove debug borders

---

## Code Example (TCPDF Implementation)

```php
<?php

namespace App\Services;

use TCPDF;

class LabelPdfService
{
    public function generateNameTags(array $nameTags, $seasonLogo, $organizerLogos, array $programLogoCache): string
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetMargins(17.5, 13.5, 17.5);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        
        $labelsPerPage = 10;
        $totalLabels = count($nameTags);
        $totalPages = ceil($totalLabels / $labelsPerPage);
        
        for ($page = 0; $page < $totalPages; $page++) {
            if ($page > 0) {
                $pdf->AddPage();
            }
            
            $startIdx = $page * $labelsPerPage;
            $endIdx = min($startIdx + $labelsPerPage, $totalLabels);
            $pageLabels = array_slice($nameTags, $startIdx, $endIdx - $startIdx);
            
            foreach ($pageLabels as $index => $nameTag) {
                $col = ($index % 2) + 1;
                $row = floor($index / 2) + 1;
                
                // Calculate position
                $x = $col === 1 ? 17.5 : 112.5; // 17.5 + 80 + 15
                $y = 13.5 + (($row - 1) * 55); // 13.5 + (row-1) * (50 + 5)
                
                // Draw label border (for debugging)
                $pdf->Rect($x, $y, 80, 50, 'D');
                
                // Add content
                $pdf->SetXY($x + 2, $y + 5); // Internal padding
                $pdf->SetFont('helvetica', 'B', 18);
                $pdf->Cell(76, 10, $nameTag['person_name'], 0, 1);
                
                $pdf->SetXY($x + 2, $pdf->GetY());
                $pdf->SetFont('helvetica', '', 12);
                $pdf->Cell(76, 8, $nameTag['team_name'], 0, 1);
                
                // Add logos at bottom
                $logoY = $y + 50 - 20; // Bottom area
                // ... logo positioning code
            }
        }
        
        return $pdf->Output('', 'S'); // Return as string
    }
}
```

---

## Next Steps

1. **Decision**: Choose TCPDF, FPDF, or try DomPDF workaround
2. **If TCPDF**: I'll implement the service class and update the controller
3. **If FPDF**: I'll use the existing setasign/fpdf dependency
4. **If DomPDF workaround**: I'll try point-based positioning or SVG approach

**Recommendation**: Go with **TCPDF** for reliable, precise positioning that matches the specification exactly.
