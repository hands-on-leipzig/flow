<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\DrahtController;
use App\Models\EventCalendar;
use App\Services\CalendarFeedService;
use Carbon\Carbon;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    public function test_rebuild_safely_does_not_throw_when_event_is_missing(): void
    {
        app(CalendarFeedService::class)->rebuildSafely(999999);
        $this->assertSame(0, EventCalendar::query()->count());
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

    public function test_publication_level_does_not_change_description(): void
    {
        $this->insertEvent();
        DB::table('publication')->insert([
            'id' => 1,
            'event' => 1,
            'level' => 4,
            'last_change' => '2026-03-01 00:00:00',
        ]);
        $this->mockDraht(ok: true, data: CalendarFeedService::emptyDrahtData());

        app(CalendarFeedService::class)->rebuild(1);
        $vevent = EventCalendar::query()->where('event', 1)->value('vevent');

        $this->assertStringContainsString('Zeitplan: https://flow.hands-on-technology.org/aachen', $vevent);
        $this->assertStringNotContainsString('Eröffnung', $vevent);
        $this->assertStringNotContainsString('09:30', $vevent);
    }

    public function test_description_lists_program_display_names_in_catalog_order(): void
    {
        DB::table('m_first_program')->insert([
            ['id' => 3, 'name' => 'CHALLENGE', 'display_name' => 'Challenge', 'letter' => 'C', 'ics_postfix' => 'challenge', 'sequence' => 2],
            ['id' => 2, 'name' => 'EXPLORE', 'display_name' => 'Explore', 'letter' => 'E', 'ics_postfix' => 'explore', 'sequence' => 1],
        ]);
        $this->insertEvent();
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => 3],
            ['event' => 1, 'first_program' => 2],
        ]);
        $this->mockDraht(ok: true, data: [
            'programs' => [],
            'address' => null,
            'contact' => [
                ['contact' => 'Ada'],
                ['contact' => 'Grace'],
            ],
            'information' => null,
        ]);

        app(CalendarFeedService::class)->rebuild(1);
        $vevent = EventCalendar::query()->where('event', 1)->value('vevent');

        $this->assertStringContainsString('Programme: Explore\\, Challenge', $vevent);
        $this->assertStringContainsString('Kontakt: Ada', $vevent);
        $this->assertStringNotContainsString('Grace', $vevent);
        $this->assertStringContainsString('Zeitplan: https', $vevent);
    }

    public function test_rebuild_window_builds_in_window_and_drops_outside(): void
    {
        Carbon::setTestNow('2026-08-24');
        $this->mockDraht(ok: true, data: CalendarFeedService::emptyDrahtData());

        $this->insertEvent(['id' => 1, 'slug' => 'aachen', 'date' => '2026-08-24']);
        $this->insertEvent(['id' => 2, 'slug' => 'old', 'date' => '2026-05-01', 'link' => 'https://flow.hands-on-technology.org/old']);
        $this->insertCalendar(2, '2026-05-01', "BEGIN:VEVENT\r\nSUMMARY:OLD\r\nEND:VEVENT");
        $this->insertEvent(['id' => 3, 'slug' => null, 'date' => '2026-08-20', 'link' => null]);

        $result = app(CalendarFeedService::class)->rebuildWindow();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['rebuilt']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, $result['failed']);
        $this->assertNotNull(EventCalendar::query()->where('event', 1)->first());
        $this->assertNull(EventCalendar::query()->where('event', 2)->first());
        $this->assertNull(EventCalendar::query()->where('event', 3)->first());

        Carbon::setTestNow();
    }

    public function test_feed_all_skips_draht_and_applies_window_and_slug(): void
    {
        Carbon::setTestNow('2026-08-24');
        $this->mock(DrahtController::class, function ($mock) {
            $mock->shouldReceive('fetchScheduleData')->never();
        });

        $this->insertEvent(['id' => 1, 'slug' => 'aachen', 'date' => '2026-08-24']);
        $this->insertCalendar(1, '2026-08-24', "BEGIN:VEVENT\r\nUID:in-window\r\nEND:VEVENT");

        $this->insertEvent(['id' => 2, 'slug' => 'koeln', 'date' => '2026-05-25']);
        $this->insertCalendar(2, '2026-05-25', "BEGIN:VEVENT\r\nUID:too-old\r\nEND:VEVENT");

        $this->insertEvent(['id' => 3, 'slug' => null, 'date' => '2026-08-20']);
        $this->insertCalendar(3, '2026-08-20', "BEGIN:VEVENT\r\nUID:no-slug\r\nEND:VEVENT");

        $this->insertEvent(['id' => 4, 'slug' => 'berlin', 'date' => '2026-05-26']);
        $this->insertCalendar(4, '2026-05-26', "BEGIN:VEVENT\r\nUID:edge-90\r\nEND:VEVENT");

        $feed = app(CalendarFeedService::class)->feedAll();

        $this->assertStringContainsString('BEGIN:VCALENDAR', $feed['body']);
        $this->assertStringContainsString('METHOD:PUBLISH', $feed['body']);
        $this->assertStringContainsString('X-WR-CALNAME:HANDS on TECHNOLOGY Veranstaltungen', $feed['body']);
        $this->assertStringContainsString('UID:in-window', $feed['body']);
        $this->assertStringContainsString('UID:edge-90', $feed['body']);
        $this->assertStringNotContainsString('UID:too-old', $feed['body']);
        $this->assertStringNotContainsString('UID:no-slug', $feed['body']);
        $this->assertNotNull($feed['lastModified']);

        Carbon::setTestNow();
    }

    public function test_feed_by_postfix_program_filter_country_empty_unknown_null(): void
    {
        Carbon::setTestNow('2026-08-24');
        $this->mock(DrahtController::class, function ($mock) {
            $mock->shouldReceive('fetchScheduleData')->never();
        });

        DB::table('m_first_program')->insert([
            ['id' => 2, 'name' => 'EXPLORE', 'display_name' => 'Explore', 'letter' => 'E', 'ics_postfix' => 'explore', 'sequence' => 1],
            ['id' => 3, 'name' => 'CHALLENGE', 'display_name' => 'Challenge', 'letter' => 'C', 'ics_postfix' => 'challenge', 'sequence' => 2],
        ]);

        $this->insertEvent(['id' => 1, 'slug' => 'explore-only', 'date' => '2026-08-24']);
        $this->insertCalendar(1, '2026-08-24', "BEGIN:VEVENT\r\nUID:explore-only\r\nEND:VEVENT");
        DB::table('event_program')->insert(['event' => 1, 'first_program' => 2]);

        $this->insertEvent(['id' => 2, 'slug' => 'challenge-only', 'date' => '2026-08-24']);
        $this->insertCalendar(2, '2026-08-24', "BEGIN:VEVENT\r\nUID:challenge-only\r\nEND:VEVENT");
        DB::table('event_program')->insert(['event' => 2, 'first_program' => 3]);

        $this->insertEvent(['id' => 3, 'slug' => 'combined', 'date' => '2026-08-24']);
        $this->insertCalendar(3, '2026-08-24', "BEGIN:VEVENT\r\nUID:combined\r\nEND:VEVENT");
        DB::table('event_program')->insert([
            ['event' => 3, 'first_program' => 2],
            ['event' => 3, 'first_program' => 3],
        ]);

        $explore = app(CalendarFeedService::class)->feedByPostfix('Explore');
        $this->assertNotNull($explore);
        $this->assertStringContainsString('X-WR-CALNAME:HANDS on TECHNOLOGY Veranstaltungen — Explore', $explore['body']);
        $this->assertStringContainsString('UID:explore-only', $explore['body']);
        $this->assertStringContainsString('UID:combined', $explore['body']);
        $this->assertStringNotContainsString('UID:challenge-only', $explore['body']);

        $challenge = app(CalendarFeedService::class)->feedByPostfix('challenge');
        $this->assertNotNull($challenge);
        $this->assertStringContainsString('X-WR-CALNAME:HANDS on TECHNOLOGY Veranstaltungen — Challenge', $challenge['body']);
        $this->assertStringContainsString('UID:challenge-only', $challenge['body']);
        $this->assertStringContainsString('UID:combined', $challenge['body']);
        $this->assertStringNotContainsString('UID:explore-only', $challenge['body']);

        $de = app(CalendarFeedService::class)->feedByPostfix('de');
        $this->assertNotNull($de);
        $this->assertStringContainsString('X-WR-CALNAME:HANDS on TECHNOLOGY Veranstaltungen — DE', $de['body']);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $de['body']);
        $this->assertNull($de['lastModified']);

        $this->assertNull(app(CalendarFeedService::class)->feedByPostfix('unknown'));

        Carbon::setTestNow();
    }

    public function test_list_feeds_and_preview_from_stored_vevent(): void
    {
        Carbon::setTestNow('2026-08-24');
        $this->mock(DrahtController::class, function ($mock) {
            $mock->shouldReceive('fetchScheduleData')->never();
        });

        DB::table('m_first_program')->insert([
            ['id' => 2, 'name' => 'EXPLORE', 'display_name' => 'Explore', 'letter' => 'E', 'ics_postfix' => 'explore', 'sequence' => 1],
            ['id' => 3, 'name' => 'CHALLENGE', 'display_name' => 'Challenge', 'letter' => 'C', 'ics_postfix' => 'challenge', 'sequence' => 2],
        ]);

        $this->insertEvent(['id' => 1, 'slug' => 'aachen', 'date' => '2026-08-24']);
        $this->insertCalendar(
            1,
            '2026-08-24',
            "BEGIN:VEVENT\r\nUID:event-1@flow.hands-on-technology.org\r\nSEQUENCE:4\r\nDTSTART;VALUE=DATE:20260824\r\nDTEND;VALUE=DATE:20260825\r\nSUMMARY:Aachen\r\nDESCRIPTION:Kontakt\\nAda\r\nLOCATION:Halle\r\nURL:https://example.org/aachen\r\nEND:VEVENT",
            4
        );

        $feeds = app(CalendarFeedService::class)->listFeeds('https://flow.hands-on-technology.org');
        $keys = array_column($feeds, 'key');
        $this->assertSame('all', $keys[0]);
        $this->assertContains('explore', $keys);
        $this->assertContains('challenge', $keys);
        $this->assertContains('de', $keys);
        $this->assertSame(
            'https://flow.hands-on-technology.org/api/calendar.ics',
            $feeds[0]['url']
        );

        $preview = app(CalendarFeedService::class)->previewFeed('all', 'https://flow.hands-on-technology.org');
        $this->assertNotNull($preview);
        $this->assertSame('all', $preview['key']);
        $this->assertCount(1, $preview['events']);
        $this->assertSame(1, $preview['events'][0]['event_id']);
        $this->assertSame('Aachen', $preview['events'][0]['summary']);
        $this->assertSame('2026-08-24', $preview['events'][0]['dtstart']);
        $this->assertSame('2026-08-25', $preview['events'][0]['dtend']);
        $this->assertSame('Halle', $preview['events'][0]['location']);
        $this->assertSame("Kontakt\nAda", $preview['events'][0]['description']);
        $this->assertSame(4, $preview['events'][0]['sequence']);
        $this->assertNotNull($preview['events'][0]['built_at']);
        $this->assertStringContainsString('BEGIN:VEVENT', $preview['events'][0]['vevent']);

        $de = app(CalendarFeedService::class)->previewFeed('de', 'https://flow.hands-on-technology.org');
        $this->assertSame([], $de['events']);
        $this->assertNull(app(CalendarFeedService::class)->previewFeed('unknown', 'https://example.org'));

        Carbon::setTestNow();
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

    private function insertCalendar(int $eventId, string $date, string $vevent, int $sequence = 0): void
    {
        DB::table('event_calendar')->insert([
            'event' => $eventId,
            'date' => $date,
            'uid' => 'event-'.$eventId.'@flow.hands-on-technology.org',
            'sequence' => $sequence,
            'vevent' => $vevent,
            'built_at' => '2026-08-01 12:00:00',
        ]);
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
            $table->string('ics_postfix')->nullable();
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
            $table->unsignedInteger('id')->autoIncrement();
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
