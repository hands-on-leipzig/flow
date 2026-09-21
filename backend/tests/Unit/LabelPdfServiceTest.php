<?php

namespace Tests\Unit;

use App\Services\LabelPdfService;
use Tests\TestCase;

class LabelPdfServiceTest extends TestCase
{
    public function test_name_tags_pdf_starts_with_pdf_header(): void
    {
        $pdf = (new LabelPdfService())->generateNameTags(
            [[
                'person_name' => 'Max Mustermann',
                'team_name' => 'Team Beispiel',
                'program' => 'default',
            ]],
            null,
            [],
            [],
        );

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }
}
