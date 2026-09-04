<?php

namespace Tests\Unit;

use App\Services\PlanGeneratorService;
use App\Services\SeasonPlanBulkService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SeasonPlanBulkServiceTest extends TestCase
{
    private PlanGeneratorService $generator;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('SeasonPlanBulkService tests require sqlite.');
        }

        $this->createSchema();
        $this->generator = Mockery::mock(PlanGeneratorService::class);
        $this->app->instance(PlanGeneratorService::class, $this->generator);
        config(['staffing.sync_after_generate' => false]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_summary_uses_highest_season_id_and_counts_locked(): void
    {
        $this->seedSeasonsAndPlans();

        $summary = app(SeasonPlanBulkService::class)->summary();

        $this->assertSame(2, $summary['season_id']);
        $this->assertSame('Current', $summary['season_name']);
        $this->assertSame(3, $summary['plans']);
        $this->assertSame(1, $summary['locked']);
    }

    public function test_empty_wipes_activities_and_matches_only_for_current_season(): void
    {
        $this->seedSeasonsAndPlans();
        DB::table('activity_group')->insert([
            ['plan' => 1],
            ['plan' => 2],
            ['plan' => 3],
            ['plan' => 10],
        ]);
        DB::table('match')->insert([
            ['plan' => 1, 'round' => 1, 'match_no' => 1],
            ['plan' => 10, 'round' => 1, 'match_no' => 1],
        ]);
        DB::table('plan_param_value')->insert([
            ['plan' => 1, 'parameter' => 1, 'set_value' => 'keep'],
        ]);

        $result = app(SeasonPlanBulkService::class)->empty();

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['plans']);
        $this->assertSame(1, $result['locked']);
        $this->assertSame(3, $result['activity_groups_deleted']);
        $this->assertSame(1, $result['matches_deleted']);
        $this->assertSame(0, DB::table('activity_group')->whereIn('plan', [1, 2, 3])->count());
        $this->assertSame(1, DB::table('activity_group')->where('plan', 10)->count());
        $this->assertSame(0, DB::table('match')->where('plan', 1)->count());
        $this->assertSame(1, DB::table('match')->where('plan', 10)->count());
        $this->assertSame(3, DB::table('plan')->whereIn('id', [1, 2, 3])->count());
        $this->assertSame('keep', DB::table('plan_param_value')->where('plan', 1)->value('set_value'));
        $this->assertTrue((bool) DB::table('plan')->where('id', 3)->value('locked'));
        $this->assertNull(DB::table('plan')->where('id', 1)->value('generator_status'));
    }

    public function test_regenerate_runs_serially_includes_locked_skips_unsupported(): void
    {
        $this->seedSeasonsAndPlans();

        $this->generator->shouldReceive('isSupported')->once()->with(1)->andReturn(['supported' => true]);
        $this->generator->shouldReceive('isSupported')->once()->with(2)->andReturn(['supported' => false]);
        $this->generator->shouldReceive('isSupported')->once()->with(3)->andReturn(['supported' => true]);
        $this->generator->shouldReceive('prepare')->once()->with(1, 'direct', 99);
        $this->generator->shouldReceive('prepare')->once()->with(3, 'direct', 99);
        $this->generator->shouldReceive('run')->once()->with(1);
        $this->generator->shouldReceive('run')->once()->with(3);
        $this->generator->shouldNotReceive('dispatchJob');

        $result = app(SeasonPlanBulkService::class)->regenerate(99);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['regenerated']);
        $this->assertSame(1, $result['skipped_unsupported']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $result['locked']);
    }

    public function test_regenerate_continues_after_a_failure(): void
    {
        $this->seedSeasonsAndPlans();

        $this->generator->shouldReceive('isSupported')->andReturn(['supported' => true]);
        $this->generator->shouldReceive('prepare');
        $this->generator->shouldReceive('run')->with(1)->andThrow(new RuntimeException('boom'));
        $this->generator->shouldReceive('run')->with(2);
        $this->generator->shouldReceive('run')->with(3);

        $result = app(SeasonPlanBulkService::class)->regenerate();

        $this->assertSame(2, $result['regenerated']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['errors'][0]['plan_id']);
    }

    public function test_summary_throws_when_no_season(): void
    {
        $this->expectException(RuntimeException::class);
        app(SeasonPlanBulkService::class)->summary();
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('m_season', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('year')->nullable();
        });

        Schema::create('event', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('season');
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event');
            $table->string('generator_status')->nullable();
            $table->timestamp('last_change')->nullable();
            $table->boolean('locked')->default(false);
        });

        Schema::create('activity_group', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('plan');
        });

        Schema::create('match', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('round')->nullable();
            $table->unsignedInteger('match_no')->nullable();
        });

        Schema::create('plan_param_value', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('parameter');
            $table->string('set_value')->nullable();
        });
    }

    private function seedSeasonsAndPlans(): void
    {
        DB::table('m_season')->insert([
            ['id' => 1, 'name' => 'Old', 'year' => 2024],
            ['id' => 2, 'name' => 'Current', 'year' => 2025],
        ]);
        DB::table('event')->insert([
            ['id' => 1, 'season' => 2],
            ['id' => 2, 'season' => 2],
            ['id' => 3, 'season' => 2],
            ['id' => 10, 'season' => 1],
        ]);
        DB::table('plan')->insert([
            ['id' => 1, 'event' => 1, 'locked' => 0, 'generator_status' => 'done'],
            ['id' => 2, 'event' => 2, 'locked' => 0, 'generator_status' => 'done'],
            ['id' => 3, 'event' => 3, 'locked' => 1, 'generator_status' => 'done'],
            ['id' => 10, 'event' => 10, 'locked' => 0, 'generator_status' => 'done'],
        ]);
    }
}
