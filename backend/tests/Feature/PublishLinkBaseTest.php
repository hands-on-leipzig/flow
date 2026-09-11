<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PublishController;
use App\Models\Event;
use App\Services\CalendarFeedService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublishLinkBaseTest extends TestCase
{
    private const SEASON = 3;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Publish link tests require sqlite.');
        }

        config(['app.public_url' => 'https://handson.tools']);

        $this->mock(CalendarFeedService::class, function ($mock) {
            $mock->shouldReceive('tryRebuildOne')->zeroOrMoreTimes()->andReturn(CalendarFeedService::RESULT_SKIPPED);
            $mock->shouldReceive('markStale')->zeroOrMoreTimes();
        });

        $this->createSchema();
    }

    public function test_stored_link_is_returned_untouched_while_it_matches_the_public_base(): void
    {
        $this->insertEvent('aachen', 'https://handson.tools/aachen', 'stored-qr');

        $payload = app(PublishController::class)->linkAndQRcode(1)->getData(true);

        $this->assertSame('https://handson.tools/aachen', $payload['link']);
        $this->assertSame('aachen', $payload['slug']);
        $this->assertSame('data:image/png;base64,stored-qr', $payload['qrcode']);
    }

    public function test_link_and_qr_code_are_rebuilt_after_the_public_base_changed(): void
    {
        $this->insertEvent('aachen', 'https://flow.hands-on-technology.org/aachen', 'stored-qr');

        $payload = app(PublishController::class)->linkAndQRcode(1)->getData(true);

        $this->assertSame('https://handson.tools/aachen', $payload['link']);
        $this->assertSame('aachen', $payload['slug']);
        $this->assertNotSame('data:image/png;base64,stored-qr', $payload['qrcode']);

        $stored = DB::table('event')->where('id', 1)->first();
        $this->assertSame('https://handson.tools/aachen', $stored->link);
        $this->assertNotSame('stored-qr', $stored->qrcode);
    }

    private function insertEvent(string $slug, string $link, string $qrcode): void
    {
        Event::query()->create([
            'id' => 1,
            'name' => 'Aachen',
            'slug' => $slug,
            'regional_partner' => 1,
            'level' => 1,
            'season' => self::SEASON,
            'date' => '2026-08-24',
            'days' => 1,
            'link' => $link,
            'qrcode' => $qrcode,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('event_slug_history');
        Schema::dropIfExists('event_program');
        Schema::dropIfExists('event');
        Schema::dropIfExists('m_first_program');
        Schema::dropIfExists('m_season');

        Schema::create('m_season', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('year');
        });

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('letter')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('color_hex')->nullable();
            $table->string('logo_stem')->nullable();
            $table->string('logo_white')->nullable();
        });

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('slug_manual')->default(false);
            $table->unsignedInteger('regional_partner')->default(1);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('season')->default(1);
            $table->date('date');
            $table->unsignedTinyInteger('days')->default(1);
            $table->string('link')->nullable();
            $table->text('qrcode')->nullable();
            $table->boolean('calendar_stale')->default(true);
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('draht_id')->nullable();
        });

        Schema::create('event_slug_history', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('season');
            $table->string('slug');
            $table->timestamp('replaced_at')->nullable();

            $table->unique(['slug', 'season']);
        });

        DB::table('m_season')->insert([
            ['id' => self::SEASON, 'name' => 'Saison 2026', 'year' => 2026],
        ]);
    }
}
