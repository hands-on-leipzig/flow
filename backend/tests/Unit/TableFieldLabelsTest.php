<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Support\TableFieldLabels;
use PHPUnit\Framework\TestCase;

class TableFieldLabelsTest extends TestCase
{
    public function test_noun_plural_abbrev_by_program(): void
    {
        $c = FirstProgram::CHALLENGE->value;
        $f8 = FirstProgram::FUTURE_8->value;

        $this->assertSame('Tisch', TableFieldLabels::noun($c));
        $this->assertSame('Tische', TableFieldLabels::plural($c));
        $this->assertSame('T', TableFieldLabels::abbrev($c));
        $this->assertSame('Tisch 2', TableFieldLabels::defaultLabel($c, 2));
        $this->assertSame('Tisch 2', TableFieldLabels::defaultLabel($c, 2, 4));

        $this->assertSame('Matte', TableFieldLabels::noun($f8));
        $this->assertSame('Matten', TableFieldLabels::plural($f8));
        $this->assertSame('M', TableFieldLabels::abbrev($f8));
        $this->assertSame('Matte rot', TableFieldLabels::defaultLabel($f8, 1));
    }

    public function test_future_defaults_by_count(): void
    {
        $f8 = FirstProgram::FUTURE_8->value;

        $this->assertSame('Matte rot', TableFieldLabels::defaultLabel($f8, 1, 2));
        $this->assertSame('Matte blau', TableFieldLabels::defaultLabel($f8, 2, 2));

        $this->assertSame('Matte rot 1', TableFieldLabels::defaultLabel($f8, 1, 4));
        $this->assertSame('Matte blau 1', TableFieldLabels::defaultLabel($f8, 2, 4));
        $this->assertSame('Matte rot 2', TableFieldLabels::defaultLabel($f8, 3, 4));
        $this->assertSame('Matte blau 2', TableFieldLabels::defaultLabel($f8, 4, 4));
    }

    public function test_unknown_program_uses_tisch(): void
    {
        $this->assertSame('Tisch', TableFieldLabels::noun(FirstProgram::EXPLORE->value));
        $this->assertSame('Tische', TableFieldLabels::plural(0));
        $this->assertSame('Tische/Matten', TableFieldLabels::pluralSlash());
        $this->assertSame('Jury/Matte', TableFieldLabels::juryPlaceHeader(FirstProgram::FUTURE_8->value));
        $this->assertSame('Jury/Tisch', TableFieldLabels::juryPlaceHeader(FirstProgram::CHALLENGE->value));
    }

    public function test_effective_prefers_custom_name(): void
    {
        $f8 = FirstProgram::FUTURE_8->value;

        $this->assertSame('Rot', TableFieldLabels::effective($f8, 1, '  Rot  ', 2));
        $this->assertSame('Matte rot', TableFieldLabels::effective($f8, 1, '  ', 2));
        $this->assertSame('Matte rot 2', TableFieldLabels::effective($f8, 3, null, 4));
        $this->assertSame('Matte rot 2', TableFieldLabels::effective($f8, 3, null));
    }

    public function test_strip_leading_noun_leaves_custom_names(): void
    {
        $c = FirstProgram::CHALLENGE->value;
        $f8 = FirstProgram::FUTURE_8->value;

        $this->assertSame('1', TableFieldLabels::stripLeadingNoun($c, 'Tisch 1'));
        $this->assertSame('rot 1', TableFieldLabels::stripLeadingNoun($f8, 'Matte rot 1'));
        $this->assertSame('Rot', TableFieldLabels::stripLeadingNoun($f8, 'Rot'));
    }
}
