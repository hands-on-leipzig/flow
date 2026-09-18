<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KeycloakLoginSideEffectsTest extends TestCase
{
    private string $privatePem;

    private string $publicKeyRelativePath = 'storage/framework/testing/oauth-public.pem';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Keycloak login side-effect tests require sqlite.');
        }

        $this->createSchema();
        $this->writeKeyPair();

        config([
            'services.keycloak.public_key_path' => $this->publicKeyRelativePath,
            'services.draht_api.base_url' => 'https://draht.test/api',
            'services.draht_api.key' => 'test-key',
        ]);

        Http::fake([
            'https://draht.test/api/handson/contact/*' => Http::response([], 200),
        ]);
    }

    public function test_draht_partner_sync_runs_once_per_keycloak_session(): void
    {
        $this->makeUser();

        $this->getJson('/api/environment', $this->bearer(['sid' => 'session-a']))->assertOk();
        $this->getJson('/api/environment', $this->bearer(['sid' => 'session-a']))->assertOk();
        $this->getJson('/api/environment', $this->bearer(['sid' => 'session-a']))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_a_new_keycloak_session_syncs_again(): void
    {
        $this->makeUser();

        $this->getJson('/api/environment', $this->bearer(['sid' => 'session-a']))->assertOk();
        $this->getJson('/api/environment', $this->bearer(['sid' => 'session-b']))->assertOk();

        Http::assertSentCount(2);
    }

    public function test_recent_last_login_skips_sync_when_token_has_no_sid(): void
    {
        $this->makeUser(['last_login' => now()]);

        $this->getJson('/api/environment', $this->bearer([]))->assertOk();

        Http::assertSentCount(0);
    }

    public function test_stale_last_login_syncs_when_token_has_no_sid(): void
    {
        $this->makeUser(['last_login' => now()->subHour()]);

        $this->getJson('/api/environment', $this->bearer([]))->assertOk();
        $this->getJson('/api/environment', $this->bearer([]))->assertOk();

        Http::assertSentCount(1);
        $this->assertTrue(User::query()->where('subject', 'user-1')->first()->last_login->gt(now()->subMinute()));
    }

    private function makeUser(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'subject' => 'user-1',
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'dolibarr_id' => 99,
            'last_login' => now()->subHour(),
        ], $overrides));
    }

    private function bearer(array $extraClaims): array
    {
        $payload = array_merge([
            'iss' => 'https://sso.hands-on-technology.org/realms/master',
            'aud' => 'flow',
            'sub' => 'user-1',
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'dolibarr_id' => 99,
            'resource_access' => [
                'flow' => ['roles' => ['flow_user']],
            ],
            'exp' => time() + 3600,
            'iat' => time(),
        ], $extraClaims);

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
        Schema::dropIfExists('user_regional_partner');
        Schema::dropIfExists('user');

        Schema::create('user', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('subject')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->integer('dolibarr_id')->nullable();
            $table->timestamp('last_login')->nullable();
            $table->unsignedInteger('selection_event')->nullable();
            $table->unsignedInteger('selection_regional_partner')->nullable();
        });

        Schema::create('user_regional_partner', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('user');
            $table->unsignedInteger('regional_partner');
            $table->string('source', 16)->default('draht');
            $table->timestamp('granted_at')->nullable();
            $table->unsignedInteger('granted_by')->nullable();
        });

        Cache::flush();
    }
}
