<?php

namespace Tests\Unit;

use App\Support\VolunteerRosterCustomFields;
use App\Support\VolunteerRosterDetailFields;
use Tests\TestCase;

class VolunteerRosterCustomFieldsTest extends TestCase
{
    public function test_validate_definition_requires_select_options(): void
    {
        $result = VolunteerRosterCustomFields::validateDefinition([
            'label' => 'Parkplatz',
            'type' => 'select',
            'options' => [],
        ]);

        $this->assertFalse($result['ok']);
    }

    public function test_validate_boolean_value_accepts_null(): void
    {
        $field = new \App\Models\EventVolunteerField([
            'type' => 'boolean',
            'field_key' => 'vorabend',
            'label' => 'Vorabend',
        ]);

        $result = VolunteerRosterCustomFields::validateValue($field, null);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['stored']);
        $this->assertNull($result['api']);
    }

    public function test_validate_number_value_normalizes_trailing_zeros(): void
    {
        $field = new \App\Models\EventVolunteerField([
            'type' => 'number',
            'field_key' => 'alter',
            'label' => 'Alter',
        ]);

        $result = VolunteerRosterCustomFields::validateValue($field, '42.500000');

        $this->assertTrue($result['ok']);
        $this->assertSame('42.5', $result['stored']);
    }

    public function test_export_boolean_value_uses_german_labels(): void
    {
        $field = new \App\Models\EventVolunteerField([
            'type' => 'boolean',
            'field_key' => 'vorabend',
            'label' => 'Vorabend',
        ]);

        $this->assertSame('ja', VolunteerRosterCustomFields::exportValue($field, '1'));
        $this->assertSame('nein', VolunteerRosterCustomFields::exportValue($field, '0'));
    }

    public function test_is_unset_treats_null_boolean_as_unset(): void
    {
        $field = new \App\Models\EventVolunteerField([
            'type' => 'boolean',
            'field_key' => 'vorabend',
            'label' => 'Vorabend',
        ]);

        $this->assertTrue(VolunteerRosterCustomFields::isUnset($field, null));
        $this->assertFalse(VolunteerRosterCustomFields::isUnset($field, true));
        $this->assertFalse(VolunteerRosterCustomFields::isUnset($field, false));
    }

    public function test_detail_validate_no_longer_accepts_eve_meeting(): void
    {
        $result = VolunteerRosterDetailFields::validate([
            't_shirt_cut' => null,
            't_shirt_size' => null,
            'meal' => null,
        ], ['standard', 'vegetarisch', 'vegan', 'keine']);

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('eve_meeting', $result['data']);
        $this->assertArrayNotHasKey('notes', $result['data']);
    }
}
