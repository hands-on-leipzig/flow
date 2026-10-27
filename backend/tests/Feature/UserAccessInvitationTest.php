<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAccessInvitationTest extends TestCase
{
    private string $privatePem;

    private string $publicKeyRelativePath = 'storage/framework/testing/oauth-public.pem';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('User access tests require sqlite.');
        }

        $this->createSchema();
        $this->writeKeyPair();
        config(['services.keycloak.public_key_path' => $this->publicKeyRelativePath]);
        Mail::fake();

        Route::middleware('keycloak')->get('/api/scor/ping', fn () => response()->json(['ok' => true]));

        DB::table('regional_partner')->insert(['id' => 1, 'name' => 'RP Leipzig']);
        DB::table('event')->insert([
            ['id' => 10, 'name' => 'Leipzig', 'regional_partner' => 1],
            ['id' => 11, 'name' => 'Leipzig 2', 'regional_partner' => 1],
        ]);

        DB::table('user')->insert([
            ['id' => 1, 'subject' => 'rp', 'name' => 'RP', 'email' => 'rp@example.org'],
            ['id' => 2, 'subject' => 'helper', 'name' => 'Helper', 'email' => 'helper@example.org'],
        ]);
        DB::table('user_regional_partner')->insert([
            'user' => 1, 'regional_partner' => 1, 'source' => 'draht', 'can_flow' => true, 'can_scor' => true,
        ]);
        DB::table('event_user_access')->insert([
            'user' => 2, 'event' => 10, 'can_flow' => true, 'can_scor' => false, 'granted_by' => 1,
        ]);
    }

    public function test_draht_contact_invites_by_email(): void
    {
        $this->postJson('/api/user/access/grants', [
            'event_id' => 10,
            'email' => '  New.Person@Example.org ',
            'can_flow' => false,
            'can_scor' => true,
        ], $this->bearer('rp', 'rp@example.org'))
            ->assertCreated()
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('invitations.0.email', 'new.person@example.org')
            ->assertJsonPath('invitations.0.can_scor', true);
    }

    public function test_invited_planner_cannot_invite_others(): void
    {
        $this->postJson('/api/user/access/grants', [
            'event_id' => 10,
            'email' => 'someone@example.org',
            'can_flow' => true,
            'can_scor' => false,
        ], $this->bearer('helper', 'helper@example.org'))->assertForbidden();

        $this->assertSame(0, DB::table('event_invitation')->count());
    }

    public function test_email_with_access_is_rejected(): void
    {
        $this->postJson('/api/user/access/grants', [
            'event_id' => 10,
            'email' => 'HELPER@example.org',
            'can_flow' => true,
            'can_scor' => false,
        ], $this->bearer('rp', 'rp@example.org'))->assertStatus(409);
    }

    public function test_verified_invitee_without_keycloak_role_gets_in(): void
    {
        $this->invite('guest@example.org', canFlow: true, canScor: true);

        $this->getJson('/api/environment', $this->bearer('guest', 'Guest@example.org', roles: []))
            ->assertOk();

        $guestId = DB::table('user')->where('subject', 'guest')->value('id');
        $grant = DB::table('event_user_access')->where('user', $guestId)->where('event', 10)->first();
        $this->assertNotNull($grant);
        $this->assertTrue((bool) $grant->can_flow);
        $this->assertTrue((bool) $grant->can_scor);
        $this->assertSame(1, (int) $grant->granted_by);
        $this->assertSame(0, DB::table('event_invitation')->count());
        $this->assertFalse(DB::table('event_user_access')->where('user', $guestId)->where('event', 11)->exists());
        $this->assertTrue(DB::table('access_log')->where('action', 'claimed')->where('target_user', $guestId)->exists());
    }

    public function test_unverified_email_does_not_claim(): void
    {
        $this->invite('guest@example.org', canFlow: true, canScor: false);

        $this->getJson('/api/environment', $this->bearer('guest', 'guest@example.org', roles: [], verified: false))
            ->assertForbidden();

        $this->assertSame(1, DB::table('event_invitation')->count());
    }

    public function test_scor_only_grant_opens_scor_but_not_flow(): void
    {
        $this->invite('judge@example.org', canFlow: false, canScor: true);
        $headers = $this->bearer('judge', 'judge@example.org', roles: []);

        $this->getJson('/api/scor/ping', $headers)->assertOk();
        $this->getJson('/api/environment', $headers)->assertForbidden();
    }

    public function test_keycloak_flow_role_still_opens_flow_but_not_scor(): void
    {
        $headers = $this->bearer('staff', 'staff@example.org');

        $this->getJson('/api/environment', $headers)->assertOk();
        $this->getJson('/api/scor/ping', $headers)->assertForbidden();
    }

    public function test_draht_contact_changes_and_revokes_manual_grant(): void
    {
        $headers = $this->bearer('rp', 'rp@example.org');

        $this->patchJson('/api/user/access/grants', [
            'event_id' => 10, 'user_id' => 2, 'can_flow' => true, 'can_scor' => true,
        ], $headers)->assertOk();
        $this->assertTrue((bool) DB::table('event_user_access')->where('user', 2)->value('can_scor'));

        $this->deleteJson('/api/user/access/grants', ['event_id' => 10, 'user_id' => 2], $headers)->assertOk();
        $this->assertFalse(DB::table('event_user_access')->where('user', 2)->exists());
        $this->assertTrue(DB::table('access_log')->where('action', 'revoked')->where('target_user', 2)->exists());
    }

    public function test_draht_grants_cannot_be_changed(): void
    {
        DB::table('user')->insert(['id' => 3, 'subject' => 'rp2', 'name' => 'RP 2', 'email' => 'rp2@example.org']);
        DB::table('user_regional_partner')->insert([
            'user' => 3, 'regional_partner' => 1, 'source' => 'draht', 'can_flow' => true, 'can_scor' => true,
        ]);

        $this->patchJson('/api/user/access/grants', [
            'event_id' => 10, 'user_id' => 3, 'can_flow' => true, 'can_scor' => false,
        ], $this->bearer('rp', 'rp@example.org'))->assertStatus(409);
    }

    public function test_expired_invitation_is_not_claimed(): void
    {
        DB::table('event_invitation')->insert([
            'event' => 10,
            'email' => 'guest@example.org',
            'can_flow' => true,
            'can_scor' => false,
            'invited_by' => 1,
            'invited_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson('/api/environment', $this->bearer('guest', 'guest@example.org', roles: []))
            ->assertForbidden();

        $this->assertSame(0, DB::table('event_invitation')->count());
        $this->assertTrue(DB::table('access_log')->where('action', 'expired')->exists());
    }

    public function test_removing_the_draht_contact_keeps_the_granted_access(): void
    {
        $this->invite('guest@example.org', canFlow: true, canScor: false);
        $this->getJson('/api/environment', $this->bearer('guest', 'guest@example.org', roles: []))->assertOk();

        DB::table('user_regional_partner')->where('user', 1)->delete();

        $guestId = (int) DB::table('user')->where('subject', 'guest')->value('id');
        $this->assertTrue(DB::table('event_user_access')->where('user', $guestId)->where('event', 10)->exists());
        $this->getJson('/api/environment', $this->bearer('guest', 'guest@example.org', roles: []))->assertOk();
    }

    public function test_invitation_mail_is_sent(): void
    {
        $this->postJson('/api/user/access/grants', [
            'event_id' => 10,
            'email' => 'new.person@example.org',
            'can_flow' => true,
            'can_scor' => false,
        ], $this->bearer('rp', 'rp@example.org'))->assertCreated()->assertJsonPath('mail_sent', true);

        Mail::assertSent(\App\Mail\EventAccessInvitationMail::class, function ($mail) {
            return $mail->hasTo('new.person@example.org')
                && $mail->eventName === 'Leipzig'
                && $mail->permissions === ['FLOW'];
        });
    }

    private function invite(string $email, bool $canFlow, bool $canScor): void
    {
        DB::table('event_invitation')->insert([
            'event' => 10,
            'email' => $email,
            'can_flow' => $canFlow,
            'can_scor' => $canScor,
            'invited_by' => 1,
            'invited_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    private function bearer(string $subject, string $email, array $roles = ['flow_user'], bool $verified = true): array
    {
        $payload = [
            'iss' => 'https://sso.hands-on-technology.org/realms/master',
            'aud' => 'flow',
            'sub' => $subject,
            'sid' => 'session-'.$subject,
            'name' => ucfirst($subject),
            'email' => $email,
            'email_verified' => $verified,
            'resource_access' => ['flow' => ['roles' => $roles]],
            'exp' => time() + 3600,
            'iat' => time(),
        ];

        return ['Authorization' => 'Bearer '.JWT::encode($payload, $this->privatePem, 'RS256')];
    }

    private function writeKeyPair(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);

        $privatePem = '';
        openssl_pkey_export($key, $privatePem);
        $this->privatePem = $privatePem;
        $details = openssl_pkey_get_details($key);
        $path = base_path($this->publicKeyRelativePath);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $details['key']);
    }

    private function createSchema(): void
    {
        foreach (['access_log', 'event_user_access', 'event_invitation', 'user_regional_partner', 'event', 'regional_partner', 'user'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('user', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('subject')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('nick')->nullable();
            $table->integer('dolibarr_id')->nullable();
            $table->timestamp('last_login')->nullable();
            $table->unsignedInteger('selection_event')->nullable();
            $table->unsignedInteger('selection_regional_partner')->nullable();
        });

        Schema::create('regional_partner', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('region')->nullable();
        });

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('regional_partner');
        });

        Schema::create('user_regional_partner', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('user');
            $table->unsignedInteger('regional_partner');
            $table->string('source', 16)->default('draht');
            $table->boolean('can_flow')->default(true);
            $table->boolean('can_scor')->default(false);
            $table->boolean('can_scor_referee')->default(false);
            $table->boolean('can_scor_jury')->default(false);
            $table->boolean('can_scor_admin')->default(false);
            $table->timestamp('granted_at')->nullable();
            $table->unsignedInteger('granted_by')->nullable();
        });

        Schema::create('event_invitation', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->string('email');
            $table->boolean('can_flow')->default(true);
            $table->boolean('can_scor')->default(false);
            $table->boolean('can_scor_referee')->default(false);
            $table->boolean('can_scor_jury')->default(false);
            $table->boolean('can_scor_admin')->default(false);
            $table->unsignedInteger('invited_by')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });

        Schema::create('event_user_access', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('user');
            $table->unsignedInteger('event');
            $table->boolean('can_flow')->default(false);
            $table->boolean('can_scor')->default(false);
            $table->boolean('can_scor_referee')->default(false);
            $table->boolean('can_scor_jury')->default(false);
            $table->boolean('can_scor_admin')->default(false);
            $table->timestamp('granted_at')->nullable();
            $table->unsignedInteger('granted_by')->nullable();
        });

        Schema::create('access_log', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->timestamp('created_at')->nullable();
            $table->unsignedInteger('event')->nullable();
            $table->unsignedInteger('regional_partner')->nullable();
            $table->unsignedInteger('actor_user')->nullable();
            $table->unsignedInteger('target_user')->nullable();
            $table->string('target_email')->nullable();
            $table->string('action', 32);
            $table->boolean('can_flow')->nullable();
            $table->boolean('can_scor')->nullable();
            $table->boolean('can_scor_referee')->nullable();
            $table->boolean('can_scor_jury')->nullable();
            $table->boolean('can_scor_admin')->nullable();
        });

        Cache::flush();
    }
}
