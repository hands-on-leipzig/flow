<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Models\EventStaffingRole;
use App\Services\EventWorkspaceEnsureService;
use App\Services\PlanGeneratorService;
use App\Services\StaffingSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class EventWorkspaceEnsureServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('EventWorkspaceEnsureService tests require sqlite.');
        }

        $this->createSchema();
        config(['staffing.sync_after_generate' => false]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_generates_when_no_activities_then_syncs_staffing(): void
    {
        $this->seedChallengeEvent(lanes: 2, locked: false, withActivities: false);

        $generator = Mockery::mock(PlanGeneratorService::class);
        $generator->shouldReceive('isSupported')->once()->with(1)->andReturn(['supported' => true]);
        $generator->shouldReceive('prepare')->once()->with(1, 'direct', null);
        $generator->shouldReceive('run')->once()->with(1)->andReturnUsing(function () {
            DB::table('activity_group')->insert(['plan' => 1]);
        });
        $this->app->instance(PlanGeneratorService::class, $generator);

        $result = app(EventWorkspaceEnsureService::class)->ensure(1);

        $this->assertTrue($result['generated']);
        $this->assertTrue($result['staffing_synced']);
        $this->assertTrue($result['existing']);
        $this->assertFalse($result['locked']);
        $this->assertNull($result['generate_skipped']);
        $this->assertGreaterThan(0, EventStaffingRole::query()->where('event', 1)->count());
    }

    public function test_syncs_staffing_only_when_activities_exist(): void
    {
        $this->seedChallengeEvent(lanes: 2, locked: false, withActivities: true);

        $generator = Mockery::mock(PlanGeneratorService::class);
        $generator->shouldNotReceive('isSupported');
        $generator->shouldNotReceive('prepare');
        $generator->shouldNotReceive('run');
        $this->app->instance(PlanGeneratorService::class, $generator);

        $result = app(EventWorkspaceEnsureService::class)->ensure(1);

        $this->assertFalse($result['generated']);
        $this->assertTrue($result['staffing_synced']);
        $this->assertTrue($result['existing']);
        $this->assertNull($result['generate_skipped']);
        $this->assertGreaterThan(0, EventStaffingRole::query()->where('event', 1)->count());
    }

    public function test_locked_skips_generate_still_syncs_staffing(): void
    {
        $this->seedChallengeEvent(lanes: 1, locked: true, withActivities: false);

        $generator = Mockery::mock(PlanGeneratorService::class);
        $generator->shouldNotReceive('prepare');
        $generator->shouldNotReceive('run');
        $this->app->instance(PlanGeneratorService::class, $generator);

        $result = app(EventWorkspaceEnsureService::class)->ensure(1);

        $this->assertFalse($result['generated']);
        $this->assertTrue($result['staffing_synced']);
        $this->assertFalse($result['existing']);
        $this->assertTrue($result['locked']);
        $this->assertSame('locked', $result['generate_skipped']);
        $this->assertGreaterThan(0, EventStaffingRole::query()->where('event', 1)->count());
    }

    public function test_noop_when_activities_and_staffing_exist(): void
    {
        $this->seedChallengeEvent(lanes: 1, locked: false, withActivities: true);
        app(StaffingSyncService::class)->syncForEvent(1);
        $this->assertGreaterThan(0, EventStaffingRole::query()->where('event', 1)->count());

        $generator = Mockery::mock(PlanGeneratorService::class);
        $generator->shouldNotReceive('prepare');
        $generator->shouldNotReceive('run');
        $this->app->instance(PlanGeneratorService::class, $generator);

        $result = app(EventWorkspaceEnsureService::class)->ensure(1);

        $this->assertFalse($result['generated']);
        $this->assertFalse($result['staffing_synced']);
        $this->assertTrue($result['existing']);
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('event', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('regional_partner')->default(1);
            $table->date('date')->nullable();
            $table->integer('level')->default(1);
            $table->integer('days')->default(1);
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event');
            $table->string('name')->nullable();
            $table->timestamp('created')->nullable();
            $table->timestamp('last_change')->nullable();
            $table->boolean('locked')->default(false);
        });

        Schema::create('activity_group', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan');
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event');
            $table->unsignedInteger('first_program');
        });

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->id();
            $table->integer('sequence');
        });

        Schema::create('m_parameter', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('integer');
            $table->string('value')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->integer('level')->default(1);
            $table->string('context')->default('expert');
            $table->string('min')->nullable();
            $table->string('max')->nullable();
            $table->string('step')->nullable();
        });

        Schema::create('plan_param_value', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan');
            $table->unsignedBigInteger('parameter');
            $table->string('set_value')->nullable();
        });

        Schema::create('m_role', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('sequence')->default(0);
            $table->unsignedInteger('first_program')->nullable();
            $table->string('differentiation_type')->nullable();
            $table->text('differentiation_source')->nullable();
            $table->string('differentiation_parameter')->nullable();
            $table->boolean('staffable')->default(false);
        });

        Schema::create('m_staffing_rule', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('m_role')->unique();
            $table->unsignedSmallInteger('min');
            $table->unsignedSmallInteger('best');
            $table->unsignedSmallInteger('max');
            $table->text('ui_description')->nullable();
        });

        Schema::create('event_staffing_role', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event');
            $table->unsignedInteger('m_role')->nullable();
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('min');
            $table->unsignedSmallInteger('best');
            $table->unsignedSmallInteger('max');
            $table->text('ui_description')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
        });

        Schema::create('event_staffing_group', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event_staffing_role');
            $table->unsignedSmallInteger('group_index')->default(1);
            $table->boolean('surplus')->default(false);
        });

        Schema::create('event_staffing_assignment', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event_staffing_group');
            $table->unsignedInteger('volunteer_person');
            $table->timestamp('created_at')->nullable();
        });
    }

    private function seedChallengeEvent(int $lanes, bool $locked, bool $withActivities): void
    {
        DB::table('m_first_program')->insert([
            ['id' => FirstProgram::EXPLORE->value, 'sequence' => 1],
            ['id' => FirstProgram::CHALLENGE->value, 'sequence' => 2],
            ['id' => FirstProgram::FUTURE_8->value, 'sequence' => 5],
        ]);

        DB::table('m_parameter')->insert([
            ['id' => 1, 'name' => 'g_plan', 'value' => '1', 'first_program' => null],
            ['id' => 22, 'name' => 'c_teams', 'value' => '8', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 122, 'name' => 'c_mode', 'value' => '0', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 50, 'name' => 'j_lanes', 'value' => '0', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 6, 'name' => 'e_teams', 'value' => '0', 'first_program' => FirstProgram::EXPLORE->value],
            ['id' => 7, 'name' => 'e_mode', 'value' => '0', 'first_program' => FirstProgram::EXPLORE->value],
            ['id' => 200, 'name' => 'f8_teams', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 201, 'name' => 'f8_mode', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
        ]);

        DB::table('event')->insert(['id' => 1, 'regional_partner' => 1, 'date' => '2026-01-01']);
        DB::table('plan')->insert([
            'id' => 1,
            'event' => 1,
            'name' => 'Zeitplan',
            'last_change' => now(),
            'locked' => $locked ? 1 : 0,
        ]);
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => FirstProgram::CHALLENGE->value],
        ]);

        DB::table('plan_param_value')->insert([
            ['plan' => 1, 'parameter' => 122, 'set_value' => '1'],
            ['plan' => 1, 'parameter' => 22, 'set_value' => '8'],
            ['plan' => 1, 'parameter' => 50, 'set_value' => (string) $lanes],
        ]);

        DB::table('m_role')->insert([
            'id' => 4,
            'name' => 'Jury',
            'sequence' => 3,
            'first_program' => FirstProgram::CHALLENGE->value,
            'differentiation_type' => 'number',
            'differentiation_source' => 'select set_value from plan_param_value where parameter=50 and plan=[plan]',
            'differentiation_parameter' => 'lane',
            'staffable' => 1,
        ]);

        DB::table('m_staffing_rule')->insert([
            'id' => 1,
            'm_role' => 4,
            'min' => 2,
            'best' => 3,
            'max' => 5,
            'ui_description' => 'Jury help text',
        ]);

        if ($withActivities) {
            DB::table('activity_group')->insert(['plan' => 1]);
        }
    }
}
