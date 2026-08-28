<?php

namespace Tests\Unit;

use App\Support\VolunteerPersonColumns;
use App\Support\VolunteerRosterColumns;
use Tests\TestCase;

class VolunteerColumnsTest extends TestCase
{
    public function test_person_export_labels_match_table_headers(): void
    {
        $this->assertSame(
            [
                'Vorname',
                'Nachname',
                'Spitzname',
                'E-Mail',
                'Mobil',
                'Letzte Änderung',
            ],
            VolunteerPersonColumns::exportLabels(),
        );
    }

    public function test_person_table_payload_includes_updated_at(): void
    {
        $keys = array_column(VolunteerPersonColumns::tablePayload(), 'key');

        $this->assertSame(
            ['first_name', 'last_name', 'nickname', 'email', 'mobile', 'updated_at'],
            $keys,
        );
    }

    public function test_roster_export_labels_use_german_headers(): void
    {
        $this->assertSame(
            [
                'Vorname',
                'Nachname',
                'Spitzname',
                'E-Mail',
                'Mobil',
                'Letzte Änderung',
                'Zuordnung 1 Programm',
                'Zuordnung 1 Rolle',
                'T-Shirt Schnitt',
                'T-Shirt Größe',
                'Essen',
                'Vorabendtreffen',
                'Bemerkungen',
                'Zuordnung 2 Programm',
                'Zuordnung 2 Rolle',
                'Zuordnung 3 Programm',
                'Zuordnung 3 Rolle',
                'Zuordnung 4 Programm',
                'Zuordnung 4 Rolle',
                'Zuordnung 5 Programm',
                'Zuordnung 5 Rolle',
            ],
            VolunteerRosterColumns::exportLabels(),
        );
    }

    public function test_roster_table_payload_matches_ui_columns(): void
    {
        $this->assertSame(
            [
                ['key' => 'name', 'label' => 'Name', 'sortable' => true],
                ['key' => 'role', 'label' => 'Rolle', 'sortable' => true],
                ['key' => 't_shirt', 'label' => 'T-Shirt Größe', 'sortable' => false],
                ['key' => 'meal', 'label' => 'Essen', 'sortable' => false],
                ['key' => 'eve_meeting', 'label' => 'Vorabendtreffen', 'sortable' => false],
                ['key' => 'notes', 'label' => 'Bemerkungen', 'sortable' => false],
            ],
            VolunteerRosterColumns::tablePayload(),
        );
    }
}
