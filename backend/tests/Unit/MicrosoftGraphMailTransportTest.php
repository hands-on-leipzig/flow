<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class MicrosoftGraphMailTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'microsoft-graph',
            'mail.from.address' => 'flow@hands-on-technology.org',
            'mail.from.name' => 'FLOW',
            'mail.mailers.microsoft-graph.tenant_id' => 'tenant-id',
            'mail.mailers.microsoft-graph.client_id' => 'client-id',
            'mail.mailers.microsoft-graph.client_secret' => 'client-secret',
            'mail.mailers.microsoft-graph.save_to_sent_items' => false,
        ]);

        Mail::purge('microsoft-graph');
        Cache::flush();
    }

    public function test_send_posts_graph_send_mail_after_client_credentials_token(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'graph-token',
                'expires_in' => 3600,
            ], 200),
            'https://graph.microsoft.com/v1.0/users/*' => Http::response('', 202),
        ]);

        Mail::raw('OTP 123456', function ($message) {
            $message->to('coach@example.org')->subject('Dein Code');
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://login.microsoftonline.com/tenant-id/oauth2/v2.0/token'
                && $request['grant_type'] === 'client_credentials'
                && $request['scope'] === 'https://graph.microsoft.com/.default'
                && $request['client_id'] === 'client-id';
        });

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://graph.microsoft.com/v1.0/users/flow%40hands-on-technology.org/sendMail') {
                return false;
            }

            $payload = $request->data();

            return ($payload['saveToSentItems'] ?? null) === false
                && ($payload['message']['subject'] ?? null) === 'Dein Code'
                && ($payload['message']['body']['contentType'] ?? null) === 'Text'
                && str_contains((string) ($payload['message']['body']['content'] ?? ''), 'OTP 123456')
                && ($payload['message']['toRecipients'][0]['emailAddress']['address'] ?? null) === 'coach@example.org';
        });
    }

    public function test_token_is_cached_across_sends(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'graph-token',
                'expires_in' => 3600,
            ], 200),
            'https://graph.microsoft.com/v1.0/users/*' => Http::response('', 202),
        ]);

        Mail::raw('one', function ($message) {
            $message->to('a@example.org')->subject('One');
        });
        Mail::raw('two', function ($message) {
            $message->to('b@example.org')->subject('Two');
        });

        $tokenRequests = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/oauth2/v2.0/token'));

        $this->assertCount(1, $tokenRequests);
        Http::assertSentCount(3);
    }

    public function test_graph_error_becomes_transport_exception(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'graph-token',
            ], 200),
            'https://graph.microsoft.com/v1.0/users/*' => Http::response([
                'error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access denied'],
            ], 403),
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('HTTP 403');

        Mail::raw('fail', function ($message) {
            $message->to('coach@example.org')->subject('Fail');
        });
    }
}
