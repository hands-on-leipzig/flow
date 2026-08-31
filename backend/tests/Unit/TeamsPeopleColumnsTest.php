<?php

namespace Tests\Unit;

use App\Support\TeamsPeopleColumns;
use PHPUnit\Framework\TestCase;

class TeamsPeopleColumnsTest extends TestCase
{
    public function test_export_values_follows_definition_order(): void
    {
        $values = TeamsPeopleColumns::exportValues([
            'program' => 'Challenge',
            'team_number' => '42',
            'team_name' => 'Robots',
            'role' => 'Coach',
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'gender' => '',
            'birthday' => '',
            'email' => 'max@example.com',
            'phone' => '+49123',
            'organization' => 'School',
        ]);

        $this->assertSame([
            'Challenge',
            '42',
            'Robots',
            'Coach',
            'Max',
            'Muster',
            '',
            '',
            'max@example.com',
            '+49123',
            'School',
        ], $values);
    }

    public function test_format_birthday_from_unix_timestamp(): void
    {
        $formatted = TeamsPeopleColumns::formatBirthday(946684800);

        $this->assertSame('01.01.2000', $formatted);
    }
}
