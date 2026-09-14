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

        $this->assertSame('Feld', TableFieldLabels::noun($f8));
        $this->assertSame('Felder', TableFieldLabels::plural($f8));
        $this->assertSame('F', TableFieldLabels::abbrev($f8));
        $this->assertSame('Feld 1', TableFieldLabels::defaultLabel($f8, 1));
    }

    public function test_unknown_program_uses_tisch(): void
    {
        $this->assertSame('Tisch', TableFieldLabels::noun(FirstProgram::EXPLORE->value));
        $this->assertSame('Tische', TableFieldLabels::plural(0));
        $this->assertSame('Tische/Felder', TableFieldLabels::pluralSlash());
    }

    public function test_effective_prefers_custom_name(): void
    {
        $f8 = FirstProgram::FUTURE_8->value;

        $this->assertSame('Rot', TableFieldLabels::effective($f8, 1, '  Rot  '));
        $this->assertSame('Feld 1', TableFieldLabels::effective($f8, 1, '  '));
        $this->assertSame('Feld 3', TableFieldLabels::effective($f8, 3, null));
    }

    public function test_strip_leading_noun_leaves_custom_names(): void
    {
        $c = FirstProgram::CHALLENGE->value;
        $f8 = FirstProgram::FUTURE_8->value;

        $this->assertSame('1', TableFieldLabels::stripLeadingNoun($c, 'Tisch 1'));
        $this->assertSame('1', TableFieldLabels::stripLeadingNoun($f8, 'Feld 1'));
        $this->assertSame('Rot', TableFieldLabels::stripLeadingNoun($f8, 'Rot'));
        $this->assertSame('Jury/Feld', TableFieldLabels::juryPlaceHeader($f8));
    }

    public function test_sql_default_noun_uses_feld_for_future(): void
    {
        $sql = TableFieldLabels::sqlDefaultNounExpression('atd.first_program');

        $this->assertStringContainsString('THEN "Feld"', $sql);
        $this->assertStringContainsString('ELSE "Tisch"', $sql);
        $this->assertStringNotContainsString('Spielfeld', $sql);
    }
}
