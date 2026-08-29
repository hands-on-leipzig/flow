<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Support\OverviewPlanStyle;
use App\Support\ProgramCatalog;
use PHPUnit\Framework\TestCase;

class OverviewPlanStyleTest extends TestCase
{
    public function test_mix_hex_with_white_is_pure_blend(): void
    {
        $this->assertSame('#FFFFFF', ProgramCatalog::mixHexWithWhite('ED1C24', 0.0));
        $this->assertSame('#ED1C24', ProgramCatalog::mixHexWithWhite('#ED1C24', 1.0));
        $this->assertSame('#F5F5F5', ProgramCatalog::mixHexWithWhite('not-a-color', 0.1));
    }

    public function test_allgemein_column_maps_future_8_to_four(): void
    {
        $this->assertNull(OverviewPlanStyle::allgemeinColumn(FirstProgram::JOINT->value));
        $this->assertSame('Allgemein-2', OverviewPlanStyle::allgemeinColumn(FirstProgram::EXPLORE->value));
        $this->assertSame('Allgemein-3', OverviewPlanStyle::allgemeinColumn(FirstProgram::CHALLENGE->value));
        $this->assertSame('Allgemein-4', OverviewPlanStyle::allgemeinColumn(FirstProgram::FUTURE_8->value));
        $this->assertSame('Allgemein-1', OverviewPlanStyle::allgemeinColumn(FirstProgram::DISCOVER->value));
    }

    public function test_cell_colors_follow_catalog_borders(): void
    {
        $explore = '#00A651';
        $challenge = '#ED1C24';
        $future8 = '#CC8800';

        $exploreCell = OverviewPlanStyle::cellColorsFromCatalog('Explore', $explore, $challenge, $future8);
        $this->assertSame($explore, $exploreCell['border']);
        $this->assertSame(ProgramCatalog::mixHexWithWhite($explore, OverviewPlanStyle::PROGRAM_TINT), $exploreCell['bg']);

        $challengeCell = OverviewPlanStyle::cellColorsFromCatalog('Challenge', $explore, $challenge, $future8);
        $this->assertSame($challenge, $challengeCell['border']);

        $robot = OverviewPlanStyle::cellColorsFromCatalog('Robot-Game', $explore, $challenge, $future8);
        $this->assertSame($challenge, $robot['border']);
        $this->assertSame(ProgramCatalog::mixHexWithWhite($challenge, OverviewPlanStyle::FIELD_TINT), $robot['bg']);

        $future = OverviewPlanStyle::cellColorsFromCatalog('Future 8+', $explore, $challenge, $future8);
        $this->assertSame($future8, $future['border']);
        $this->assertSame(ProgramCatalog::mixHexWithWhite($future8, OverviewPlanStyle::PROGRAM_TINT), $future['bg']);

        $game = OverviewPlanStyle::cellColorsFromCatalog('Game', $explore, $challenge, $future8);
        $this->assertSame($future8, $game['border']);
        $this->assertSame(ProgramCatalog::mixHexWithWhite($future8, OverviewPlanStyle::FIELD_TINT), $game['bg']);
    }

    public function test_allgemein_suffix_keeps_gray_tint_and_program_border(): void
    {
        $explore = '#00A651';
        $challenge = '#ED1C24';
        $future8 = '#CC8800';

        $a2 = OverviewPlanStyle::cellColorsFromCatalog('Allgemein-2', $explore, $challenge, $future8);
        $this->assertSame(OverviewPlanStyle::GRAY_TINT, $a2['bg']);
        $this->assertSame($explore, $a2['border']);

        $a3 = OverviewPlanStyle::cellColorsFromCatalog('Allgemein-3', $explore, $challenge, $future8);
        $this->assertSame(OverviewPlanStyle::GRAY_TINT, $a3['bg']);
        $this->assertSame($challenge, $a3['border']);

        $a4 = OverviewPlanStyle::cellColorsFromCatalog('Allgemein-4', $explore, $challenge, $future8);
        $this->assertSame(OverviewPlanStyle::GRAY_TINT, $a4['bg']);
        $this->assertSame($future8, $a4['border']);

        $joint = OverviewPlanStyle::cellColorsFromCatalog('Allgemein', $explore, $challenge, $future8);
        $this->assertSame(OverviewPlanStyle::GRAY_TINT, $joint['bg']);
        $this->assertSame(OverviewPlanStyle::GRAY_BORDER, $joint['border']);
    }

    public function test_live_challenge_stays_hardcoded_purple(): void
    {
        $colors = OverviewPlanStyle::cellColorsFromCatalog('Live Challenge', '#00A651', '#ED1C24', '#CC8800');
        $this->assertSame(OverviewPlanStyle::LIVE_CHALLENGE_TINT, $colors['bg']);
        $this->assertSame(OverviewPlanStyle::LIVE_CHALLENGE_BORDER, $colors['border']);
    }

    public function test_slot_cells_use_pale_pint_fill_and_program_border(): void
    {
        $explore = '#00A651';
        $challenge = '#ED1C24';
        $future8 = '#CC8800';

        $this->assertSame('Slot-Explore', OverviewPlanStyle::slotStyleColumn(FirstProgram::EXPLORE->value));
        $this->assertSame('Slot-Challenge', OverviewPlanStyle::slotStyleColumn(FirstProgram::CHALLENGE->value));
        $this->assertSame('Slot-Future 8+', OverviewPlanStyle::slotStyleColumn(FirstProgram::FUTURE_8->value));

        $slotExplore = OverviewPlanStyle::cellColorsFromCatalog('Slot-Explore', $explore, $challenge, $future8);
        $this->assertSame(OverviewPlanStyle::SLOT_TINT, $slotExplore['bg']);
        $this->assertSame($explore, $slotExplore['border']);

        $slotChallenge = OverviewPlanStyle::cellColorsFromCatalog('Slot-Challenge', $explore, $challenge, $future8);
        $this->assertSame(OverviewPlanStyle::SLOT_TINT, $slotChallenge['bg']);
        $this->assertSame($challenge, $slotChallenge['border']);
    }
}
