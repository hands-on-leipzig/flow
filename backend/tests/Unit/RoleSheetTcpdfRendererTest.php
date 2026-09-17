<?php

namespace Tests\Unit;

use App\Print\RoleSheetTcpdfRenderer;
use PHPUnit\Framework\TestCase;

class RoleSheetTcpdfRendererTest extends TestCase
{
    public function test_render_returns_pdf_bytes(): void
    {
        $pdf = (new RoleSheetTcpdfRenderer)->render([
            'title_short' => 'Challenge Event Test',
            'sections' => [
                [
                    'subject' => 'Team: Alpha',
                    'noshow' => false,
                    'color_hex' => 'ed1c24',
                    'logo_stem' => null,
                    'ablauf' => [
                        [
                            'start' => '09:00',
                            'end' => '09:15',
                            'room' => 'Halle',
                            'action' => 'Beta',
                            'strike' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(str_starts_with($pdf, '%PDF'));
        $this->assertGreaterThan(1000, strlen($pdf));
    }
}
