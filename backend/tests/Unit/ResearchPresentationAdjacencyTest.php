<?php

namespace Tests\Unit;

use App\Core\PlanGeneratorCore;
use PHPUnit\Framework\TestCase;

class ResearchPresentationAdjacencyTest extends TestCase
{
    public function test_c_then_f8_and_f8_then_c_are_adjacent(): void
    {
        $this->assertTrue(PlanGeneratorCore::researchPresentationsAreAdjacent('c_presentations', 'f8_presentations'));
        $this->assertTrue(PlanGeneratorCore::researchPresentationsAreAdjacent('f8_presentations', 'c_presentations'));
    }

    public function test_same_program_or_other_blocks_are_not_adjacent(): void
    {
        $this->assertFalse(PlanGeneratorCore::researchPresentationsAreAdjacent('c_presentations', 'c_presentations'));
        $this->assertFalse(PlanGeneratorCore::researchPresentationsAreAdjacent('c_presentations', 'r_final_2'));
        $this->assertFalse(PlanGeneratorCore::researchPresentationsAreAdjacent('r_final_4', 'f8_presentations'));
        $this->assertFalse(PlanGeneratorCore::researchPresentationsAreAdjacent(null, 'f8_presentations'));
        $this->assertFalse(PlanGeneratorCore::researchPresentationsAreAdjacent('c_presentations', null));
    }
}
