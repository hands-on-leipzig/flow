<?php

namespace Tests\Unit;

use App\Support\VolunteerRosterDetailFields;
use Tests\TestCase;

class VolunteerRosterDetailFieldsTest extends TestCase
{
    public function test_validate_accepts_complete_shirt_pair_and_meal(): void
    {
        $result = VolunteerRosterDetailFields::validate([
            't_shirt_cut' => 'frauen',
            't_shirt_size' => 'M',
            'meal' => 'vegetarisch',
        ], ['standard', 'vegetarisch', 'vegan', 'keine']);

        $this->assertTrue($result['ok']);
        $this->assertSame('frauen', $result['data']['t_shirt_cut']);
        $this->assertSame('M', $result['data']['t_shirt_size']);
        $this->assertSame('vegetarisch', $result['data']['meal']);
        $this->assertArrayNotHasKey('notes', $result['data']);
    }

    public function test_validate_rejects_half_filled_shirt(): void
    {
        $result = VolunteerRosterDetailFields::validate([
            't_shirt_cut' => 'maenner',
            't_shirt_size' => null,
            'meal' => null,
        ], ['standard', 'vegetarisch', 'vegan', 'keine']);

        $this->assertFalse($result['ok']);
    }

    public function test_validate_allows_all_empty(): void
    {
        $result = VolunteerRosterDetailFields::validate([
            't_shirt_cut' => null,
            't_shirt_size' => null,
            'meal' => null,
        ], ['standard', 'vegetarisch', 'vegan', 'keine']);

        $this->assertTrue($result['ok']);
    }

    public function test_validate_rejects_meal_not_in_allowed_list(): void
    {
        $result = VolunteerRosterDetailFields::validate([
            't_shirt_cut' => null,
            't_shirt_size' => null,
            'meal' => 'vegetarisch',
        ], ['standard', 'keine']);

        $this->assertFalse($result['ok']);
    }
}
