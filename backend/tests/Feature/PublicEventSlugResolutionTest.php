<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EventController;
use App\Models\Event;
use App\Services\EventSlugService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicEventSlugResolutionTest extends TestCase
{
    private const CURRENT_SEASON = 3;

    private const PAST_SEASON = 2;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Public slug resolution tests require sqlite.');
        }

        config(['app.public_url' => 'https://handson.tools']);

        $this->createSchema();
    }

    public function test_current_season_slug_resolves_without_a_year(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Aachen']);
        app(EventSlugService::class)->ensure($event);

        $response = $this->resolve('aachen');

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame(1, $payload['id']);
        $this->assertArrayNotHasKey('redirect_to', $payload);
    }

    public function test_past_season_slug_needs_its_year(): void
    {
        $past = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'season' => self::PAST_SEASON]);
        app(EventSlugService::class)->ensure($past);

        // Without a year only the current season answers, and there is no event there.
        $this->assertSame(404, $this->resolve('aachen')->getStatusCode());

        $withYear = $this->resolve('aachen', 2025);
        $this->assertSame(200, $withYear->getStatusCode());
        $this->assertSame(1, $withYear->getData(true)['id']);
    }

    public function test_old_slug_resolves_and_reports_the_current_path(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Aachen']);
        $slugs = app(EventSlugService::class);
        $slugs->ensure($event);

        $event->refresh()->update(['name' => 'Duesseldorf']);
        $slugs->regenerate($event->refresh());

        $payload = $this->resolve('aachen')->getData(true);

        $this->assertSame(1, $payload['id']);
        $this->assertSame('duesseldorf', $payload['slug']);
        $this->assertSame('/duesseldorf', $payload['redirect_to']);
    }

    public function test_old_slug_of_a_past_season_keeps_the_year_in_the_current_path(): void
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Aachen', 'season' => self::PAST_SEASON]);
        $slugs = app(EventSlugService::class);
        $slugs->ensure($event);

        $event->refresh()->update(['name' => 'Duesseldorf']);
        $slugs->regenerate($event->refresh());

        $payload = $this->resolve('aachen', 2025)->getData(true);

        $this->assertSame('/2025/duesseldorf', $payload['redirect_to']);
    }

    public function test_unknown_slug_is_not_found(): void
    {
        $this->assertSame(404, $this->resolve('gibtsnicht')->getStatusCode());
    }

    private function resolve(string $slug, ?int $year = null)
    {
        $query = $year === null ? [] : ['year' => $year];

        return app(EventController::class)->getEventBySlug(
            Request::create('/', 'GET', $query),
            $slug
        );
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

    private function createSchema(): void
    {
        Schema::dropIfExists('event_slug_history');
        Schema::dropIfExists('event_program');
        Schema::dropIfExists('event');
        Schema::dropIfExists('regional_partner');
        Schema::dropIfExists('m_first_program');
        Schema::dropIfExists('m_level');
        Schema::dropIfExists('m_season');

        Schema::create('m_season', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('year');
        });

        Schema::create('m_level', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
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

        DB::table('m_level')->insert([
            ['id' => 1, 'name' => 'RegionalWettbewerb'],
        ]);

        DB::table('regional_partner')->insert([
            ['id' => 1, 'name' => 'RP Aachen', 'region' => 'West'],
        ]);
    }
}
