<?php

namespace Tests\Unit;

use App\Enums\ExploreMode;
use App\Support\TimeColumnVisibility;
use Tests\TestCase;

class TimeColumnVisibilityTest extends TestCase
{
    public function test_decoupled_challenge_exposes_c_start_opening(): void
    {
        $openings = TimeColumnVisibility::editableOpeningParams(
            ExploreMode::NONE->value,
            1,
            0,
        );

        $this->assertContains('c_start_opening', $openings);
        $this->assertNotContains('g_start_opening', $openings);
    }

    public function test_integrated_morning_exposes_g_start_opening(): void
    {
        $openings = TimeColumnVisibility::editableOpeningParams(
            ExploreMode::INTEGRATED_MORNING->value,
            1,
            0,
        );

        $this->assertContains('g_start_opening', $openings);
        $this->assertNotContains('c_start_opening', $openings);
    }

    public function test_decoupled_both_maps_e1_and_e2_openings(): void
    {
        $openings = TimeColumnVisibility::editableOpeningParams(
            ExploreMode::DECOUPLED_BOTH->value,
            1,
            0,
        );

        $this->assertContains('e1_start_opening', $openings);
        $this->assertContains('e2_start_opening', $openings);
        $this->assertContains('c_start_opening', $openings);
    }

    public function test_prefix_for_param(): void
    {
        $this->assertSame('g', TimeColumnVisibility::prefixForParam('g_start_opening'));
        $this->assertSame('e1', TimeColumnVisibility::prefixForParam('e1_start_opening'));
        $this->assertNull(TimeColumnVisibility::prefixForParam('g_duration_opening'));
    }
}
