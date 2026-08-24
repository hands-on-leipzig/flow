<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DrahtController;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CalendarFeedAllTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Calendar feed tests require sqlite.');
        }

        config([
            'app.env' => 'production',
            'app.url' => 'https://flow.hands-on-technology.org',
        ]);
        Carbon::setTestNow('2026-08-24');
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_public_calendar_ics_headers_and_body(): void
    {
        $this->mock(DrahtController::class, function ($mock) {
            $mock->shouldReceive('fetchScheduleData')->never();
        });

        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Aachen',
            'slug' => 'aachen',
            'regional_partner' => 1,
            'level' => 1,
            'season' => 1,
            'date' => '2026-08-24',
            'days' => 1,
            'link' => 'https://flow.hands-on-technology.org/aachen',
        ]);
        DB::table('event_calendar')->insert([
            'event' => 1,
            'date' => '2026-08-24',
            'uid' => 'event-1@flow.hands-on-technology.org',
            'sequence' => 1,
            'vevent' => "BEGIN:VEVENT\r\nUID:event-1@flow.hands-on-technology.org\r\nSUMMARY:Aachen\r\nEND:VEVENT",
            'built_at' => '2026-08-20 10:00:00',
        ]);

        $response = $this->get('/api/calendar.ics');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertNull($response->headers->get('Content-Disposition'));
        $response->assertSee('BEGIN:VCALENDAR', false);
        $response->assertSee('SUMMARY:Aachen', false);
        $this->assertNotEmpty($response->headers->get('ETag'));
        $this->assertNotEmpty($response->headers->get('Last-Modified'));
    }

    public function test_not_modified_when_etag_matches(): void
    {
        $this->mock(DrahtController::class, function ($mock) {
            $mock->shouldReceive('fetchScheduleData')->never();
        });

        $first = $this->get('/api/calendar.ics');
        $etag = $first->headers->get('ETag');

        $this->get('/api/calendar.ics', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('event_calendar');
        Schema::dropIfExists('event');

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
