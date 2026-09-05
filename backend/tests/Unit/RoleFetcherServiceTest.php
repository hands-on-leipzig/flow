<?php

namespace Tests\Unit;

use App\Services\RoleFetcherService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleFetcherServiceTest extends TestCase
{
    private RoleFetcherService $roles;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('RoleFetcherService tests require sqlite.');
        }

        $this->createSchema();
        $this->roles = app(RoleFetcherService::class);
    }

    public function test_fetch_roles_returns_distinct_roles_from_plan_activities(): void
    {
        $this->seedBasePlan();

        // Role 4 linked to ATD 10 (has activity); role 5 linked to ATD 11 (no activity)
        DB::table('m_visibility')->insert([
            ['id' => 1, 'activity_type_detail' => 10, 'role' => 4],
            ['id' => 2, 'activity_type_detail' => 11, 'role' => 5],
            ['id' => 3, 'activity_type_detail' => 10, 'role' => 14],
        ]);

        DB::table('activity_group')->insert([
            'id' => 1,
            'activity_type_detail' => 10,
            'plan' => 1,
        ]);
        DB::table('activity')->insert([
            'id' => 1,
            'activity_group' => 1,
            'start' => '2026-03-15 09:00:00',
            'end' => '2026-03-15 09:30:00',
            'activity_type_detail' => 10,
            'extra_block' => null,
        ]);

        $ids = $this->roles->fetchRoles(1)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame([4, 14], $ids);
        $this->assertSame([], array_values(array_intersect($ids, [1, 7, 15, 17, 18, 19, 20, 26])));
    }

    public function test_free_block_activities_do_not_contribute_roles(): void
    {
        $this->seedBasePlan();

        DB::table('m_visibility')->insert([
            ['id' => 1, 'activity_type_detail' => 10, 'role' => 4],
            ['id' => 2, 'activity_type_detail' => 12, 'role' => 99],
        ]);

        DB::table('extra_block')->insert([
            'id' => 1,
            'plan' => 1,
            'name' => 'Free',
            'type' => 'free',
        ]);

        DB::table('activity_group')->insert([
            ['id' => 1, 'activity_type_detail' => 10, 'plan' => 1],
            ['id' => 2, 'activity_type_detail' => 12, 'plan' => 1],
        ]);

        DB::table('activity')->insert([
            [
                'id' => 1,
                'activity_group' => 1,
                'start' => '2026-03-15 09:00:00',
                'end' => '2026-03-15 09:30:00',
                'activity_type_detail' => 10,
                'extra_block' => null,
            ],
            [
                'id' => 2,
                'activity_group' => 2,
                'start' => '2026-03-15 10:00:00',
                'end' => '2026-03-15 10:30:00',
                'activity_type_detail' => 12,
                'extra_block' => 1,
            ],
        ]);

        $ids = $this->roles->fetchRoles(1)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([4], $ids);
    }

    public function test_include_past_false_excludes_out_of_range_activities(): void
    {
        $this->seedBasePlan();

        DB::table('m_visibility')->insert([
            ['id' => 1, 'activity_type_detail' => 10, 'role' => 4],
            ['id' => 2, 'activity_type_detail' => 11, 'role' => 5],
        ]);

        DB::table('activity_group')->insert([
            ['id' => 1, 'activity_type_detail' => 10, 'plan' => 1],
            ['id' => 2, 'activity_type_detail' => 11, 'plan' => 1],
        ]);

        DB::table('activity')->insert([
            [
                'id' => 1,
                'activity_group' => 1,
                'start' => '2026-03-15 09:00:00',
                'end' => '2026-03-15 09:30:00',
                'activity_type_detail' => 10,
                'extra_block' => null,
            ],
            [
                'id' => 2,
                'activity_group' => 2,
                'start' => '2025-01-01 09:00:00',
                'end' => '2025-01-01 09:30:00',
                'activity_type_detail' => 11,
                'extra_block' => null,
            ],
        ]);

        $withoutPast = $this->roles->fetchRoles(1, include_past: false)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $withPast = $this->roles->fetchRoles(1, include_past: true)
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame([4], $withoutPast);
        $this->assertSame([4, 5], $withPast);
    }

    public function test_fetch_roles_includes_public_plan_zero_and_one(): void
    {
        $this->seedBasePlan();

        DB::table('m_visibility')->insert([
            ['id' => 1, 'activity_type_detail' => 10, 'role' => 4],
            ['id' => 2, 'activity_type_detail' => 11, 'role' => 5],
        ]);

        DB::table('activity_group')->insert([
            ['id' => 1, 'activity_type_detail' => 10, 'plan' => 1],
            ['id' => 2, 'activity_type_detail' => 11, 'plan' => 1],
        ]);
        DB::table('activity')->insert([
            [
                'id' => 1,
                'activity_group' => 1,
                'start' => '2026-03-15 09:00:00',
                'end' => '2026-03-15 09:30:00',
                'activity_type_detail' => 10,
                'extra_block' => null,
            ],
            [
                'id' => 2,
                'activity_group' => 2,
                'start' => '2026-03-15 10:00:00',
                'end' => '2026-03-15 10:30:00',
                'activity_type_detail' => 11,
                'extra_block' => null,
            ],
        ]);

        $roles = $this->roles->fetchRoles(1)->keyBy(fn ($row) => (int) $row->id);

        $this->assertTrue((bool) $roles[4]->public_plan);
        $this->assertFalse((bool) $roles[5]->public_plan);
        $this->assertSame([4, 5], $roles->keys()->sort()->values()->all());
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('color_hex')->nullable();
            $table->string('logo_stem')->nullable();
            $table->string('logo_white')->nullable();
        });

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->date('date')->nullable();
            $table->unsignedTinyInteger('days')->default(1);
            $table->unsignedInteger('level')->default(1);
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->string('name')->nullable();
        });

        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('activity_type')->nullable();
        });

        Schema::create('m_role', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('name_short')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedInteger('first_program')->nullable();
            $table->string('differentiation_type')->nullable();
            $table->text('differentiation_source')->nullable();
            $table->string('differentiation_parameter')->nullable();
            $table->boolean('preview_matrix')->default(false);
            $table->boolean('pdf_export')->default(false);
            $table->boolean('staffable')->default(false);
            $table->boolean('public_plan')->default(false);
            $table->string('group_label')->nullable();
        });

        Schema::create('m_visibility', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('activity_type_detail')->nullable();
            $table->unsignedInteger('role')->nullable();
        });

        Schema::create('extra_block', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan');
            $table->string('name')->nullable();
            $table->string('type')->nullable();
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
            $table->unsignedInteger('extra_block')->nullable();
            $table->unsignedInteger('room_type')->nullable();
            $table->unsignedTinyInteger('jury_lane')->nullable();
            $table->unsignedInteger('jury_team')->nullable();
            $table->unsignedTinyInteger('table_1')->nullable();
            $table->unsignedInteger('table_1_team')->nullable();
            $table->unsignedTinyInteger('table_2')->nullable();
            $table->unsignedInteger('table_2_team')->nullable();
            $table->unsignedInteger('slot_team')->nullable();
        });

        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('team');
            $table->unsignedInteger('team_number_plan');
        });

        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program')->nullable();
            $table->string('name')->nullable();
        });

        Schema::create('table_event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedTinyInteger('table_number');
            $table->string('name')->nullable();
        });
    }

    private function seedBasePlan(): void
    {
        DB::table('m_first_program')->insert([
            [
                'id' => 3,
                'name' => 'Challenge',
                'sequence' => 2,
                'color_hex' => 'ed1c24',
                'logo_stem' => 'fll_challenge',
                'logo_white' => 'challenge.png',
            ],
        ]);

        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Test Event',
            'date' => '2026-03-15',
            'days' => 1,
            'level' => 1,
        ]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1, 'name' => 'Plan']);

        DB::table('m_activity_type_detail')->insert([
            ['id' => 10, 'name' => 'Judging', 'first_program' => 3, 'activity_type' => 1],
            ['id' => 11, 'name' => 'Other', 'first_program' => 3, 'activity_type' => 1],
            ['id' => 12, 'name' => 'Free ATD', 'first_program' => null, 'activity_type' => 1],
        ]);

        DB::table('m_role')->insert([
            [
                'id' => 4,
                'name' => 'Jury',
                'name_short' => 'J',
                'sequence' => 1,
                'first_program' => 3,
                'differentiation_parameter' => 'lane',
                'preview_matrix' => 1,
                'pdf_export' => 1,
                'staffable' => 1,
                'public_plan' => 1,
            ],
            [
                'id' => 5,
                'name' => 'Referee',
                'name_short' => 'R',
                'sequence' => 2,
                'first_program' => 3,
                'differentiation_parameter' => 'table',
                'preview_matrix' => 1,
                'pdf_export' => 1,
                'staffable' => 1,
                'public_plan' => 0,
            ],
            [
                'id' => 14,
                'name' => 'Visitor',
                'name_short' => 'V',
                'sequence' => 1,
                'first_program' => null,
                'differentiation_parameter' => null,
                'preview_matrix' => 0,
                'pdf_export' => 0,
                'staffable' => 0,
                'public_plan' => 1,
            ],
            [
                'id' => 99,
                'name' => 'FreeOnly',
                'name_short' => 'F',
                'sequence' => 9,
                'first_program' => null,
                'differentiation_parameter' => null,
                'preview_matrix' => 0,
                'pdf_export' => 0,
                'staffable' => 0,
                'public_plan' => 0,
            ],
        ]);
    }
}
