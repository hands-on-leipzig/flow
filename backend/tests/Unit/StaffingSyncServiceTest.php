<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Http\Controllers\Api\EventStaffingAssignmentController;
use App\Models\Event;
use App\Models\EventStaffingAssignment;
use App\Models\EventStaffingGroup;
use App\Models\EventStaffingRole;
use App\Services\StaffingSyncService;
use App\Support\PlanParameter;
use App\Support\StaffingAssignmentLabel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
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
        $this->assertSame('Jury-Gruppe', $role->group_label);
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
            'event_staffing_role' => $role->id,
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
        $this->assertSame('Jury-Gruppe', $role->group_label);

        DB::table('m_role')->where('id', 4)->update(['group_label' => 'Jury-Spur']);
        $this->sync->syncForEvent(1);
        $role->refresh();
        $this->assertSame('Jury-Spur', $role->group_label);
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
            'event_staffing_role' => $role->id,
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
            'event_staffing_role' => $role->id,
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
            'event_staffing_role' => $role->id,
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

    public function test_open_positions_aggregates_critical_and_recommended_per_role(): void
    {
        $this->seedChallengeEvent(lanes: 2);
        DB::table('m_staffing_rule')->where('m_role', 4)->update(['min' => 2, 'best' => 4, 'max' => 5]);
        $this->sync->syncForEvent(1);

        $role = EventStaffingRole::query()->where('event', 1)->where('m_role', 4)->firstOrFail();
        $groups = EventStaffingGroup::query()
            ->where('event_staffing_role', $role->id)
            ->orderBy('group_index')
            ->get();

        EventStaffingAssignment::create([
            'event_staffing_role' => $role->id,
            'event_staffing_group' => $groups[0]->id,
            'volunteer_person' => 1,
            'created_at' => now(),
        ]);

        $openPositions = collect($this->sync->openPositionsByScope(1, [FirstProgram::CHALLENGE->value]))
            ->keyBy('key');

        $challenge = $openPositions['program:'.FirstProgram::CHALLENGE->value];
        $this->assertCount(2, $challenge['critical']);
        $this->assertSame('Jury-Gruppe 1', $challenge['critical'][0]['label']);
        $this->assertSame(1, $challenge['critical'][0]['wanted']);
        $this->assertSame((int) $groups[0]->id, $challenge['critical'][0]['group_id']);
        $this->assertSame('Jury-Gruppe 2', $challenge['critical'][1]['label']);
        $this->assertSame(2, $challenge['critical'][1]['wanted']);
        $this->assertCount(2, $challenge['recommended']);
        $this->assertSame(2, $challenge['recommended'][0]['wanted']);
        $this->assertSame(2, $challenge['recommended'][1]['wanted']);
        $this->assertArrayNotHasKey('cross', $openPositions->all());
    }

    public function test_open_positions_skips_surplus_groups(): void
    {
        $this->seedChallengeEvent(lanes: 2);
        $this->sync->syncForEvent(1);

        $role = EventStaffingRole::query()->where('event', 1)->where('m_role', 4)->firstOrFail();
        $group = EventStaffingGroup::query()
            ->where('event_staffing_role', $role->id)
            ->where('group_index', 2)
            ->firstOrFail();
        $group->surplus = true;
        $group->save();

        DB::table('plan_param_value')->where('parameter', 50)->update(['set_value' => '1']);
        $this->sync->syncForEvent(1);

        $openPositions = $this->sync->openPositionsByScope(1, [FirstProgram::CHALLENGE->value]);
        $challenge = collect($openPositions)->firstWhere('key', 'program:'.FirstProgram::CHALLENGE->value);
        $this->assertNotNull($challenge);
        $this->assertSame(2, $challenge['critical'][0]['wanted']);
        $this->assertSame('Jury-Gruppe 1', $challenge['critical'][0]['label']);
        $this->assertSame(1, $challenge['recommended'][0]['wanted']);
    }

    public function test_ungrouped_catalog_role_creates_no_groups(): void
    {
        $this->seedChallengeEvent(lanes: 1);
        $this->insertUngroupedChallengeRole();

        $stats = $this->sync->syncForEvent(1);

        $head = EventStaffingRole::query()->where('event', 1)->where('m_role', 27)->first();
        $this->assertNotNull($head);
        $this->assertNull($head->group_label);
        $this->assertFalse((bool) $head->surplus);
        $this->assertSame(0, EventStaffingGroup::query()->where('event_staffing_role', $head->id)->count());
        $this->assertGreaterThanOrEqual(2, $stats['roles']);
    }

    public function test_sync_leaves_local_role_without_groups(): void
    {
        $this->seedChallengeEvent(lanes: 1);
        $local = EventStaffingRole::create([
            'event' => 1,
            'm_role' => null,
            'label' => 'Catering',
            'group_label' => null,
            'min' => 1,
            'best' => 1,
            'max' => 2,
            'sequence' => 90,
            'surplus' => false,
        ]);

        $this->sync->syncForEvent(1);

        $this->assertSame(0, EventStaffingGroup::query()->where('event_staffing_role', $local->id)->count());
        $this->assertFalse((bool) $local->fresh()->surplus);
    }

    public function test_ungrouped_program_off_sets_role_surplus(): void
    {
        $this->seedChallengeEvent(lanes: 1);
        $this->insertUngroupedChallengeRole();
        $this->sync->syncForEvent(1);

        $head = EventStaffingRole::query()->where('event', 1)->where('m_role', 27)->firstOrFail();
        EventStaffingAssignment::create([
            'event_staffing_role' => $head->id,
            'event_staffing_group' => null,
            'volunteer_person' => 1,
            'created_at' => now(),
        ]);

        DB::table('event_program')->where('event', 1)->delete();
        $this->sync->syncForEvent(1);

        $head->refresh();
        $this->assertTrue((bool) $head->surplus);
        $this->assertSame(0, EventStaffingGroup::query()->where('event_staffing_role', $head->id)->count());
        $this->assertFalse($this->sync->staffingOk(1));
    }

    public function test_local_role_create_has_no_group(): void
    {
        $this->seedChallengeEvent(lanes: 1);
        $event = Event::query()->findOrFail(1);
        $controller = app(EventStaffingAssignmentController::class);

        $response = $controller->storeLocalRole(
            Request::create('/', 'POST', [
                'label' => 'Catering A',
                'min' => 1,
                'best' => 2,
                'max' => 3,
            ]),
            $event,
        );

        $this->assertSame(201, $response->getStatusCode());
        $roleId = $response->getData(true)['role']['id'];
        $this->assertSame(0, EventStaffingGroup::query()->where('event_staffing_role', $roleId)->count());
    }

    public function test_assign_via_role_for_ungrouped(): void
    {
        $this->seedChallengeEvent(lanes: 1);
        $this->insertUngroupedChallengeRole();
        $this->sync->syncForEvent(1);

        $head = EventStaffingRole::query()->where('event', 1)->where('m_role', 27)->firstOrFail();
        DB::table('event_volunteer_roster')->insert([
            'event' => 1,
            'volunteer_person' => 1,
            'created_at' => now(),
        ]);

        $event = Event::query()->findOrFail(1);
        $controller = app(EventStaffingAssignmentController::class);
        $response = $controller->storeOnRole(
            Request::create('/', 'POST', ['volunteer_person' => 1]),
            $event,
            $head,
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('event_staffing_assignment', [
            'event_staffing_role' => $head->id,
            'event_staffing_group' => null,
            'volunteer_person' => 1,
        ]);
    }

    public function test_open_positions_for_ungrouped_role_min_gap(): void
    {
        $this->seedChallengeEvent(lanes: 1);
        $this->insertUngroupedChallengeRole();
        $this->sync->syncForEvent(1);

        $openPositions = collect($this->sync->openPositionsByScope(1, [FirstProgram::CHALLENGE->value]))
            ->keyBy('key');
        $challenge = $openPositions['program:'.FirstProgram::CHALLENGE->value];
        $head = collect($challenge['critical'])->firstWhere('label', 'Jury-Verantwortliche:r');
        $this->assertNotNull($head);
        $this->assertNull($head['group_id']);
        $this->assertSame(1, $head['wanted']);
    }

    public function test_assignment_tile_name_uses_group_label_and_program(): void
    {
        $this->seedChallengeEvent(lanes: 2);
        $this->sync->syncForEvent(1);
        $role = EventStaffingRole::query()->where('event', 1)->where('m_role', 4)->firstOrFail();
        $group2 = EventStaffingGroup::query()
            ->where('event_staffing_role', $role->id)
            ->where('group_index', 2)
            ->firstOrFail();
        EventStaffingAssignment::create([
            'event_staffing_role' => $role->id,
            'event_staffing_group' => $group2->id,
            'volunteer_person' => 1,
            'created_at' => now(),
        ]);

        $byPerson = StaffingAssignmentLabel::assignmentsByPerson(1);
        $this->assertSame('CHALLENGE: Jury-Gruppe 2', $byPerson[1][0]['tile_name']);
        $this->assertSame('Jury', $byPerson[1][0]['label']);
        $this->assertSame(2, $byPerson[1][0]['group_index']);
    }

    private function insertUngroupedChallengeRole(): void
    {
        DB::table('m_role')->insert([
            'id' => 27,
            'name' => 'Jury-Verantwortliche:r',
            'sequence' => 3,
            'first_program' => FirstProgram::CHALLENGE->value,
            'differentiation_type' => null,
            'differentiation_source' => null,
            'differentiation_parameter' => null,
            'staffable' => 1,
            'group_label' => null,
        ]);
        DB::table('m_staffing_rule')->insert([
            'id' => 2,
            'm_role' => 27,
            'min' => 1,
            'best' => 1,
            'max' => 2,
            'ui_description' => null,
        ]);
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
            $table->string('name')->nullable();
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
            $table->string('group_label')->nullable();
        });

        Schema::create('m_staffing_rule', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('m_role')->unique();
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
            $table->string('group_label')->nullable();
            $table->unsignedSmallInteger('min');
            $table->unsignedSmallInteger('best');
            $table->unsignedSmallInteger('max');
            $table->text('ui_description')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('surplus')->default(false);
        });

        Schema::create('event_staffing_group', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event_staffing_role');
            $table->unsignedSmallInteger('group_index')->default(1);
            $table->boolean('surplus')->default(false);
        });

        Schema::create('event_staffing_assignment', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event_staffing_role');
            $table->unsignedInteger('event_staffing_group')->nullable();
            $table->unsignedInteger('volunteer_person');
            $table->timestamp('created_at')->nullable();
            $table->unique(['event_staffing_role', 'volunteer_person']);
        });

        Schema::create('event_volunteer_roster', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event');
            $table->unsignedInteger('volunteer_person');
            $table->timestamp('created_at')->nullable();
        });
    }

    private function seedChallengeEvent(int $lanes): void
    {
        DB::table('m_first_program')->insert([
            ['id' => FirstProgram::EXPLORE->value, 'sequence' => 1, 'name' => 'EXPLORE'],
            ['id' => FirstProgram::CHALLENGE->value, 'sequence' => 2, 'name' => 'CHALLENGE'],
            ['id' => FirstProgram::FUTURE_8->value, 'sequence' => 5, 'name' => 'FUTURE_8'],
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
            'group_label' => 'Jury-Gruppe',
        ]);

        DB::table('m_staffing_rule')->insert([
            'id' => 1,
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
