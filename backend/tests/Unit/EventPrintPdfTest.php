<?php

namespace Tests\Unit;

use App\Print\EventPrintPdf;
use PHPUnit\Framework\TestCase;

class EventPrintPdfTest extends TestCase
{
    public function test_body_starts_below_header_content(): void
    {
        $this->assertGreaterThan(EventPrintPdf::HEADER_CONTENT_HEIGHT, EventPrintPdf::HEADER_BODY_MARGIN);

        $pdf = EventPrintPdf::make('Test');
        $pdf->AddPage();

        $this->assertEqualsWithDelta(EventPrintPdf::HEADER_BODY_MARGIN, $pdf->GetY(), 0.05);
        $this->assertEqualsWithDelta(
            $pdf->getPageHeight() - EventPrintPdf::BODY_BOTTOM_MARGIN,
            $pdf->bodyBottom(),
            0.05,
        );
    }

    public function test_overflows_uses_body_bottom_not_page_edge(): void
    {
        $pdf = EventPrintPdf::make('Test');
        $pdf->AddPage();
        $pdf->SetY($pdf->bodyBottom() - 5);

        $this->assertFalse($pdf->overflows(4));
        $this->assertTrue($pdf->overflows(6));

        $pdf->ensureSpace(6);
        $this->assertSame(2, $pdf->getNumPages());
        $this->assertEqualsWithDelta(EventPrintPdf::HEADER_BODY_MARGIN, $pdf->GetY(), 0.05);
    }

    public function test_time_columns_fit_hhmm_without_legacy_padding(): void
    {
        $pdf = EventPrintPdf::make('Test');
        $pdf->AddPage();
        $width = $pdf->timeColumnWidth();

        $this->assertLessThan(14.0, $width);
        $this->assertGreaterThan(8.0, $width);
    }
}
