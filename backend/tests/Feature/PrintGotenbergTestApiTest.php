<?php

namespace Tests\Feature;

use App\Http\Middleware\KeycloakJwtMiddleware;
use App\Print\GotenbergChromium;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrintGotenbergTestApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(KeycloakJwtMiddleware::class);
    }

    public function test_download_503_when_gotenberg_is_not_configured(): void
    {
        config(['services.gotenberg.url' => null]);

        $this->postJson('/api/admin/helpers/gotenberg-test')
            ->assertStatus(503)
            ->assertExactJson(['error' => 'PDF-Dienst nicht erreichbar.']);
    }

    public function test_download_streams_hello_world_pdf(): void
    {
        config([
            'services.gotenberg.url' => 'http://gotenberg.test',
            'services.gotenberg.username' => 'flow',
            'services.gotenberg.password' => 's3cret',
        ]);
        Http::fake([
            'http://gotenberg.test/forms/chromium/convert/html' => Http::response(
                '%PDF-1.4 hello',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);

        $response = $this->post('/api/admin/helpers/gotenberg-test');
        $response->assertOk();
        $this->assertSame('%PDF-1.4 hello', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertNotEmpty($response->headers->get('X-Filename'));

        Http::assertSent(function (Request $request) {
            return $request->url() === 'http://gotenberg.test/forms/chromium/convert/html'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('flow:s3cret'))
                && str_contains($request->body(), 'Hallo Welt')
                && str_contains($request->body(), 'index.html');
        });
    }

    public function test_download_502_when_gotenberg_rejects_auth(): void
    {
        config([
            'services.gotenberg.url' => 'http://gotenberg.test',
            'services.gotenberg.username' => 'flow',
            'services.gotenberg.password' => 'wrong',
        ]);
        Http::fake([
            'http://gotenberg.test/forms/chromium/convert/html' => Http::response('Unauthorized', 401),
        ]);

        $this->postJson('/api/admin/helpers/gotenberg-test')
            ->assertStatus(502)
            ->assertExactJson(['error' => 'PDF-Dienst: Anmeldung fehlgeschlagen.']);
    }

    public function test_hello_world_html_contains_marker(): void
    {
        $this->assertStringContainsString('Hallo Welt', GotenbergChromium::HELLO_WORLD_HTML);
    }
}
