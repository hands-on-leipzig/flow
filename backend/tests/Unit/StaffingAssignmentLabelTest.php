<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Support\StaffingAssignmentLabel;
use PHPUnit\Framework\TestCase;

class StaffingAssignmentLabelTest extends TestCase
{
    public function test_jury_keeps_group_label_and_index(): void
    {
        $this->assertSame('Jury-Gruppe 2', StaffingAssignmentLabel::containerTitle('Jury-Gruppe', 2, 'Jury'));
        $this->assertSame('Jury (Jury-Gruppe 2)', StaffingAssignmentLabel::assignmentCaption('Jury', 'Jury-Gruppe', 2));
    }

    public function test_match_place_uses_effective_name(): void
    {
        $this->assertSame('Anton', StaffingAssignmentLabel::containerTitle('Tisch', 1, 'Schiedsrichter:in', 'Anton'));
        $this->assertSame(
            'Schiedsrichter:in (Anton)',
            StaffingAssignmentLabel::assignmentCaption('Schiedsrichter:in', 'Tisch', 1, 'Anton'),
        );
        $this->assertSame('Tisch 1', StaffingAssignmentLabel::containerTitle('Tisch', 1, 'Schiedsrichter:in', 'Tisch 1'));
        $this->assertSame(
            'Matte rot',
            StaffingAssignmentLabel::containerTitle('Spiel-Matte', 1, 'Schiedsrichter:in', 'Matte rot'),
        );
    }

    public function test_robot_check_prefixes_place(): void
    {
        $this->assertSame(
            'Robot-Check für Anton',
            StaffingAssignmentLabel::containerTitle('Robot-Check', 1, 'Robot-Check', 'Anton'),
        );
        $this->assertSame(
            'Robot-Check (Robot-Check für Anton)',
            StaffingAssignmentLabel::assignmentCaption('Robot-Check', 'Robot-Check', 1, 'Anton'),
        );
    }

    public function test_allianz_never_uses_place(): void
    {
        $this->assertSame(
            'Betreuer:in Allianz-Gespräche',
            StaffingAssignmentLabel::containerTitle(null, 1, 'Betreuer:in Allianz-Gespräche', 'Matte rot'),
        );
        $this->assertSame(
            'Betreuer:in Allianz-Gespräche',
            StaffingAssignmentLabel::assignmentCaption('Betreuer:in Allianz-Gespräche', null, 1, 'Matte rot'),
        );
        $this->assertSame(
            'Allianz 1',
            StaffingAssignmentLabel::containerTitle('Allianz', 1, 'Betreuer:in Allianz-Gespräche', 'Matte rot'),
        );
    }

    public function test_place_label_for_group_skips_allianz_and_jury(): void
    {
        $c = FirstProgram::CHALLENGE->value;
        $f8 = FirstProgram::FUTURE_8->value;
        $maps = [
            'counts' => [$c => 2, $f8 => 2],
            'customs' => [
                $c => [1 => 'Anton'],
                $f8 => [2 => 'Blau'],
            ],
        ];

        $this->assertSame('Anton', StaffingAssignmentLabel::placeLabelForGroup('Tisch', 'Schiedsrichter:in', $c, 1, $maps));
        $this->assertSame('Tisch 2', StaffingAssignmentLabel::placeLabelForGroup('Tisch', 'Schiedsrichter:in', $c, 2, $maps));
        $this->assertSame('Anton', StaffingAssignmentLabel::placeLabelForGroup('Robot-Check', 'Robot-Check', $c, 1, $maps));
        $this->assertSame('Blau', StaffingAssignmentLabel::placeLabelForGroup('Spiel-Matte', 'Schiedsrichter:in', $f8, 2, $maps));
        $this->assertSame('Matte rot', StaffingAssignmentLabel::placeLabelForGroup('Spiel-Matte', 'Schiedsrichter:in', $f8, 1, $maps));
        $this->assertNull(StaffingAssignmentLabel::placeLabelForGroup('Jury-Gruppe', 'Jury', $c, 1, $maps));
        $this->assertNull(StaffingAssignmentLabel::placeLabelForGroup(null, 'Betreuer:in Allianz-Gespräche', $f8, 1, $maps));
        $this->assertNull(StaffingAssignmentLabel::placeLabelForGroup('Allianz', 'Betreuer:in Allianz-Gespräche', $f8, 1, $maps));
    }
}
