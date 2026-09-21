<?php

namespace Tests\Unit;

use App\Print\TeamlisteTcpdfRenderer;
use PHPUnit\Framework\TestCase;

class TeamlisteTcpdfRendererTest extends TestCase
{
    public function test_program_heading_renders_official_html(): void
    {
        $pdf = (new TeamlisteTcpdfRenderer)->render([
            'title_long' => 'Challenge Event Test',
            'created_at' => '18.09.2026 16:20',
            'sections' => [
                [
                    'subject' => 'Teamliste',
                    'color_hex' => 'F78B1F',
                    'display_name' => 'Challenge',
                    'official_name' => '<i>FIRST</i> LEGO League Challenge',
                    'logo_stem' => null,
                    'group_header' => 'Jury-Gruppe',
                    'rows' => [
                        [
                            'number' => '0001',
                            'name' => 'Alpha',
                            'noshow' => false,
                            'room' => 'Aula',
                            'group' => 'A',
                            'coaches' => 'Ada',
                            'phone' => '',
                            'coach_count' => '1',
                            'player_count' => '4',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(str_starts_with($pdf, '%PDF'));
        $this->assertGreaterThan(1000, strlen($pdf));
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
