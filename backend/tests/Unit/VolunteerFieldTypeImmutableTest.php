<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\EventVolunteerFieldController;
use App\Models\Event;
use App\Models\EventVolunteerField;
use Illuminate\Http\Request;
use Tests\TestCase;

class VolunteerFieldTypeImmutableTest extends TestCase
{
    public function test_update_rejects_type_change(): void
    {
        $event = new Event();
        $event->id = 1;

        $field = new EventVolunteerField([
            'event' => 1,
            'field_key' => 'notiz',
            'label' => 'Notiz',
            'type' => 'text',
            'options' => null,
            'sequence' => 1,
        ]);
        $field->id = 5;

        $response = app(EventVolunteerFieldController::class)->update(
            Request::create('/', 'PATCH', [
                'label' => 'Notiz',
                'type' => 'boolean',
            ]),
            $event,
            $field,
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Feldtyp', $response->getData(true)['error'] ?? '');
    }
}
