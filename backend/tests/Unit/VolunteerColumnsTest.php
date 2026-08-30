<?php

namespace Tests\Unit;

use App\Models\EventVolunteerField;
use App\Support\VolunteerPersonColumns;
use App\Support\VolunteerRosterColumns;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VolunteerColumnsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('event_volunteer_field')) {
            return;
        }

        Schema::create('event_volunteer_field', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->string('field_key', 64);
            $table->string('label', 120);
            $table->string('type', 20);
            $table->json('options')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();

            $table->unique(['event', 'field_key'], 'event_volunteer_field_event_key_unique');
        });
    }

    public function test_person_export_labels_match_table_headers(): void
    {
        $this->assertSame(
            [
                'Vorname',
                'Nachname',
                'E-Mail',
                'Mobil',
                'Organisation',
                'Letzte Änderung',
            ],
            VolunteerPersonColumns::exportLabels(),
        );
    }

    public function test_person_table_payload_includes_updated_at(): void
    {
        $keys = array_column(VolunteerPersonColumns::tablePayload(), 'key');

        $this->assertSame(
            ['first_name', 'last_name', 'email', 'mobile', 'organization', 'updated_at'],
            $keys,
        );
    }

    public function test_roster_table_payload_without_custom_fields(): void
    {
        $keys = array_column(VolunteerRosterColumns::tablePayloadForEvent(999999), 'key');

        $this->assertSame(
            ['name', 'role', 't_shirt', 'meal', 'notes'],
            $keys,
        );
    }

    public function test_roster_table_payload_includes_custom_fields_between_meal_and_notes(): void
    {
        EventVolunteerField::create([
            'event' => 1,
            'field_key' => 'vorabend',
            'label' => 'Vorabendtreffen',
            'type' => 'boolean',
            'options' => null,
            'sequence' => 1,
        ]);

        $keys = array_column(VolunteerRosterColumns::tablePayloadForEvent(1), 'key');

        $this->assertSame(
            ['name', 'role', 't_shirt', 'meal', 'custom:vorabend', 'notes'],
            $keys,
        );
    }

    public function test_roster_export_labels_without_custom_fields(): void
    {
        $this->assertSame(
            [
                'Vorname',
                'Nachname',
                'E-Mail',
                'Mobil',
                'Organisation',
                'Letzte Änderung',
                'Zuordnung 1 Programm',
                'Zuordnung 1 Rolle',
                'T-Shirt Schnitt',
                'T-Shirt Größe',
                'Essen',
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
            VolunteerRosterColumns::exportLabelsForEvent(999999),
        );
    }

    public function test_roster_export_labels_place_custom_fields_before_notes(): void
    {
        EventVolunteerField::create([
            'event' => 2,
            'field_key' => 'parkplatz',
            'label' => 'Parkplatz',
            'type' => 'text',
            'options' => null,
            'sequence' => 1,
        ]);

        $labels = VolunteerRosterColumns::exportLabelsForEvent(2);
        $essenIndex = array_search('Essen', $labels, true);
        $notesIndex = array_search('Bemerkungen', $labels, true);
        $customIndex = array_search('Parkplatz', $labels, true);

        $this->assertNotFalse($essenIndex);
        $this->assertNotFalse($notesIndex);
        $this->assertNotFalse($customIndex);
        $this->assertGreaterThan($essenIndex, $customIndex);
        $this->assertLessThan($notesIndex, $customIndex);
    }
}
