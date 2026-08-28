<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Models\EventStaffingAssignment;
use App\Models\EventStaffingGroup;
use App\Models\EventStaffingRole;
use App\Services\StaffingSyncService;
use App\Support\PlanParameter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffingSyncServiceTest extends TestCase
{
    private StaffingSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('StaffingSyncService tests require sqlite.');
        }

        $this->createSchema();
        $this->sync = app(StaffingSyncService::class);
    }

    public function test_sync_creates_groups_from_differentiation_count(): void
    {
        $this->seedChallengeEvent(lanes: 3);

        $stats = $this->sync->syncForEvent(1);

        $this->assertSame(1, $stats['roles']);
        $this->assertSame(3, $stats['groups_created']);

        $role = EventStaffingRole::query()->where('event', 1)->where('m_role', 4)->first();
        $this->assertNotNull($role);
        $this->assertSame(2, $role->min);
        $this->assertSame(3, $role->best);
        $this->assertSame(5, $role->max);
        $this->assertSame('Jury help text', $role->ui_description);
        $this->assertSame(3, EventStaffingGroup::query()->where('event_staffing_role', $role->id)->count());
        $this->assertFalse($this->sync->staffingOk(1));
    }

    public function test_sync_marks_surplus_and_collapses_empty(): void
    {
        $this->seedChallengeEvent(lanes: 3);
        $this->sync->syncForEvent(1);

        $role = EventStaffingRole::query()->where('event', 1)->where('m_role', 4)->firstOrFail();
        $group3 = EventStaffingGroup::query()
            ->where('event_staffing_role', $role->id)
            ->where('group_index', 3)
            ->firstOrFail();

        EventStaffingAssignment::create([
            'event_staffing_group' => $group3->id,
            'volunteer_person' => 1,
            'created_at' => now(),
        ]);

        DB::table('plan_param_value')->where('parameter', 50)->update(['set_value' => '2']);
        PlanParameter::load(1); // warm nothing special; sync reloads

        $stats = $this->sync->syncForEvent(1);

        $group3->refresh();
        $this->assertTrue((bool) $group3->surplus);
        $this->assertGreaterThanOrEqual(1, $stats['groups_surplus']);
        $this->assertFalse($this->sync->staffingOk(1));

        EventStaffingAssignment::query()->where('event_staffing_group', $group3->id)->delete();
        $stats2 = $this->sync->syncForEvent(1);

        $this->assertDatabaseMissing('event_staffing_group', ['id' => $group3->id]);
        $this->assertGreaterThanOrEqual(1, $stats2['groups_collapsed']);
    }

    public function test_sync_snapshots_rule_values_on_next_run(): void
    {
        $this->seedChallengeEvent(lanes: 1);
        $this->sync->syncForEvent(1);

        DB::table('m_staffing_rule')->where('m_role', 4)->update([
            'min' => 1,
            'best' => 1,
            'max' => 2,
            'ui_description' => 'updated',
        ]);

        $this->sync->syncForEvent(1);

        $role = EventStaffingRole::query()->where('event', 1)->where('m_role', 4)->firstOrFail();
        $this->assertSame(1, $role->min);
        $this->assertSame(1, $role->best);
        $this->assertSame(2, $role->max);
        $this->assertSame('updated', $role->ui_description);
    }

    public function test_sync_allows_optional_catalog_role_with_min_zero(): void
    {
        $this->seedChallengeEvent(lanes: 1);

        DB::table('m_staffing_rule')->where('m_role', 4)->update([
            'min' => 0,
            'best' => 1,
            'max' => 2,
        ]);

        $stats = $this->sync->syncForEvent(1);

        $this->assertSame(1, $stats['roles']);
        $this->assertNotContains('invalid min/best/max on rule for role 4', $stats['skipped']);

        $role = EventStaffingRole::query()->where('event', 1)->where('m_role', 4)->firstOrFail();
        $this->assertSame(0, $role->min);
        $this->assertSame(1, $role->best);
        $this->assertSame(2, $role->max);
    }

    public function test_program_off_marks_catalog_groups_surplus(): void
    {
        $this->seedChallengeEvent(lanes: 2);
        $this->sync->syncForEvent(1);

        $role = EventStaffingRole::query()->where('event', 1)->where('m_role', 4)->firstOrFail();
        $g1 = EventStaffingGroup::query()->where('event_staffing_role', $role->id)->where('group_index', 1)->firstOrFail();
        EventStaffingAssignment::create([
            'event_staffing_group' => $g1->id,
            'volunteer_person' => 1,
            'created_at' => now(),
        ]);

        // Detach Challenge from event → role no longer active
        DB::table('event_program')->where('event', 1)->delete();

        $this->sync->syncForEvent(1);

        $g1->refresh();
        $this->assertTrue((bool) $g1->surplus);
        $this->assertFalse($this->sync->staffingOk(1));
    }

    public function test_staffing_ok_when_min_met_and_no_surplus_people(): void
    {
        $this->seedChallengeEvent(lanes: 1);
        DB::table('m_staffing_rule')->where('m_role', 4)->update(['min' => 1, 'best' => 1, 'max' => 2]);
        $this->sync->syncForEvent(1);

        $role = EventStaffingRole::query()->where('event', 1)->where('m_role', 4)->firstOrFail();
        $group = EventStaffingGroup::query()->where('event_staffing_role', $role->id)->firstOrFail();
        EventStaffingAssignment::create([
            'event_staffing_group' => $group->id,
            'volunteer_person' => 1,
            'created_at' => now(),
        ]);

        $this->assertTrue($this->sync->staffingOk(1));
    }

    public function test_summary_by_scope_counts_assigned_and_missing_min(): void
    {
        $this->seedChallengeEvent(lanes: 2);
        DB::table('m_staffing_rule')->where('m_role', 4)->update(['min' => 2, 'best' => 2, 'max' => 3]);
        $this->sync->syncForEvent(1);

        $role = EventStaffingRole::query()->where('event', 1)->where('m_role', 4)->firstOrFail();
        $groups = EventStaffingGroup::query()
            ->where('event_staffing_role', $role->id)
            ->orderBy('group_index')
            ->get();

        EventStaffingAssignment::create([
            'event_staffing_group' => $groups[0]->id,
            'volunteer_person' => 1,
            'created_at' => now(),
        ]);

        $summary = collect($this->sync->summaryByScope(1, [FirstProgram::CHALLENGE->value]))
            ->keyBy('key');

        $this->assertSame(0, $summary['cross']['assigned']);
        $this->assertSame(0, $summary['cross']['missing_min']);
        $this->assertSame(0, $summary['cross']['roles']);
        $this->assertSame(1, $summary['program:'.FirstProgram::CHALLENGE->value]['assigned']);
        $this->assertSame(3, $summary['program:'.FirstProgram::CHALLENGE->value]['missing_min']);
        $this->assertSame(1, $summary['program:'.FirstProgram::CHALLENGE->value]['roles']);
        $this->assertSame(0, $summary['local']['assigned']);
        $this->assertSame(0, $summary['local']['missing_min']);
        $this->assertSame(0, $summary['local']['roles']);
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
            $table->unsignedInteger('m_role')->primary();
            $table->unsignedSmallInteger('min');
            $table->unsignedSmallInteger('best');
            $table->unsignedSmallInteger('max');
            $table->text('ui_description')->nullable();
        });

        Schema::create('volunteer_person', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('regional_partner')->default(1);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->timestamps();
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

    private function seedChallengeEvent(int $lanes): void
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
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
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
            'm_role' => 4,
            'min' => 2,
            'best' => 3,
            'max' => 5,
            'ui_description' => 'Jury help text',
        ]);

        DB::table('volunteer_person')->insert([
            'id' => 1,
            'regional_partner' => 1,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
