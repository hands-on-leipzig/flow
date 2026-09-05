<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
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

    public function test_job_owner_appears_visibility_does_not(): void
    {
        $this->seedBasePlan();
        $this->insertAtd(10, 'Awards', role: 2);
        $this->insertAtd(11, 'Judging', role: 4);

        DB::table('m_visibility')->insert([
            ['id' => 1, 'activity_type_detail' => 10, 'role' => 2],
            ['id' => 2, 'activity_type_detail' => 10, 'role' => 4],
            ['id' => 3, 'activity_type_detail' => 10, 'role' => 11],
        ]);

        $this->insertActivity(1, 10, '2026-03-15 09:00:00');

        $ids = $this->ids();

        $this->assertContains(2, $ids);
        $this->assertContains(14, $ids);
        $this->assertNotContains(4, $ids);
        $this->assertNotContains(11, $ids);
    }

    public function test_robot_check_activity_adds_robot_check_not_from_match(): void
    {
        $this->seedBasePlan();
        $this->insertAtd(15, 'Match', role: 5);
        $this->insertAtd(16, 'Check', role: 11);

        $this->insertActivity(1, 16, '2026-03-15 09:00:00');

        $ids = $this->ids();
        $this->assertContains(11, $ids);
        $this->assertNotContains(5, $ids);

        DB::table('activity')->delete();
        $this->insertActivity(2, 15, '2026-03-15 10:00:00');

        $ids = $this->ids();
        $this->assertContains(5, $ids);
        $this->assertNotContains(11, $ids);
    }

    public function test_free_block_with_null_role_does_not_add_a_role(): void
    {
        $this->seedBasePlan();
        $this->insertAtd(10, 'Judging', role: 4);
        $this->insertAtd(12, 'Free ATD', role: null);

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

        $this->insertActivity(1, 10, '2026-03-15 09:00:00');
        $this->insertActivity(2, 12, '2026-03-15 10:00:00', extraBlock: 1);

        $ids = $this->ids();
        $this->assertContains(4, $ids);
        $this->assertNotContains(99, $ids);
    }

    public function test_include_past_false_excludes_out_of_range_job_owners(): void
    {
        $this->seedBasePlan();
        $this->insertAtd(10, 'Judging', role: 4);
        $this->insertAtd(11, 'Other', role: 5);

        $this->insertActivity(1, 10, '2026-03-15 09:00:00');
        $this->insertActivity(2, 11, '2025-01-01 09:00:00');

        $withoutPast = $this->roles->fetchRoles(1, include_past: false)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $withPast = $this->roles->fetchRoles(1, include_past: true)
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertContains(4, $withoutPast);
        $this->assertNotContains(5, $withoutPast);
        $this->assertSame([4, 5, 14], $withPast);
    }

    public function test_fetch_roles_keeps_public_plan_flags_on_job_owners(): void
    {
        $this->seedBasePlan();
        $this->insertAtd(10, 'Judging', role: 4);
        $this->insertAtd(11, 'Other', role: 5);
        $this->insertActivity(1, 10, '2026-03-15 09:00:00');
        $this->insertActivity(2, 11, '2026-03-15 10:00:00');

        $roles = $this->roles->fetchRoles(1)->keyBy(fn ($row) => (int) $row->id);

        $this->assertTrue((bool) $roles[4]->public_plan);
        $this->assertFalse((bool) $roles[5]->public_plan);
    }

    public function test_publikum_per_attached_event_program(): void
    {
        $this->seedBasePlan();
        DB::table('m_first_program')->insert([
            [
                'id' => FirstProgram::EXPLORE->value,
                'name' => 'Explore',
                'display_name' => 'Explore',
                'sequence' => 1,
                'color_hex' => '00a651',
                'logo_stem' => 'fll_explore',
                'logo_white' => 'explore.png',
            ],
        ]);
        DB::table('event_program')->insert([
            ['id' => 1, 'event' => 1, 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 2, 'event' => 1, 'first_program' => FirstProgram::EXPLORE->value],
        ]);
        $this->insertRole(6, 'Publikum C', firstProgram: 3, publicPlan: 1);
        $this->insertRole(10, 'Publikum E', firstProgram: 2, publicPlan: 1);

        $ids = $this->ids();

        $this->assertSame([14, 10, 6], $ids);
    }

    /**
     * @return list<int>
     */
    private function ids(): array
    {
        return $this->roles->fetchRoles(1)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function insertAtd(int $id, string $name, ?int $role): void
    {
        DB::table('m_activity_type_detail')->insert([
            'id' => $id,
            'name' => $name,
            'first_program' => 3,
            'activity_type' => 1,
            'role' => $role,
        ]);
    }

    private function insertActivity(int $id, int $atd, string $start, ?int $extraBlock = null): void
    {
        DB::table('activity_group')->insert([
            'id' => $id,
            'activity_type_detail' => $atd,
            'plan' => 1,
        ]);
        DB::table('activity')->insert([
            'id' => $id,
            'activity_group' => $id,
            'start' => $start,
            'end' => $start,
            'activity_type_detail' => $atd,
            'extra_block' => $extraBlock,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('display_name')->nullable();
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

        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
        });

        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('activity_type')->nullable();
            $table->unsignedInteger('role')->nullable();
        });

        Schema::create('m_role', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('name_short')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedInteger('first_program')->nullable();
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
        });
    }

    private function seedBasePlan(): void
    {
        DB::table('m_first_program')->insert([
            [
                'id' => 3,
                'name' => 'Challenge',
                'display_name' => 'Challenge',
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

        $this->insertRole(2, 'Moderation', firstProgram: null, publicPlan: 1, sequence: 2);
        $this->insertRole(4, 'Jury', firstProgram: 3, publicPlan: 1, sequence: 4, staffable: true);
        $this->insertRole(5, 'Referee', firstProgram: 3, publicPlan: 0, sequence: 6, staffable: true);
        $this->insertRole(11, 'Robot-Check', firstProgram: 3, publicPlan: 1, sequence: 7, staffable: true);
        $this->insertRole(14, 'Visitor', firstProgram: null, publicPlan: 1, sequence: 1);
        $this->insertRole(99, 'FreeOnly', firstProgram: null, publicPlan: 0, sequence: 9);
    }

    private function insertRole(
        int $id,
        string $name,
        ?int $firstProgram,
        int $publicPlan,
        int $sequence = 1,
        bool $staffable = false,
    ): void {
        DB::table('m_role')->insert([
            'id' => $id,
            'name' => $name,
            'name_short' => null,
            'sequence' => $sequence,
            'first_program' => $firstProgram,
            'differentiation_parameter' => null,
            'preview_matrix' => 0,
            'pdf_export' => 0,
            'staffable' => $staffable ? 1 : 0,
            'public_plan' => $publicPlan,
        ]);
    }
}
