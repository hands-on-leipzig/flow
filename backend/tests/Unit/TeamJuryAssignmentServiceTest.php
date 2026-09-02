<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\TeamController;
use App\Models\Event;
use App\Services\TeamJuryAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeamJuryAssignmentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('TeamJuryAssignmentService tests require sqlite.');
        }

        $this->createSchema();
    }

    public function test_assignments_for_program_maps_plan_slot_to_lane(): void
    {
        $this->seedPlanWithActivities();

        $service = app(TeamJuryAssignmentService::class);
        $map = $service->assignmentsForProgram(1, 3);

        $this->assertSame([1 => 2, 2 => 1], $map);
    }

    public function test_assignments_are_scoped_by_program(): void
    {
        $this->seedPlanWithActivities();

        DB::table('m_activity_type_detail')->insert([
            'id' => 20,
            'name' => 'Explore judging',
            'first_program' => 2,
            'activity_type' => 1,
        ]);
        DB::table('activity_group')->insert([
            'id' => 3,
            'activity_type_detail' => 20,
            'plan' => 1,
        ]);
        DB::table('activity')->insert([
            'id' => 4,
            'activity_group' => 3,
            'start' => '2026-03-15 09:00:00',
            'end' => '2026-03-15 09:30:00',
            'activity_type_detail' => 20,
            'jury_lane' => 3,
            'jury_team' => 1,
        ]);

        $service = app(TeamJuryAssignmentService::class);

        $this->assertSame([1 => 3], $service->assignmentsForProgram(1, 2));
        $this->assertSame([1 => 2, 2 => 1], $service->assignmentsForProgram(1, 3));
    }

    public function test_teams_index_includes_jury_lane_from_plan_slot(): void
    {
        $this->seedPlanWithTeams();

        $event = Event::query()->without('programs')->findOrFail(1);
        $request = Request::create('/api/events/1/teams', 'GET', [
            'program' => 'challenge',
            'sort' => 'plan_order',
        ]);

        $response = app(TeamController::class)->index($request, $event);
        $payload = $response->getData(true);
        $this->assertIsArray($payload);

        $byPlanNo = collect($payload)->keyBy('team_number_plan');
        $this->assertSame(2, $byPlanNo[1]['jury_lane']);
        $this->assertSame(1, $byPlanNo[2]['jury_lane']);
    }

    public function test_teams_index_returns_null_jury_lane_without_matching_activity(): void
    {
        $this->seedPlanWithTeams();

        DB::table('team')->insert([
            'id' => 12,
            'name' => 'Unassigned',
            'event' => 1,
            'first_program' => 3,
            'team_number_hot' => 102,
        ]);
        DB::table('team_plan')->insert([
            'team' => 12,
            'plan' => 1,
            'team_number_plan' => 3,
            'room' => null,
            'noshow' => 0,
        ]);

        $event = Event::query()->without('programs')->findOrFail(1);
        $request = Request::create('/api/events/1/teams', 'GET', [
            'program' => 'challenge',
            'sort' => 'plan_order',
        ]);

        $payload = app(TeamController::class)->index($request, $event)->getData(true);
        $team = collect($payload)->firstWhere('team_number_plan', 3);
        $this->assertNotNull($team);
        $this->assertNull($team['jury_lane']);
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 50);
            $table->unsignedInteger('sequence')->default(0);
        });

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100);
            $table->boolean('needs_attention')->default(false);
            $table->timestamp('needs_attention_checked_at')->nullable();
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100);
            $table->unsignedInteger('event');
        });

        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100);
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->integer('team_number_hot');
            $table->string('location', 255)->nullable();
            $table->string('organization', 255)->nullable();
        });

        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('team');
            $table->unsignedInteger('plan');
            $table->unsignedInteger('team_number_plan');
            $table->unsignedInteger('room')->nullable();
            $table->boolean('noshow')->default(false);
        });

        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('activity_type')->nullable();
        });

        Schema::create('activity_group', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedInteger('plan');
        });

        Schema::create('activity', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('activity_group');
            $table->datetime('start');
            $table->datetime('end');
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedTinyInteger('jury_lane')->nullable();
            $table->unsignedInteger('jury_team')->nullable();
        });
    }

    private function seedPlanWithActivities(): void
    {
        DB::table('m_first_program')->insert([
            'id' => 3,
            'name' => 'CHALLENGE',
            'sequence' => 2,
        ]);

        DB::table('event')->insert(['id' => 1, 'name' => 'Test Event']);
        DB::table('plan')->insert(['id' => 1, 'name' => 'Plan', 'event' => 1]);

        DB::table('m_activity_type_detail')->insert([
            'id' => 10,
            'name' => 'Judging',
            'first_program' => 3,
            'activity_type' => 1,
        ]);

        DB::table('activity_group')->insert([
            'id' => 1,
            'activity_type_detail' => 10,
            'plan' => 1,
        ]);

        DB::table('activity')->insert([
            [
                'id' => 1,
                'activity_group' => 1,
                'start' => '2026-03-15 09:00:00',
                'end' => '2026-03-15 09:30:00',
                'activity_type_detail' => 10,
                'jury_lane' => 2,
                'jury_team' => 1,
            ],
            [
                'id' => 2,
                'activity_group' => 1,
                'start' => '2026-03-15 09:30:00',
                'end' => '2026-03-15 10:00:00',
                'activity_type_detail' => 10,
                'jury_lane' => 2,
                'jury_team' => 1,
            ],
            [
                'id' => 3,
                'activity_group' => 1,
                'start' => '2026-03-15 09:00:00',
                'end' => '2026-03-15 09:30:00',
                'activity_type_detail' => 10,
                'jury_lane' => 1,
                'jury_team' => 2,
            ],
        ]);
    }

    private function seedPlanWithTeams(): void
    {
        $this->seedPlanWithActivities();

        DB::table('team')->insert([
            ['id' => 10, 'name' => 'Team A', 'event' => 1, 'first_program' => 3, 'team_number_hot' => 100],
            ['id' => 11, 'name' => 'Team B', 'event' => 1, 'first_program' => 3, 'team_number_hot' => 101],
        ]);

        DB::table('team_plan')->insert([
            ['team' => 10, 'plan' => 1, 'team_number_plan' => 1, 'room' => null, 'noshow' => 0],
            ['team' => 11, 'plan' => 1, 'team_number_plan' => 2, 'room' => null, 'noshow' => 0],
        ]);
    }
}
