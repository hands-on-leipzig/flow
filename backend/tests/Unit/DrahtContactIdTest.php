<?php

namespace Tests\Unit;

use App\Support\DrahtContactId;
use Tests\TestCase;

class DrahtContactIdTest extends TestCase
{
    public function test_prefers_contact_claim_over_generic_dolibarr_id(): void
    {
        $this->assertSame(42, DrahtContactId::fromClaims([
            'dolibarr_contact_id' => 42,
            'dolibarr_id' => 99,
        ]));
    }

    public function test_falls_back_to_dolibarr_id(): void
    {
        $this->assertSame(99, DrahtContactId::fromClaims([
            'dolibarr_id' => '99',
        ]));
    }

    public function test_ignores_empty_and_zero(): void
    {
        $this->assertNull(DrahtContactId::fromClaims([]));
        $this->assertNull(DrahtContactId::fromClaims(['dolibarr_id' => 0]));
        $this->assertNull(DrahtContactId::fromClaims(['dolibarr_contact_id' => '']));
    }
}
