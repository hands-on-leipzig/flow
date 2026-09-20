<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EventVolunteerInquiryController;
use App\Mail\VolunteerInquiryAcceptedMail;
use App\Mail\VolunteerInquiryDeclinedMail;
use App\Mail\VolunteerInquiryPlannerMail;
use App\Models\Event;
use App\Models\VolunteerInquiry;
use App\Models\VolunteerPerson;
use App\Services\StaffingSyncService;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicVolunteerInquiryTest extends TestCase
{
    private string $privatePem;

    private string $publicKeyRelativePath = 'storage/framework/testing/oauth-public.pem';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Public volunteer inquiry tests require sqlite.');
        }

        Carbon::setTestNow('2026-09-03');
        $this->createSchema();
        $this->truncateData();
        $this->seedBase();
        $this->writeKeyPair();
        config([
            'services.keycloak.public_key_path' => $this->publicKeyRelativePath,
        ]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_stores_inquiry_without_adding_person(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
        ]);

        $response = $this->postJson('/api/public/volunteer-inquiries', [
            'event_id' => 1,
            'role' => 'Schiedsrichter',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
            'mobile' => '0171 1234567',
            'message' => 'Ich helfe gern am Samstag.',
        ]);

        $response->assertCreated();
        $this->assertSame(0, DB::table('volunteer_person')->count());
        $this->assertSame(0, DB::table('event_volunteer_roster')->count());
        $this->assertDatabaseHas('volunteer_inquiry', [
            'event' => 1,
            'role' => 'Schiedsrichter',
            'email' => 'ada@example.org',
            'status' => 'pending',
            'volunteer_person' => null,
            'draht_id' => null,
        ]);
        $listed = app(EventVolunteerInquiryController::class)->index(Event::query()->findOrFail(1));
        $this->assertCount(1, $listed->getData(true)['inquiries']);
        $this->assertSame('Ada', $listed->getData(true)['inquiries'][0]['first_name']);
        $this->assertFalse($listed->getData(true)['inquiries'][0]['has_account']);
        $this->assertNull($listed->getData(true)['inquiries'][0]['draht_id']);
        Mail::assertSent(VolunteerInquiryPlannerMail::class, function (VolunteerInquiryPlannerMail $mail) {
            return $mail->hasTo('rp@example.org')
                && $mail->eventName === 'Leipzig'
                && $mail->personName === 'Ada Lovelace'
                && $mail->role === 'Schiedsrichter';
        });
        Mail::assertNotSent(VolunteerInquiryAcceptedMail::class);
        Mail::assertNotSent(VolunteerInquiryDeclinedMail::class);
    }

    public function test_accept_creates_person_and_roster(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
        ]);

        $this->postJson('/api/public/volunteer-inquiries', [
            'event_id' => 1,
            'role' => 'Schiedsrichter',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
            'mobile' => '0171 1234567',
        ])->assertCreated();

        $event = Event::query()->findOrFail(1);
        $inquiry = VolunteerInquiry::query()->firstOrFail();
        $controller = app(EventVolunteerInquiryController::class);

        $accepted = $controller->accept($event, $inquiry);
        $this->assertSame(200, $accepted->getStatusCode());

        $this->assertDatabaseHas('volunteer_person', [
            'email' => 'ada@example.org',
            'regional_partner' => 1,
            'first_name' => 'Ada',
            'draht_id' => null,
        ]);
        $personId = (int) DB::table('volunteer_person')->where('email', 'ada@example.org')->value('id');
        $this->assertDatabaseHas('event_volunteer_roster', [
            'event' => 1,
            'volunteer_person' => $personId,
        ]);
        $this->assertDatabaseHas('volunteer_inquiry', [
            'id' => $inquiry->id,
            'status' => 'accepted',
            'volunteer_person' => $personId,
        ]);
        $this->assertSame([], $controller->index($event)->getData(true)['inquiries']);
        Mail::assertSent(VolunteerInquiryAcceptedMail::class, function (VolunteerInquiryAcceptedMail $mail) {
            return $mail->hasTo('ada@example.org')
                && $mail->eventName === 'Leipzig'
                && $mail->personName === 'Ada Lovelace';
        });
    }

    public function test_decline_leaves_person_pool_empty(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
        ]);

        $this->postJson('/api/public/volunteer-inquiries', [
            'event_id' => 1,
            'role' => 'Schiedsrichter',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
        ])->assertCreated();

        $event = Event::query()->findOrFail(1);
        $inquiry = VolunteerInquiry::query()->firstOrFail();
        $controller = app(EventVolunteerInquiryController::class);

        $declined = $controller->decline($event, $inquiry);
        $this->assertSame(200, $declined->getStatusCode());
        $this->assertSame(0, DB::table('volunteer_person')->count());
        $this->assertSame(0, DB::table('event_volunteer_roster')->count());
        $this->assertDatabaseHas('volunteer_inquiry', [
            'id' => $inquiry->id,
            'status' => 'declined',
            'volunteer_person' => null,
        ]);
        $this->assertSame([], $controller->index($event)->getData(true)['inquiries']);
        Mail::assertSent(VolunteerInquiryDeclinedMail::class, function (VolunteerInquiryDeclinedMail $mail) {
            return $mail->hasTo('ada@example.org') && $mail->eventName === 'Leipzig';
        });
    }

    public function test_rejects_role_that_is_not_open(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
        ]);

        $response = $this->postJson('/api/public/volunteer-inquiries', [
            'event_id' => 1,
            'role' => 'Laufhilfe',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('volunteer_inquiry')->count());
    }

    public function test_ignores_client_supplied_draht_id_without_token(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
        ]);

        $this->postJson('/api/public/volunteer-inquiries', [
            'event_id' => 1,
            'role' => 'Schiedsrichter',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
            'draht_id' => 777,
        ])->assertCreated();

        $this->assertDatabaseHas('volunteer_inquiry', [
            'email' => 'ada@example.org',
            'draht_id' => null,
        ]);
    }

    public function test_signed_in_inquiry_stores_draht_id_from_hero_token(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
        ]);

        $this->postJson('/api/public/volunteer-inquiries', [
            'event_id' => 1,
            'role' => 'Schiedsrichter',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
        ], $this->heroBearer(['dolibarr_contact_id' => 4242]))->assertCreated();

        $this->assertDatabaseHas('volunteer_inquiry', [
            'email' => 'ada@example.org',
            'draht_id' => 4242,
        ]);
        $listed = app(EventVolunteerInquiryController::class)->index(Event::query()->findOrFail(1));
        $this->assertTrue($listed->getData(true)['inquiries'][0]['has_account']);
        $this->assertSame(4242, $listed->getData(true)['inquiries'][0]['draht_id']);
    }

    public function test_accept_creates_second_person_when_email_already_exists_without_draht_id(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
        ]);

        $existing = VolunteerPerson::query()->create([
            'regional_partner' => 1,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
            'mobile' => null,
        ]);

        $this->postJson('/api/public/volunteer-inquiries', [
            'event_id' => 1,
            'role' => 'Schiedsrichter',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
        ], $this->heroBearer(['dolibarr_id' => 88]))->assertCreated();

        $event = Event::query()->findOrFail(1);
        $inquiry = VolunteerInquiry::query()->firstOrFail();
        app(EventVolunteerInquiryController::class)->accept($event, $inquiry);

        $this->assertSame(2, VolunteerPerson::query()->count());
        $this->assertNull(VolunteerPerson::query()->find($existing->id)?->draht_id);
        $this->assertDatabaseHas('volunteer_person', [
            'email' => 'ada@example.org',
            'draht_id' => 88,
        ]);
        $this->assertNotSame($existing->id, (int) $inquiry->fresh()->volunteer_person);
    }

    public function test_accept_matches_existing_person_by_draht_id_even_when_email_differs(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
        ]);

        $existing = VolunteerPerson::query()->create([
            'regional_partner' => 1,
            'draht_id' => 55,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'old@example.org',
        ]);

        $this->postJson('/api/public/volunteer-inquiries', [
            'event_id' => 1,
            'role' => 'Schiedsrichter',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'new@example.org',
        ], $this->heroBearer(['dolibarr_contact_id' => 55]))->assertCreated();

        $event = Event::query()->findOrFail(1);
        $inquiry = VolunteerInquiry::query()->firstOrFail();
        app(EventVolunteerInquiryController::class)->accept($event, $inquiry);

        $this->assertSame(1, VolunteerPerson::query()->count());
        $this->assertDatabaseHas('volunteer_inquiry', [
            'id' => $inquiry->id,
            'volunteer_person' => $existing->id,
            'status' => 'accepted',
        ]);
        $this->assertSame('old@example.org', VolunteerPerson::query()->find($existing->id)?->email);
    }

    public function test_invalid_bearer_is_ignored_and_stays_local(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
        ]);

        $this->postJson('/api/public/volunteer-inquiries', [
            'event_id' => 1,
            'role' => 'Schiedsrichter',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
        ], ['Authorization' => 'Bearer not-a-jwt'])->assertCreated();

        $this->assertDatabaseHas('volunteer_inquiry', [
            'email' => 'ada@example.org',
            'draht_id' => null,
        ]);
    }

    public function test_rejects_event_without_public_helper_search(): void
    {
        $this->mockOpenPositions([5 => []]);

        $response = $this->postJson('/api/public/volunteer-inquiries', [
            'event_id' => 5,
            'role' => 'Technik',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
        ]);

        $response->assertStatus(422);
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $byEventId
     */
    private function mockOpenPositions(array $byEventId): void
    {
        $this->mock(StaffingSyncService::class, function ($mock) use ($byEventId) {
            $mock->shouldReceive('openPositionsByScope')->andReturnUsing(
                function (int $eventId) use ($byEventId) {
                    return $byEventId[$eventId] ?? [];
                }
            );
        });
    }

    private function seedBase(): void
    {
        DB::table('m_season')->insert([
            'id' => 1,
            'year' => 2026,
        ]);
        DB::table('regional_partner')->insert([
            'id' => 1,
            'name' => 'RP Leipzig',
            'region' => 'Sachsen',
        ]);
        DB::table('user')->insert([
            'id' => 9,
            'subject' => 'rp-leipzig',
            'name' => 'RP Leipzig',
            'email' => 'rp@example.org',
        ]);
        DB::table('user_regional_partner')->insert([
            'user' => 9,
            'regional_partner' => 1,
        ]);
        DB::table('m_first_program')->insert([
            'id' => 2,
            'name' => 'CHALLENGE',
            'display_name' => 'Challenge',
            'sequence' => 2,
            'color_hex' => 'E87722',
        ]);
        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Leipzig',
            'slug' => 'leipzig',
            'regional_partner' => 1,
            'level' => 1,
            'season' => 1,
            'date' => '2026-11-15',
            'days' => 1,
            'public_helper_search' => true,
        ]);
        DB::table('event')->insert([
            'id' => 5,
            'name' => 'Ohne Suche',
            'slug' => 'no-search',
            'regional_partner' => 1,
            'level' => 1,
            'season' => 1,
            'date' => '2026-11-22',
            'days' => 1,
            'public_helper_search' => false,
        ]);
        DB::table('event_program')->insert([
            'event' => 1,
            'first_program' => 2,
            'draht_id' => 101,
        ]);
    }

    private function truncateData(): void
    {
        foreach ([
            'volunteer_inquiry',
            'event_volunteer_roster',
            'volunteer_person',
            'user_regional_partner',
            'user',
            'event_program',
            'event',
            'regional_partner',
            'm_first_program',
            'm_season',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('m_season')) {
            Schema::create('m_season', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->unsignedSmallInteger('year');
            });
        }
        if (! Schema::hasTable('regional_partner')) {
            Schema::create('regional_partner', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->string('name')->nullable();
                $table->string('region')->nullable();
            });
        }
        if (! Schema::hasTable('user')) {
            Schema::create('user', function (Blueprint $table) {
                $table->increments('id');
                $table->string('subject')->nullable();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
            });
        }
        if (! Schema::hasTable('user_regional_partner')) {
            Schema::create('user_regional_partner', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user');
                $table->unsignedInteger('regional_partner');
            });
        }
        if (! Schema::hasTable('m_first_program')) {
            Schema::create('m_first_program', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->string('name')->nullable();
                $table->string('display_name')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->string('color_hex', 6)->nullable();
            });
        }
        if (! Schema::hasTable('event_program')) {
            Schema::create('event_program', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program')->nullable();
                $table->unsignedInteger('draht_id')->nullable();
            });
        }
        if (! Schema::hasTable('event')) {
            Schema::create('event', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->unsignedInteger('regional_partner')->nullable();
                $table->unsignedTinyInteger('level')->default(1);
                $table->unsignedInteger('season')->nullable();
                $table->date('date')->nullable();
                $table->unsignedTinyInteger('days')->default(1);
                $table->boolean('public_helper_search')->default(false);
            });
        }
        if (! Schema::hasTable('volunteer_person')) {
            Schema::create('volunteer_person', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('regional_partner');
                $table->unsignedInteger('draht_id')->nullable();
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('email', 255);
                $table->string('mobile', 50)->nullable();
                $table->string('organization', 255)->nullable();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('volunteer_person', 'draht_id')) {
            Schema::table('volunteer_person', function (Blueprint $table) {
                $table->unsignedInteger('draht_id')->nullable();
            });
        }
        if (! Schema::hasTable('event_volunteer_roster')) {
            Schema::create('event_volunteer_roster', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('volunteer_person');
                $table->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasTable('volunteer_inquiry')) {
            Schema::create('volunteer_inquiry', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('volunteer_person')->nullable();
                $table->unsignedInteger('draht_id')->nullable();
                $table->string('role', 255);
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('email', 255);
                $table->string('mobile', 50)->nullable();
                $table->text('message')->nullable();
                $table->string('status', 20)->default('pending');
                $table->timestamp('decided_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        } elseif (! Schema::hasColumn('volunteer_inquiry', 'draht_id')) {
            Schema::table('volunteer_inquiry', function (Blueprint $table) {
                $table->unsignedInteger('draht_id')->nullable();
            });
        }
    }

    /**
     * @param  array<string, mixed>  $extraClaims
     * @return array{Authorization: string}
     */
    private function heroBearer(array $extraClaims): array
    {
        $payload = array_merge([
            'iss' => 'https://sso.hands-on-technology.org/realms/master',
            'aud' => 'hero',
            'sub' => 'volunteer-1',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.org',
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
}
