<?php

namespace Tests\Feature;

use App\Http\Middleware\KeycloakJwtMiddleware;
use App\Mail\FlowTestMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class AdminMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(KeycloakJwtMiddleware::class);
        config([
            'mail.default' => 'array',
            'mail.from.address' => 'flow@hands-on-technology.org',
            'mail.from.name' => 'FLOW',
        ]);
    }

    public function test_status_returns_mailer_and_from_for_admin(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/mail')
            ->assertOk()
            ->assertJsonPath('mailer', 'array')
            ->assertJsonPath('from', 'flow@hands-on-technology.org')
            ->assertJsonPath('configured', true);
    }

    public function test_status_forbidden_without_admin(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/admin/mail')->assertForbidden();
    }

    public function test_send_test_mail_to_given_address(): void
    {
        $this->actingAsAdmin();
        Mail::fake();

        $this->postJson('/api/admin/mail/test', ['email' => 'coach@example.org'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(FlowTestMail::class, function (FlowTestMail $mail) {
            return $mail->hasTo('coach@example.org');
        });
    }

    public function test_send_rejects_invalid_email(): void
    {
        $this->actingAsAdmin();
        Mail::fake();

        $this->postJson('/api/admin/mail/test', ['email' => 'not-an-email'])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_send_forbidden_without_admin(): void
    {
        $this->actingAsUser();
        Mail::fake();

        $this->postJson('/api/admin/mail/test', ['email' => 'coach@example.org'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    private function actingAsAdmin(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isFlowAdmin')->andReturn(true);
        $this->actingAs($user);
    }

    private function actingAsUser(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isFlowAdmin')->andReturn(false);
        $this->actingAs($user);
    }
}
