<?php

namespace Tests\Feature;

use App\Services\StaffingSyncService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicVolunteerOpeningsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Public volunteer openings tests require sqlite.');
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

    public function test_lists_upcoming_events_and_marks_open_roles(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
            2 => [],
        ]);

        $response = $this->getJson('/api/public/volunteer-openings');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([1, 4, 5, 2], $ids);
        $response->assertJsonPath('data.0.name', 'Leipzig');
        $response->assertJsonPath('data.0.seeking', true);
        $this->assertStringContainsString('Schiedsrichter', json_encode($response->json('data.0.helper_search')));
    }

    public function test_includes_events_without_open_roles_as_not_seeking(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
            2 => [],
        ]);

        $response = $this->getJson('/api/public/volunteer-openings');

        $response->assertOk();
        $dresden = collect($response->json('data'))->firstWhere('id', 2);
        $this->assertNotNull($dresden);
        $this->assertFalse($dresden['seeking']);
        $this->assertNotNull($dresden['helper_search']);
    }

    public function test_omits_past_events_and_hides_needs_at_level_four(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
            3 => [[
                'key' => 'local',
                'critical' => [['role_id' => 2, 'label' => 'Laufhilfe', 'sequence' => 1]],
                'recommended' => [],
            ]],
            4 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 3, 'label' => 'Zeitnahme', 'sequence' => 1]],
                'recommended' => [],
            ]],
        ]);

        $response = $this->getJson('/api/public/volunteer-openings');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([1, 4, 5, 2], $ids);
        $hidden = collect($response->json('data'))->firstWhere('id', 4);
        $this->assertFalse($hidden['seeking']);
        $this->assertNull($hidden['helper_search']);
    }

    public function test_includes_events_that_did_not_enable_helper_search(): void
    {
        $this->mockOpenPositions([
            1 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 1, 'label' => 'Schiedsrichter', 'sequence' => 1]],
                'recommended' => [],
            ]],
            5 => [[
                'key' => 'cross',
                'critical' => [['role_id' => 9, 'label' => 'Technik', 'sequence' => 1]],
                'recommended' => [],
            ]],
        ]);

        $response = $this->getJson('/api/public/volunteer-openings');

        $response->assertOk();
        $noSearch = collect($response->json('data'))->firstWhere('id', 5);
        $this->assertNotNull($noSearch);
        $this->assertFalse($noSearch['seeking']);
        $this->assertNull($noSearch['helper_search']);
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

        $this->insertEvent(1, [
            'name' => 'Leipzig',
            'slug' => 'leipzig',
            'date' => '2026-11-15',
            'public_helper_search' => true,
        ]);
        $this->insertEvent(2, [
            'name' => 'Dresden komplett',
            'slug' => 'dresden',
            'date' => '2026-12-01',
            'public_helper_search' => true,
        ]);
        $this->insertEvent(3, [
            'name' => 'Vergangen',
            'slug' => 'past',
            'date' => '2026-05-01',
            'public_helper_search' => true,
        ]);
        $this->insertEvent(4, [
            'name' => 'Alles sichtbar',
            'slug' => 'hidden-search',
            'date' => '2026-11-20',
            'public_helper_search' => true,
        ]);
        $this->insertEvent(5, [
            'name' => 'Ohne Suche',
            'slug' => 'no-search',
            'date' => '2026-11-22',
            'public_helper_search' => false,
        ]);

        DB::table('publication')->insert([
            ['id' => 1, 'event' => 1, 'level' => 1, 'last_change' => '2026-09-01 10:00:00'],
            ['id' => 2, 'event' => 4, 'level' => 4, 'last_change' => '2026-09-01 10:00:00'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertEvent(int $id, array $overrides): void
    {
        DB::table('event')->insert(array_merge([
            'id' => $id,
            'name' => 'Event '.$id,
            'slug' => 'event-'.$id,
            'regional_partner' => 1,
            'level' => 1,
            'season' => 1,
            'date' => '2026-11-01',
            'days' => 1,
            'link' => null,
            'public_helper_search' => false,
        ], $overrides));
    }

    private function truncateData(): void
    {
        foreach (['publication', 'event_program', 'event', 'regional_partner', 'm_first_program', 'm_season'] as $table) {
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
                $table->boolean('public_helper_search')->default(false);
            });
        }
        if (! Schema::hasTable('publication')) {
            Schema::create('publication', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedTinyInteger('level');
                $table->timestamp('last_change')->nullable();
            });
        }
    }
}
