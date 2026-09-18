<?php

namespace Tests\Unit;

use App\Models\Event;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class EventWifiPasswordTest extends TestCase
{
    public function test_empty_password_stays_empty(): void
    {
        $this->assertSame('', Event::decryptWifiPassword(null));
        $this->assertSame('', Event::decryptWifiPassword(''));
    }

    public function test_encrypted_password_is_decrypted(): void
    {
        $cipher = Crypt::encryptString('secret-ssid-pw');

        $this->assertSame('secret-ssid-pw', Event::decryptWifiPassword($cipher));
        $this->assertSame('secret-ssid-pw', (new Event(['wifi_password' => $cipher]))->decryptedWifiPassword());
    }

    public function test_undecryptable_password_is_returned_as_stored(): void
    {
        $stored = 'not-a-valid-payload';

        $this->assertSame($stored, Event::decryptWifiPassword($stored));
    }
}
