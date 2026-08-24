<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\DrahtController;
use App\Http\Controllers\Api\PublishController;
use App\Models\EventCalendar;
use App\Services\CalendarFeedService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CalendarFeedServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('CalendarFeedService tests require sqlite.');
        }

        config([
            'app.env' => 'production',
            'app.url' => 'https://flow.hands-on-technology.org',
        ]);

        $this->createSchema();
    }

    public function test_environment_label_from_env_and_host(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://flow.hands-on-technology.org']);
        $this->assertNull(CalendarFeedService::environmentLabel());

        config(['app.env' => 'testing', 'app.url' => 'https://test.flow.hands-on-technology.org']);
        $this->assertSame('TEST', CalendarFeedService::environmentLabel());

        config(['app.env' => 'local', 'app.url' => 'https://dev.flow.hands-on-technology.org']);
        $this->assertSame('DEV', CalendarFeedService::environmentLabel());

        config(['app.env' => 'local', 'app.url' => 'http://localhost']);
        $this->assertSame('LOCAL', CalendarFeedService::environmentLabel());
    }

    public function test_skips_event_without_slug_and_does_not_call_draht(): void
    {
        $this->insertEvent(['slug' => null, 'link' => null]);
        $this->mock(DrahtController::class, function ($mock) {
            $mock->shouldReceive('fetchScheduleData')->never();
        });

        $result = app(CalendarFeedService::class)->rebuild(1);

        $this->assertSame(CalendarFeedService::RESULT_SKIPPED, $result);
        $this->assertSame(0, EventCalendar::query()->count());
    }

    public function test_writes_vevent_with_title_url_contact_and_empty_location(): void
    {
        $this->insertEvent();
        $this->mockDraht(ok: true, data: [
            'programs' => [],
            'address' => null,
            'contact' => [[
                'contact' => 'Ada Lovelace',
                'contact_email' => 'ada@example.org',
                'contact_infos' => 'RP West',
            ]],
            'information' => null,
        ]);

        $result = app(CalendarFeedService::class)->rebuild(1);
        $row = EventCalendar::query()->where('event', 1)->first();

        $this->assertSame(CalendarFeedService::RESULT_BUILT, $result);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->sequence);
        $this->assertSame('event-1@flow.hands-on-technology.org', $row->uid);
        $this->assertStringContainsString('SUMMARY:FIRST LEGO League Wettbewerb Aachen', $row->vevent);
        $this->assertStringContainsString('URL:https://flow.hands-on-technology.org/aachen', $row->vevent);
        $this->assertStringContainsString('LOCATION:', $row->vevent);
        $this->assertStringContainsString('DESCRIPTION:', $row->vevent);
        $this->assertStringContainsString('Ada Lovelace', $row->vevent);
        $this->assertStringNotContainsString('STATUS:CANCELLED', $row->vevent);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260315', $row->vevent);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260316', $row->vevent);
    }

    public function test_writes_location_from_draht_address(): void
    {
        $this->insertEvent();
        $this->mockDraht(ok: true, data: [
            'programs' => [],
            'address' => "Halle 1\n52062 Aachen",
            'contact' => [],
            'information' => null,
        ]);

        app(CalendarFeedService::class)->rebuild(1);
        $vevent = EventCalendar::query()->where('event', 1)->value('vevent');

        $this->assertStringContainsString('LOCATION:Halle 1\\n52062 Aachen', $vevent);
    }

    public function test_keeps_previous_vevent_when_draht_fails(): void
    {
        $this->insertEvent();
        EventCalendar::query()->insert([
            'event' => 1,
            'date' => '2026-03-15',
            'uid' => 'event-1@flow.hands-on-technology.org',
            'sequence' => 4,
            'vevent' => "BEGIN:VEVENT\r\nSUMMARY:OLD\r\nEND:VEVENT",
            'built_at' => '2026-01-01 00:00:00',
        ]);
        $this->mockDraht(ok: false, data: CalendarFeedService::emptyDrahtData());

        $result = app(CalendarFeedService::class)->rebuild(1);
        $row = EventCalendar::query()->where('event', 1)->first();

        $this->assertSame(CalendarFeedService::RESULT_KEPT, $result);
        $this->assertSame(4, (int) $row->sequence);
        $this->assertStringContainsString('SUMMARY:OLD', $row->vevent);
    }

    public function test_builds_with_empty_location_when_draht_fails_and_no_row(): void
    {
        $this->insertEvent();
        $this->mockDraht(ok: false, data: CalendarFeedService::emptyDrahtData());

        $result = app(CalendarFeedService::class)->rebuild(1);
        $row = EventCalendar::query()->where('event', 1)->first();

        $this->assertSame(CalendarFeedService::RESULT_BUILT, $result);
        $this->assertNotNull($row);
        $this->assertStringContainsString('LOCATION:', $row->vevent);
        $this->assertStringContainsString('SUMMARY:FIRST LEGO League', $row->vevent);
    }

    public function test_increments_sequence_on_rebuild(): void
    {
        $this->insertEvent();
        $this->mockDraht(ok: true, data: CalendarFeedService::emptyDrahtData());

        app(CalendarFeedService::class)->rebuild(1);
        app(CalendarFeedService::class)->rebuild(1);

        $this->assertSame(1, (int) EventCalendar::query()->where('event', 1)->value('sequence'));
    }

    public function test_level_3_description_includes_plan_times(): void
    {
        $this->insertEvent();
        DB::table('publication')->insert([
            'id' => 1,
            'event' => 1,
            'level' => 3,
            'last_change' => '2026-03-01 00:00:00',
        ]);
        $this->mockDraht(ok: true, data: CalendarFeedService::emptyDrahtData());
        $this->mock(PublishController::class, function ($mock) {
            $mock->shouldReceive('importantTimesPayload')->once()->andReturn([
                'challenge' => [
                    ['label' => 'Eröffnung', 'value' => '2026-03-15 09:30:00'],
                ],
            ]);
        });

        app(CalendarFeedService::class)->rebuild(1);
        $vevent = EventCalendar::query()->where('event', 1)->value('vevent');

        $this->assertStringContainsString('Zeitplan', $vevent);
        $this->assertStringContainsString('09:30 Eröffnung', $vevent);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertEvent(array $overrides = []): void
    {
        DB::table('event')->insert(array_merge([
            'id' => 1,
            'name' => 'Aachen',
            'slug' => 'aachen',
            'regional_partner' => 1,
            'level' => 1,
            'season' => 1,
            'date' => '2026-03-15',
            'days' => 1,
            'link' => 'https://flow.hands-on-technology.org/aachen',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mockDraht(bool $ok, array $data): void
    {
        $this->mock(DrahtController::class, function ($mock) use ($ok, $data) {
            $mock->shouldReceive('fetchScheduleData')->andReturn([
                'ok' => $ok,
                'data' => $data,
            ]);
        });
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('event_calendar');
        Schema::dropIfExists('publication');
        Schema::dropIfExists('event_program');
        Schema::dropIfExists('event');
        Schema::dropIfExists('m_first_program');

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('letter')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
        });

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->unsignedInteger('regional_partner')->default(1);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('season')->default(1);
            $table->date('date');
            $table->unsignedTinyInteger('days')->default(1);
            $table->string('link')->nullable();
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('draht_id')->nullable();
        });

        Schema::create('publication', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('level');
            $table->timestamp('last_change');
        });

        Schema::create('event_calendar', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event')->unique();
            $table->date('date');
            $table->string('uid');
            $table->unsignedInteger('sequence')->default(0);
            $table->longText('vevent');
            $table->timestamp('built_at');
        });
    }
}
