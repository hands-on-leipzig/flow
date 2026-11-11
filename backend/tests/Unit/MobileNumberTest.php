<?php

namespace Tests\Unit;

use App\Support\GermanMobileNumber;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MobileNumberTest extends TestCase
{
    #[Test]
    public function it_keeps_german_numbers_on_the_existing_format(): void
    {
        $expected = ['ok' => true, 'normalized' => '+49 170 1234567'];

        $this->assertSame($expected, GermanMobileNumber::validateAndNormalize('0170 1234567'));
        $this->assertSame($expected, GermanMobileNumber::validateAndNormalize('+49 170 1234567'));
        $this->assertSame($expected, GermanMobileNumber::validateAndNormalize('0049 170 1234567'));
        $this->assertSame($expected, GermanMobileNumber::validateAndNormalize('491701234567'));
    }

    #[Test]
    public function it_leaves_a_german_trunk_zero_in_place(): void
    {
        $this->assertSame(
            ['ok' => true, 'normalized' => '+49 017 01234567'],
            GermanMobileNumber::validateAndNormalize('+49 0170 1234567'),
        );
    }

    #[Test]
    public function it_does_not_relax_german_length_rules(): void
    {
        $this->assertSame(
            ['ok' => false, 'error' => 'Ungültige Mobilnummer'],
            GermanMobileNumber::validateAndNormalize('+49 1701234567890'),
        );
    }

    #[Test]
    public function it_treats_placeholders_as_empty(): void
    {
        $this->assertSame(['ok' => true, 'normalized' => null], GermanMobileNumber::validateAndNormalize(null));
        $this->assertSame(['ok' => true, 'normalized' => null], GermanMobileNumber::validateAndNormalize(''));
        $this->assertSame(['ok' => true, 'normalized' => null], GermanMobileNumber::validateAndNormalize('n/a'));
        $this->assertSame(['ok' => true, 'normalized' => null], GermanMobileNumber::validateAndNormalize('-'));
    }

    #[Test]
    public function it_accepts_other_countries_when_a_calling_code_is_present(): void
    {
        $swiss = ['ok' => true, 'normalized' => '+41 791234567'];

        $this->assertSame($swiss, GermanMobileNumber::validateAndNormalize('+41 79 123 45 67'));
        $this->assertSame($swiss, GermanMobileNumber::validateAndNormalize('0041 79 123 45 67'));
        $this->assertSame($swiss, GermanMobileNumber::validateAndNormalize('+41 (0)79 123 45 67'));
        $this->assertSame(
            ['ok' => true, 'normalized' => '+1 4155552671'],
            GermanMobileNumber::validateAndNormalize('+1 (415) 555-2671'),
        );
    }

    #[Test]
    public function it_rejects_unknown_short_and_national_foreign_numbers(): void
    {
        $invalid = ['ok' => false, 'error' => 'Ungültige Mobilnummer'];

        $this->assertSame($invalid, GermanMobileNumber::validateAndNormalize('+999 123456789'));
        $this->assertSame($invalid, GermanMobileNumber::validateAndNormalize('+41 79'));
        $this->assertSame($invalid, GermanMobileNumber::validateAndNormalize('079 123 45 67'));
    }
}
