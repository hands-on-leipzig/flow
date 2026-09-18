<?php

namespace Tests\Feature;

use App\Http\Middleware\KeycloakJwtMiddleware;
use App\Mail\AdminTestMail;
use App\Mail\Catalog\MailNotificationCatalog;
use App\Mail\PublicOtpMail;
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

    public function test_lists_catalog_notifications(): void
    {
        $this->actingAsAdmin();

        $keys = $this->getJson('/api/admin/mail/notifications')
            ->assertOk()
            ->json('notifications.*.key');

        $this->assertSame(
            [
                'admin-test',
                'public-otp',
                'volunteer-inquiry-planner',
                'volunteer-inquiry-accepted',
                'volunteer-inquiry-declined',
            ],
            $keys,
        );
        $this->assertSame('live', $this->getJson('/api/admin/mail/notifications')->json('notifications.0.status'));
        $this->assertSame('draft', $this->getJson('/api/admin/mail/notifications')->json('notifications.1.status'));
    }

    public function test_preview_renders_sample_otp_mail(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/mail/notifications/public-otp/preview')
            ->assertOk()
            ->assertJsonPath('key', 'public-otp')
            ->assertJsonPath('subject', 'Code für '.MailNotificationCatalog::SAMPLE_EVENT)
            ->assertSee(MailNotificationCatalog::SAMPLE_OTP, false)
            ->assertSee(MailNotificationCatalog::SAMPLE_EVENT, false);
    }

    public function test_unknown_preview_is_not_found(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/mail/notifications/does-not-exist/preview')
            ->assertNotFound();
    }

    public function test_send_test_mail_to_given_address(): void
    {
        $this->actingAsAdmin();
        Mail::fake();

        $this->postJson('/api/admin/mail/test', ['email' => 'coach@example.org'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('key', 'admin-test');

        Mail::assertSent(AdminTestMail::class, function (AdminTestMail $mail) {
            return $mail->hasTo('coach@example.org');
        });
    }

    public function test_send_selected_catalog_sample(): void
    {
        $this->actingAsAdmin();
        Mail::fake();

        $this->postJson('/api/admin/mail/test', [
            'email' => 'coach@example.org',
            'key' => 'public-otp',
        ])->assertOk();

        Mail::assertSent(PublicOtpMail::class, function (PublicOtpMail $mail) {
            return $mail->hasTo('coach@example.org')
                && $mail->code === MailNotificationCatalog::SAMPLE_OTP;
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
