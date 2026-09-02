<?php

namespace Tests\Unit;

use App\Support\PhotoConsentStatus;
use PHPUnit\Framework\TestCase;

class PhotoConsentStatusTest extends TestCase
{
    public function test_volunteer_tri_state(): void
    {
        $this->assertSame(PhotoConsentStatus::PENDING, PhotoConsentStatus::forVolunteer(null)['status']);
        $this->assertSame(PhotoConsentStatus::GRANTED, PhotoConsentStatus::forVolunteer(true)['status']);
        $this->assertSame(PhotoConsentStatus::DENIED, PhotoConsentStatus::forVolunteer(false)['status']);

        $this->assertSame('Fotoerlaubnis fehlt', PhotoConsentStatus::forVolunteer(null)['check_in_label']);
        $this->assertSame('Fotoerlaubnis liegt vor', PhotoConsentStatus::forVolunteer(true)['check_in_label']);
        $this->assertSame('Fotoerlaubnis verweigert', PhotoConsentStatus::forVolunteer(false)['check_in_label']);
    }

    public function test_team_denied_wins_when_incomplete(): void
    {
        $result = PhotoConsentStatus::forTeam(['unknown' => 0, 'yes' => 3, 'no' => 1], 10);
        $this->assertSame(PhotoConsentStatus::DENIED, $result['status']);
        $this->assertSame(4, $result['answered']);
        $this->assertSame('Mindestens eine Fotoerlaubnis verweigert', $result['check_in_label']);
    }

    public function test_team_all_yes_granted(): void
    {
        $result = PhotoConsentStatus::forTeam(['unknown' => 0, 'yes' => 5, 'no' => 0], 5);
        $this->assertSame(PhotoConsentStatus::GRANTED, $result['status']);
        $this->assertSame('Alle Fotoerlaubnisse liegen vor', $result['check_in_label']);
    }

    public function test_team_incomplete_pending_uses_answered_yes_plus_no(): void
    {
        $result = PhotoConsentStatus::forTeam(['unknown' => 2, 'yes' => 1, 'no' => 0], 5);
        $this->assertSame(PhotoConsentStatus::PENDING, $result['status']);
        $this->assertSame(1, $result['answered']);
        $this->assertSame(
            'Bisher liegen 1 von 5 Fotoerlaubnissen vor. Bitte nicht vergessen, die restlichen zu schicken.',
            $result['self_service_message'],
        );
        $this->assertSame('Es fehlen Fotoerlaubnisse', $result['check_in_label']);
    }

    public function test_team_missing_people_count_is_pending(): void
    {
        $result = PhotoConsentStatus::forTeam(['unknown' => 0, 'yes' => 3, 'no' => 0], null);
        $this->assertSame(PhotoConsentStatus::PENDING, $result['status']);
    }
}
