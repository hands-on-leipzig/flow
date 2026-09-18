<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EventVolunteerInquiryController;
use App\Models\Event;
use App\Models\VolunteerInquiry;
use App\Services\StaffingSyncService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicVolunteerInquiryTest extends TestCase
{
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
        ]);
        $listed = app(EventVolunteerInquiryController::class)->index(Event::query()->findOrFail(1));
        $this->assertCount(1, $listed->getData(true)['inquiries']);
        $this->assertSame('Ada', $listed->getData(true)['inquiries'][0]['first_name']);
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
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('email', 255);
                $table->string('mobile', 50)->nullable();
                $table->string('organization', 255)->nullable();
                $table->timestamps();
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
        }
    }
}
