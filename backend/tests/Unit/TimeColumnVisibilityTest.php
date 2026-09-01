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
        $this->assertSame('c+f8', TimeColumnVisibility::prefixForParam('c+f8_start_opening'));
        $this->assertNull(TimeColumnVisibility::prefixForParam('g_duration_opening'));
    }

    public function test_challenge_and_future_without_explore_use_cf8_joint_times(): void
    {
        $fields = TimeColumnVisibility::fieldsForModes(ExploreMode::NONE->value, 1, 1);

        $this->assertTrue($fields['c+f8_start_opening']['editable']);
        $this->assertTrue($fields['c+f8_duration_opening']['editable']);
        $this->assertTrue($fields['c+f8_duration_awards']['editable']);
        $this->assertFalse($fields['g_duration_awards']['editable']);
        $this->assertFalse($fields['c_duration_awards']['editable']);
        $this->assertFalse($fields['f8_duration_awards']['editable']);
    }

    public function test_challenge_and_future_with_explore_keep_g_awards(): void
    {
        $fields = TimeColumnVisibility::fieldsForModes(ExploreMode::INTEGRATED_AFTERNOON->value, 1, 1);

        $this->assertTrue($fields['g_duration_awards']['editable']);
        $this->assertFalse($fields['c+f8_duration_awards']['editable']);
        $this->assertFalse($fields['c_duration_awards']['editable']);
        $this->assertFalse($fields['f8_duration_awards']['editable']);
    }
}
