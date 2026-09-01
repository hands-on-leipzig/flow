<?php

namespace Tests\Unit;

use App\Support\VolunteerMealOptions;
use App\Support\VolunteerRosterDetailFields;
use Tests\TestCase;

class VolunteerMealOptionsTest extends TestCase
{
    public function test_defaults_contain_four_standard_options(): void
    {
        $this->assertCount(4, VolunteerMealOptions::defaults());
    }

    public function test_validate_replace_list_requires_at_least_one_option(): void
    {
        $result = VolunteerMealOptions::validateReplaceList([]);

        $this->assertFalse($result['ok']);
    }

    public function test_validate_replace_list_normalizes_options(): void
    {
        $result = VolunteerMealOptions::validateReplaceList([
            ['label' => 'Glutenfrei'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('glutenfrei', $result['data'][0]['value']);
        $this->assertSame('Glutenfrei', $result['data'][0]['label']);
    }

    public function test_validate_meal_against_allowed_values(): void
    {
        $result = VolunteerRosterDetailFields::validate(
            ['meal' => 'glutenfrei'],
            ['standard', 'vegan'],
        );

        $this->assertFalse($result['ok']);
    }

    public function test_validate_photo_consent_accepts_tri_state(): void
    {
        $this->assertTrue(VolunteerRosterDetailFields::validate(['photo_consent' => true])['ok']);
        $this->assertTrue(VolunteerRosterDetailFields::validate(['photo_consent' => false])['ok']);
        $this->assertTrue(VolunteerRosterDetailFields::validate(['photo_consent' => null])['ok']);
    }

    public function test_export_photo_consent_label(): void
    {
        $this->assertSame('ja', VolunteerRosterDetailFields::exportPhotoConsentLabel(true));
        $this->assertSame('nein', VolunteerRosterDetailFields::exportPhotoConsentLabel(false));
        $this->assertSame('', VolunteerRosterDetailFields::exportPhotoConsentLabel(null));
    }
}
