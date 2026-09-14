<?php

namespace Tests\Unit;

use App\Support\PreviewTeamLabels;
use PHPUnit\Framework\TestCase;

class PreviewTeamLabelsTest extends TestCase
{
    public function test_tooltip_is_null_without_a_team_number(): void
    {
        $this->assertNull(PreviewTeamLabels::tooltip(0, 'Robo'));
        $this->assertNull(PreviewTeamLabels::tooltip(-1, 'Robo'));
    }

    public function test_tooltip_uses_trimmed_team_name(): void
    {
        $this->assertSame('Robo', PreviewTeamLabels::tooltip(3, '  Robo  '));
    }

    public function test_unregistered_copy_is_missing_team(): void
    {
        $this->assertSame('Fehlendes Team', PreviewTeamLabels::UNREGISTERED);
    }

    public function test_tooltip_uses_placeholder_when_name_is_missing(): void
    {
        $this->assertSame(PreviewTeamLabels::UNREGISTERED, PreviewTeamLabels::tooltip(2, null));
        $this->assertSame(PreviewTeamLabels::UNREGISTERED, PreviewTeamLabels::tooltip(2, '   '));
    }

    public function test_tooltip_for_looks_up_program_slot(): void
    {
        $map = [3 => [1 => 'Alpha']];

        $this->assertSame('Alpha', PreviewTeamLabels::tooltipFor(3, 1, $map));
        $this->assertSame(PreviewTeamLabels::UNREGISTERED, PreviewTeamLabels::tooltipFor(3, 2, $map));
        $this->assertNull(PreviewTeamLabels::tooltipFor(3, 0, $map));
    }
}
