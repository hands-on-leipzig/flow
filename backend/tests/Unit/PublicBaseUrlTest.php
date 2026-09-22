<?php

namespace Tests\Unit;

use App\Support\PublicBaseUrl;
use Tests\TestCase;

class PublicBaseUrlTest extends TestCase
{
    public function test_production_is_the_vanity_host(): void
    {
        $this->assertSame(
            'https://handson.tools',
            PublicBaseUrl::forEnvironment('production', 'https://flow.hands-on-technology.org'),
        );
        $this->assertSame(
            'https://handson.tools',
            PublicBaseUrl::forEnvironment('prod', 'https://flow.hands-on-technology.org'),
        );
    }

    public function test_test_and_dev_use_vanity_subdomains(): void
    {
        $this->assertSame(
            'https://test.handson.tools',
            PublicBaseUrl::forEnvironment('testing', 'https://test.flow.hands-on-technology.org'),
        );
        $this->assertSame(
            'https://test.handson.tools',
            PublicBaseUrl::forEnvironment('local', 'https://test.handson.tools'),
        );
        $this->assertSame(
            'https://dev.handson.tools',
            PublicBaseUrl::forEnvironment('local', 'https://dev.flow.hands-on-technology.org'),
        );
        $this->assertSame(
            'https://dev.handson.tools',
            PublicBaseUrl::forEnvironment('local', 'https://dev.handson.tools'),
        );
    }

    public function test_laptop_keeps_the_vite_origin(): void
    {
        $this->assertSame(
            'http://localhost:5173',
            PublicBaseUrl::forEnvironment('local', 'http://localhost:5173', 'http://localhost'),
        );
        $this->assertSame(
            'http://localhost:5173',
            PublicBaseUrl::forEnvironment('testing', 'http://localhost:5173', 'http://localhost'),
        );
    }
}
