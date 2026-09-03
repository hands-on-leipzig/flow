<?php

namespace Tests\Feature;

use App\Services\CheckInService;
use App\Services\CockpitService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DayAppSessionAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Day-app session auth tests require sqlite.');
        }

        Carbon::setTestNow('2026-09-03 12:00:00');
        $this->createSchema();
        $this->truncateData();
        $this->seedSeason();
        $this->seedEvent();
        RateLimiter::clear('day-app-pin:check-in:day-event:127.0.0.1');
        RateLimiter::clear('day-app-pin:cockpit:day-event:127.0.0.1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_check_in_session_issues_namespaced_token(): void
    {
        $response = $this->postJson('/api/check-in/day-event/session', ['pin' => '123456']);
        $response->assertOk();
        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $payload = json_decode(Crypt::decryptString($token), true);
        $this->assertSame(1, $payload['event_id']);
        $this->assertSame(CheckInService::SESSION_APP, $payload['app']);
        $this->assertNotEmpty($payload['issued_at']);
    }

    public function test_header_token_authorizes_cockpit_organizer(): void
    {
        $token = $this->postJson('/api/cockpit/day-event/session', ['pin' => '654321'])->json('token');

        $this->withHeader('X-Cockpit-Token', $token)
            ->getJson('/api/cockpit/day-event/organizer')
            ->assertOk()
            ->assertJsonPath('organizer', null);
    }

    public function test_query_string_token_is_ignored(): void
    {
        $token = $this->postJson('/api/cockpit/day-event/session', ['pin' => '654321'])->json('token');

        $this->getJson('/api/cockpit/day-event/organizer?token='.urlencode($token))
            ->assertUnauthorized();
    }

    public function test_cockpit_rejects_check_in_token(): void
    {
        $checkInToken = $this->postJson('/api/check-in/day-event/session', ['pin' => '123456'])->json('token');

        $this->withHeader('X-Cockpit-Token', $checkInToken)
            ->getJson('/api/cockpit/day-event/organizer')
            ->assertUnauthorized();
    }

    public function test_check_in_rejects_cockpit_token(): void
    {
        $cockpitToken = $this->postJson('/api/cockpit/day-event/session', ['pin' => '654321'])->json('token');

        $this->withHeader('X-Check-In-Token', $cockpitToken)
            ->getJson('/api/check-in/day-event/organizer')
            ->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = app(CockpitService::class)->makeSessionToken(
            \App\Models\Event::query()->findOrFail(1)
        );

        Carbon::setTestNow('2026-09-04 12:00:01');

        $this->withHeader('X-Cockpit-Token', $token)
            ->getJson('/api/cockpit/day-event/organizer')
            ->assertUnauthorized();
    }

    public function test_legacy_token_without_app_is_rejected(): void
    {
        $legacy = Crypt::encryptString(json_encode([
            'event_id' => 1,
            'issued_at' => now()->toIso8601String(),
        ]));

        $this->withHeader('X-Cockpit-Token', $legacy)
            ->getJson('/api/cockpit/day-event/organizer')
            ->assertUnauthorized();
    }

    public function test_pin_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/check-in/day-event/session', ['pin' => '000000'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/check-in/day-event/session', ['pin' => '000000'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'Zu viele PIN-Versuche. Bitte warte eine Minute.');

        $this->postJson('/api/check-in/day-event/session', ['pin' => '123456'])
            ->assertStatus(429);
    }

    public function test_successful_pin_clears_rate_limit(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/cockpit/day-event/session', ['pin' => '000000'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/cockpit/day-event/session', ['pin' => '654321'])
            ->assertOk();

        $this->postJson('/api/cockpit/day-event/session', ['pin' => '000000'])
            ->assertUnauthorized();
    }

    private function seedSeason(): void
    {
        DB::table('m_season')->insert([
            'id' => 1,
            'year' => 2026,
        ]);
    }

    private function seedEvent(): void
    {
        $checkIn = app(CheckInService::class);
        $cockpit = app(CockpitService::class);

        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Day Event',
            'slug' => 'day-event',
            'regional_partner' => 1,
            'level' => 1,
            'season' => 1,
            'date' => '2026-09-03',
            'days' => 1,
            'link' => 'https://example.com/day-event',
            'check_in_enabled' => true,
            'check_in_pin' => $checkIn->encryptPin('123456'),
            'cockpit_enabled' => true,
            'cockpit_pin' => $cockpit->encryptPin('654321'),
        ]);
    }

    private function truncateData(): void
    {
        foreach ([
            'event_staffing_assignment',
            'event_staffing_group',
            'event_staffing_role',
            'volunteer_person',
            'event_program',
            'event',
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
                $table->string('link')->nullable();
                $table->boolean('check_in_enabled')->default(false);
                $table->text('check_in_pin')->nullable();
                $table->boolean('cockpit_enabled')->default(false);
                $table->text('cockpit_pin')->nullable();
            });
        }

        if (! Schema::hasTable('volunteer_person')) {
            Schema::create('volunteer_person', function (Blueprint $table) {
                $table->increments('id');
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('mobile')->nullable();
            });
        }

        if (! Schema::hasTable('event_staffing_role')) {
            Schema::create('event_staffing_role', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('m_role')->nullable();
            });
        }

        if (! Schema::hasTable('event_staffing_group')) {
            Schema::create('event_staffing_group', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event_staffing_role');
            });
        }

        if (! Schema::hasTable('event_staffing_assignment')) {
            Schema::create('event_staffing_assignment', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event_staffing_group');
                $table->unsignedInteger('volunteer_person');
            });
        }
    }
}
