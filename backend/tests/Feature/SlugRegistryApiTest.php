<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SlugRegistryController;
use App\Models\Event;
use App\Services\EventSlugService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SlugRegistryApiTest extends TestCase
{
    private const CURRENT_SEASON = 3;

    private const PAST_SEASON = 2;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Slug registry API tests require sqlite.');
        }

        config(['app.public_url' => 'https://handson.tools']);

        $this->createSchema();
    }

    public function test_lists_the_current_season_with_slug_state_per_event(): void
    {
        $named = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $this->insertProgram(1, 1, 2, 1001);
        app(EventSlugService::class)->ensure($named->refresh());

        // Another partner: same-partner events would pull program suffixes into the
        // suggestion, which is its own case.
        $this->insertEvent(['id' => 2, 'name' => 'Koeln', 'level' => 1, 'regional_partner' => 9]);

        $response = $this->index();
        $this->assertSame(200, $response->getStatusCode());

        $payload = $response->getData(true);
        $this->assertSame(self::CURRENT_SEASON, $payload['season']['id']);
        $this->assertTrue($payload['season']['current']);
        $this->assertSame('https://handson.tools', $payload['base']);

        [$first, $second] = $payload['events'];

        $this->assertSame('aachen', $first['slug']);
        $this->assertSame('https://handson.tools/aachen', $first['url']);
        $this->assertSame([1001], $first['draht_ids']);
        $this->assertSame(['EXPLORE'], $first['programs']);
        $this->assertSame('RP Aachen', $first['regional_partner']);
        $this->assertTrue($first['follows_suggestion']);
        $this->assertFalse($first['manual']);

        $this->assertNull($second['slug']);
        $this->assertSame('koeln', $second['suggestion']);

        $this->assertSame(2, $payload['summary']['total']);
        $this->assertSame(1, $payload['summary']['without_slug']);
        $this->assertSame(0, $payload['summary']['manual']);
    }

    public function test_reports_a_renamed_event_with_its_old_slug_and_stale_link(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'level' => 1]);
        $slugs = app(EventSlugService::class);
        $slugs->ensure($event);

        // A stored link is what QR codes were printed from, so it stays behind a rename.
        DB::table('event')->where('id', 1)->update(['link' => 'https://handson.tools/aachen']);

        $event->refresh()->update(['name' => 'Duesseldorf']);
        $slugs->regenerate($event->refresh());

        $row = $this->index()->getData(true)['events'][0];

        $this->assertSame('duesseldorf', $row['slug']);
        $this->assertFalse($row['link_current']);
        $this->assertSame('https://handson.tools/aachen', $row['stored_link']);
        $this->assertSame(
            [['slug' => 'aachen', 'url' => 'https://handson.tools/aachen']],
            array_map(
                fn (array $entry) => ['slug' => $entry['slug'], 'url' => $entry['url']],
                $row['history']
            )
        );
    }

    public function test_past_season_urls_carry_the_year(): void
    {
        $event = $this->insertEvent([
            'id' => 1,
            'name' => 'Aachen',
            'level' => 1,
            'season' => self::PAST_SEASON,
        ]);
        app(EventSlugService::class)->ensure($event);

        $payload = $this->index(self::PAST_SEASON)->getData(true);

        $this->assertFalse($payload['season']['current']);
        $this->assertSame('https://handson.tools/2025/aachen', $payload['events'][0]['url']);
    }

    public function test_unknown_season_is_reported_as_not_found(): void
    {
        $this->assertSame(404, $this->index(99)->getStatusCode());
    }

    private function index(?int $seasonId = null)
    {
        $query = $seasonId === null ? [] : ['season' => $seasonId];

        return app(SlugRegistryController::class)->index(Request::create('/', 'GET', $query));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertEvent(array $attributes): Event
    {
        $event = Event::query()->create(array_merge([
            'name' => 'Event',
            'regional_partner' => 1,
            'level' => 1,
            'season' => self::CURRENT_SEASON,
            'date' => '2026-08-24',
            'days' => 1,
        ], $attributes));

        return $event->refresh();
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
        Schema::dropIfExists('regional_partner');
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

        Schema::create('regional_partner', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('region')->nullable();
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

        DB::table('regional_partner')->insert([
            ['id' => 1, 'name' => 'RP Aachen', 'region' => 'West'],
        ]);
    }
}
