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
            'title_long' => 'FIRST LEGO League Challenge Event Test',
            'created_at' => '18.09.2026 06:52',
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
                            'action' => 'Robot-Match, Beta',
                            'strike' => [],
                            'italic' => ['Beta'],
                        ],
                        [
                            'start' => '09:20',
                            'end' => '09:35',
                            'room' => 'Halle',
                            'action' => 'Robot-Match, Gamma',
                            'strike' => [],
                            'italic' => ['Gamma'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(str_starts_with($pdf, '%PDF'));
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_render_accepts_public_page_qr_url(): void
    {
        $pdf = (new RoleSheetTcpdfRenderer)->render([
            'title_long' => 'Challenge Event Test',
            'created_at' => '18.09.2026 06:52',
            'public_url' => 'https://flow.hands-on-technology.org/test-event',
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

    public function test_render_reserves_wifi_qr_slot_when_missing(): void
    {
        $pdf = (new RoleSheetTcpdfRenderer)->render([
            'title_long' => 'Challenge Event Test',
            'created_at' => '18.09.2026 06:52',
            'public_url' => 'https://flow.hands-on-technology.org/test-event',
            'wifi_qr_base64' => null,
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

    public function test_long_table_continues_below_header_on_next_page(): void
    {
        $rows = [];
        for ($i = 0; $i < 80; $i++) {
            $rows[] = [
                'start' => '09:00',
                'end' => '09:15',
                'room' => 'Halle',
                'action' => 'Slot '.$i,
                'strike' => [],
            ];
        }

        $pdf = (new RoleSheetTcpdfRenderer)->render([
            'title_long' => 'Challenge Event Test',
            'created_at' => '18.09.2026 06:52',
            'sections' => [
                [
                    'subject' => 'Team: Alpha',
                    'noshow' => false,
                    'color_hex' => 'ed1c24',
                    'logo_stem' => null,
                    'ablauf' => $rows,
                ],
            ],
        ]);

        $this->assertTrue(str_starts_with($pdf, '%PDF'));
        $this->assertMatchesRegularExpression('/\/Count\s+[2-9]/', $pdf);
    }

    public function test_render_includes_room_hints_table(): void
    {
        $pdf = (new RoleSheetTcpdfRenderer)->render([
            'title_long' => 'Challenge Event Test',
            'created_at' => '18.09.2026 06:52',
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
                    'hinweise' => [
                        ['room' => 'Halle', 'hint' => 'Eingang über Hof'],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(str_starts_with($pdf, '%PDF'));
        $this->assertGreaterThan(1000, strlen($pdf));
    }
}
