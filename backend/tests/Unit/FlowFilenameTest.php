<?php

namespace Tests\Unit;

use App\Helpers\FlowFilename;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlowFilenameTest extends TestCase
{
    #[Test]
    public function it_builds_the_standard_pattern(): void
    {
        $this->assertSame(
            'FLOW_Plan_(25.08.26).pdf',
            FlowFilename::make('Plan', 'pdf', '25.08.26')
        );
    }

    #[Test]
    public function it_transliterates_umlauts_and_strips_specials(): void
    {
        $this->assertSame(
            'FLOW_Raeume_(25.08.26).pdf',
            FlowFilename::make('Räume', 'pdf', '25.08.26')
        );

        $this->assertSame(
            'FLOW_People_Teams_Future_8_(25.08.26).csv',
            FlowFilename::make('People_Teams_Future 8+', 'csv', '25.08.26')
        );
    }

    #[Test]
    public function it_formats_iso_dates_in_berlin(): void
    {
        $this->assertSame(
            '10.07.25',
            FlowFilename::formatDate('2025-07-10')
        );

        $this->assertSame(
            'FLOW_Raumnutzung_(10.07.25).csv',
            FlowFilename::make('Raumnutzung', 'csv', Carbon::parse('2025-07-10'))
        );
    }
}
