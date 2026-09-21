<?php

namespace Tests\Unit;

use App\Print\RoomSheetTcpdfRenderer;
use PHPUnit\Framework\TestCase;

class RoomSheetTcpdfRendererTest extends TestCase
{
    public function test_render_returns_pdf_bytes(): void
    {
        $pdf = (new RoomSheetTcpdfRenderer)->render([
            'title_long' => 'FIRST LEGO League Challenge Event Test',
            'created_at' => '18.09.2026 16:20',
            'show_program_logos' => false,
            'sections' => [
                [
                    'subject' => 'Halle',
                    'color_hex' => 'F78B1F',
                    'logo_stem' => null,
                    'noshow' => false,
                    'team_columns' => [
                        [
                            'program_id' => 3,
                            'display_name' => 'Challenge',
                            'logo_stem' => null,
                            'teams' => [
                                ['label' => 'Alpha (0001)', 'noshow' => false],
                            ],
                        ],
                    ],
                    'activities' => [
                        [
                            'start' => '09:00',
                            'end' => '09:15',
                            'program_id' => 3,
                            'action' => 'Eröffnung',
                            'strike' => [],
                            'private' => false,
                        ],
                        [
                            'start' => '09:20',
                            'end' => '09:35',
                            'program_id' => 3,
                            'action' => 'Robot-Match, Alpha (0001) – Beta (0002)',
                            'strike' => [],
                            'private' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(str_starts_with($pdf, '%PDF'));
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_team_column_header_renders_official_html(): void
    {
        $pdf = (new RoomSheetTcpdfRenderer)->render([
            'title_long' => 'Challenge Event Test',
            'created_at' => '18.09.2026 16:20',
            'show_program_logos' => false,
            'sections' => [
                [
                    'subject' => 'Halle',
                    'color_hex' => 'F78B1F',
                    'logo_stem' => null,
                    'noshow' => false,
                    'team_columns' => [
                        [
                            'program_id' => 3,
                            'display_name' => 'Challenge',
                            'official_name' => '<i>FIRST</i> LEGO League Challenge',
                            'logo_stem' => null,
                            'teams' => [
                                ['label' => 'Alpha (0001)', 'noshow' => false],
                            ],
                        ],
                    ],
                    'activities' => [],
                ],
            ],
        ]);

        $this->assertTrue(str_starts_with($pdf, '%PDF'));
        $this->assertStringContainsString('Challenge Event Test', self::pdfText($pdf));
        $this->assertStringContainsString('FIRST', self::pdfText($pdf));
        $this->assertStringContainsString('LEGO League Challenge', self::pdfText($pdf));
    }

    private static function pdfText(string $pdf): string
    {
        $chunks = [];
        if (preg_match_all('/stream\r?\n(.*)\r?\nendstream/sU', $pdf, $matches)) {
            foreach ($matches[1] as $chunk) {
                $decoded = false;
                if (str_starts_with($chunk, "\x78")) {
                    $decoded = @gzuncompress($chunk);
                }
                $chunks[] = is_string($decoded) ? $decoded : $chunk;
            }
        }

        return preg_replace('/[^\x20-\x7E\n]/', '', implode("\n", $chunks)) ?? '';
    }
}
