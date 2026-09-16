<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PublicEventLinkController;
use App\Models\Event;
use App\Services\EventSlugService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The public link list JOIN reads: every event of a season that owns a slug, addressed
 * with the DRAHT ids of its programs.
 */
class PublicEventLinkApiTest extends TestCase
{
    private const CURRENT_SEASON = 3;

    private const PAST_SEASON = 2;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Public event link tests require sqlite.');
        }

        config(['app.public_url' => 'https://handson.tools']);

        $this->createSchema();
    }

    public function test_lists_the_current_season_with_one_entry_per_event(): void
    {
        $aachen = $this->insertEvent(['id' => 1, 'name' => 'Aachen']);
        // Explore and Challenge are two events in DRAHT but one link here.
        $this->insertProgram(1, 1, 2, 1001);
        $this->insertProgram(2, 1, 3, 1002);
        app(EventSlugService::class)->ensure($aachen->refresh());

        $payload = $this->index()->getData(true);

        $this->assertSame(1, $payload['meta']['count']);
        $this->assertSame(self::CURRENT_SEASON, $payload['meta']['season']['id']);
        $this->assertTrue($payload['meta']['season']['current']);
        $this->assertSame('https://handson.tools', $payload['meta']['base']);

        $this->assertSame([[
            'event_id' => 1,
            'name' => 'Aachen',
            'date' => '2026-08-24',
            'slug' => 'aachen',
            'url' => 'https://handson.tools/aachen',
            'draht_ids' => [1001, 1002],
        ]], $payload['data']);
    }

    public function test_events_without_slug_or_without_draht_id_are_left_out(): void
    {
        $withSlug = $this->insertEvent(['id' => 1, 'name' => 'Aachen']);
        $this->insertProgram(1, 1, 2, 1001);
        app(EventSlugService::class)->ensure($withSlug->refresh());

        // A slug but no DRAHT id: no caller could address this one.
        $unreachable = $this->insertEvent(['id' => 2, 'name' => 'Koeln']);
        $this->insertProgram(2, 2, 2, null);
        app(EventSlugService::class)->ensure($unreachable->refresh());

        // A DRAHT id but no slug yet.
        $this->insertEvent(['id' => 3, 'name' => 'Bonn']);
        $this->insertProgram(3, 3, 2, 1003);

        $payload = $this->index()->getData(true);

        $this->assertSame(1, $payload['meta']['count']);
        $this->assertSame([1001], $payload['data'][0]['draht_ids']);
    }

    public function test_a_past_season_is_addressed_by_year_and_keeps_it_in_the_url(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'season' => self::PAST_SEASON]);
        $this->insertProgram(1, 1, 2, 1001);
        app(EventSlugService::class)->ensure($event->refresh());

        $payload = $this->index(['year' => 2025])->getData(true);

        $this->assertFalse($payload['meta']['season']['current']);
        $this->assertSame('https://handson.tools/2025/aachen', $payload['data'][0]['url']);
    }

    public function test_unknown_season_and_year_are_reported_as_not_found(): void
    {
        $this->assertSame(404, $this->index(['season' => 99])->getStatusCode());
        $this->assertSame(404, $this->index(['year' => 2030])->getStatusCode());
    }

    public function test_the_answer_may_be_cached_for_a_few_minutes(): void
    {
        $this->assertSame('max-age=300, public', $this->index()->headers->get('Cache-Control'));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function index(array $query = [])
    {
        return app(PublicEventLinkController::class)->index(Request::create('/', 'GET', $query));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertEvent(array $attributes): Event
    {
        return Event::query()->create(array_merge([
            'name' => 'Event',
            'regional_partner' => 1,
            'level' => 1,
            'season' => self::CURRENT_SEASON,
            'date' => '2026-08-24',
            'days' => 1,
        ], $attributes))->refresh();
    }

    private function insertProgram(int $id, int $event, int $firstProgram, ?int $drahtId): void
    {
        DB::table('event_program')->insert([
            'id' => $id,
            'event' => $event,
            'first_program' => $firstProgram,
            'draht_id' => $drahtId,
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
            ['id' => self::PAST_SEASON, 'name' => 'Saison 2025', 'year' => 2025],
            ['id' => self::CURRENT_SEASON, 'name' => 'Saison 2026', 'year' => 2026],
        ]);

        DB::table('m_first_program')->insert([
            ['id' => 2, 'name' => 'EXPLORE', 'display_name' => 'Explore', 'letter' => 'E', 'sequence' => 1],
            ['id' => 3, 'name' => 'CHALLENGE', 'display_name' => 'Challenge', 'letter' => 'C', 'sequence' => 2],
        ]);
    }
}
