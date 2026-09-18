<?php

namespace Tests\Unit;

use App\Support\MailFrom;
use Tests\TestCase;

class MailFromTest extends TestCase
{
    public function test_plain_address_stays_plain(): void
    {
        $this->assertSame(
            ['address' => 'noreply@hands-on-technology.org', 'name' => 'FLOW'],
            MailFrom::parse('noreply@hands-on-technology.org', 'FLOW'),
        );
    }

    public function test_rfc5322_mailbox_is_split(): void
    {
        $this->assertSame(
            [
                'address' => 'noreply@hands-on-technology.org',
                'name' => 'FLOW - HANDS on TECHNOLOGY',
            ],
            MailFrom::parse('FLOW - HANDS on TECHNOLOGY <noreply@hands-on-technology.org>'),
        );
    }
}
