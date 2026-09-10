<?php

namespace Tests\Unit;

use App\Services\ActivityFetcherService;
use App\Services\PublicPlanService;
use App\Services\RoleFetcherService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PublicPlanServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('PublicPlanService tests require sqlite.');
        }

        $this->createSchema();
        $this->seedPlan();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_roles_omits_public_plan_zero(): void
    {
        $this->bindRoles([
            $this->roleRow(4, publicPlan: 1, name: 'Juror:in'),
            $this->roleRow(33, publicPlan: 0, name: 'DJ'),
            $this->roleRow(14, publicPlan: 1, name: 'Publikum', differentiationParameter: null),
        ]);

        $payload = app(PublicPlanService::class)->getRoles(1);
        $ids = collect($payload['roles'])->pluck('id')->all();

        $this->assertSame([4, 14], $ids);
    }

    public function test_lane_option_labels_use_group_label(): void
    {
        $this->bindRoles([
            $this->roleRow(
                4,
                publicPlan: 1,
                name: 'Juror:in',
                groupLabel: 'Jury-Gruppe',
                differentiationParameter: 'lane',
            ),
        ]);

        $payload = app(PublicPlanService::class)->getRoles(1);
        $labels = collect($payload['roles'][0]['options'])->pluck('label')->all();

        $this->assertSame('Jury-Gruppe', $payload['roles'][0]['group_label']);
        $this->assertSame(['Jury-Gruppe 1', 'Jury-Gruppe 2', 'Jury-Gruppe 3'], $labels);
    }

    public function test_get_roles_includes_programs_and_catalog_identity(): void
    {
        $this->bindRoles([
            $this->roleRow(4, publicPlan: 1, name: 'Juror:in'),
        ]);

        $payload = app(PublicPlanService::class)->getRoles(1);

        $this->assertSame([
            [
                'id' => 3,
                'display_name' => 'Challenge',
                'sequence' => 2,
                'logo_stem' => 'fll_challenge',
                'logo_white' => 'challenge.png',
                'color_hex' => 'ed1c24',
            ],
        ], $payload['programs']);
        $this->assertSame(2, $payload['roles'][0]['first_program_sequence']);
        $this->assertSame('Challenge', $payload['roles'][0]['first_program_display_name']);
    }

    public function test_team_option_labels_use_name_and_draht_id(): void
    {
        $this->attachFuture8(teams: 2);
        DB::table('team')->insert([
            'id' => 1,
            'event' => 1,
            'first_program' => 8,
            'name' => 'Robo',
            'location' => 'Leipzig',
            'organization' => 'Gymnasium Mockau',
            'team_number_hot' => 42,
        ]);
        DB::table('room')->insert(['id' => 1, 'name' => 'A2.04']);
        DB::table('team_plan')->insert([
            'id' => 1,
            'plan' => 1,
            'team' => 1,
            'team_number_plan' => 1,
            'room' => 1,
            'noshow' => 0,
        ]);

        $this->bindRoles([
            $this->roleRow(
                21,
                publicPlan: 1,
                name: 'Team',
                differentiationParameter: 'team',
                firstProgram: 8,
            ),
        ]);

        $payload = app(PublicPlanService::class)->getRoles(1);
        $options = $payload['roles'][0]['options'];

        $this->assertSame(['Robo (42)', 'T2 (Noch nicht angemeldet)'], collect($options)->pluck('label')->all());
        $this->assertSame('Gymnasium Mockau', $options[0]['organization']);
        $this->assertSame('Leipzig', $options[0]['location']);
        $this->assertSame('A2.04', $options[0]['room']);
        $this->assertNull($options[1]['organization']);
        $this->assertNull($options[1]['location']);
        $this->assertNull($options[1]['room']);
    }

    public function test_table_option_labels_use_group_label(): void
    {
        $this->attachFuture8(fields: 1);
        $this->bindRoles([
            $this->roleRow(
                23,
                publicPlan: 1,
                name: 'Schiedsrichter:in',
                groupLabel: 'Feld',
                differentiationParameter: 'table',
                firstProgram: 8,
            ),
        ]);

        $payload = app(PublicPlanService::class)->getRoles(1);
        $labels = collect($payload['roles'][0]['options'])->pluck('label')->all();

        $this->assertSame(['Feld 1'], $labels);
    }

    public function test_get_schedule_activity_name_uses_atd_name_not_preview(): void
    {
        $fetcher = Mockery::mock(ActivityFetcherService::class);
        $fetcher->shouldReceive('fetchActivities')->once()->andReturn(collect([
            (object) [
                'activity_id' => 10,
                'activity_group_id' => 1,
                'start_time' => '2026-03-15 11:00:00',
                'end_time' => '2026-03-15 11:50:00',
                'activity_name' => 'Mit Team',
                'activity_atd_name' => 'Jurygespräch',
                'activity_type_detail_id' => 17,
                'activity_type_code' => 'j_with_team',
                'activity_presence' => 'punctual',
                'activity_first_program_id' => 3,
                'activity_first_program_name' => 'Challenge',
                'activity_description' => null,
                'group_atd_name' => 'Jurybewertung',
                'group_first_program_id' => 3,
                'group_first_program_name' => 'Challenge',
                'group_description' => null,
                'group_activity_type_code' => 'j_judging',
                'group_presence' => 'punctual',
                'lane' => 1,
                'team' => 1,
                'table_1' => null,
                'table_1_name' => null,
                'table_1_team' => null,
                'table_2' => null,
                'table_2_name' => null,
                'table_2_team' => null,
                'program_name' => 'Challenge',
                'jury_team_name' => 'Capricorns',
                'table_1_team_name' => null,
                'table_2_team_name' => null,
                'room_type_id' => null,
                'room_type_name' => null,
                'room_id' => null,
                'room_name' => 'A2.04',
            ],
        ]));
        $this->app->instance(ActivityFetcherService::class, $fetcher);

        $payload = app(PublicPlanService::class)->getSchedule(1, [
            'role' => 3,
            'expired' => 'yes',
        ]);

        $this->assertSame(
            'Jurygespräch',
            $payload['groups'][0]['activities'][0]['activity_name'],
        );
    }

    public function test_get_roles_includes_team_even_when_role_fetcher_omits_it(): void
    {
        $this->attachFuture8(teams: 2);
        DB::table('m_role')->insert([
            'id' => 21,
            'name' => 'Team',
            'name_short' => 'F8 T',
            'sequence' => 2,
            'first_program' => 8,
            'differentiation_parameter' => 'team',
            'preview_matrix' => 1,
            'pdf_export' => 1,
            'public_plan' => 1,
            'staffable' => 0,
            'group_label' => null,
        ]);

        $this->bindRoles([
            $this->roleRow(14, publicPlan: 1, name: 'Publikum', differentiationParameter: null),
        ]);

        $payload = app(PublicPlanService::class)->getRoles(1);
        $byId = collect($payload['roles'])->keyBy('id');

        $this->assertTrue($byId->has(14));
        $this->assertTrue($byId->has(21));
        $this->assertSame('team', $byId[21]['differentiation_parameter']);
        $this->assertSame(
            ['T1 (Noch nicht angemeldet)', 'T2 (Noch nicht angemeldet)'],
            collect($byId[21]['options'])->pluck('label')->all(),
        );
    }

    public function test_get_lane_meetings_first_with_team_only(): void
    {
        $this->seedLaneMeetingCatalog();
        DB::table('team')->insert([
            [
                'id' => 1,
                'event' => 1,
                'first_program' => 3,
                'name' => 'Capricorns',
                'location' => null,
                'team_number_hot' => 12,
            ],
            [
                'id' => 2,
                'event' => 1,
                'first_program' => 3,
                'name' => 'NoHot',
                'location' => null,
                'team_number_hot' => null,
            ],
        ]);
        DB::table('team_plan')->insert([
            ['id' => 1, 'plan' => 1, 'team' => 1, 'team_number_plan' => 1, 'noshow' => 0],
            ['id' => 2, 'plan' => 1, 'team' => 2, 'team_number_plan' => 4, 'noshow' => 0],
        ]);
        DB::table('activity_group')->insert([
            ['id' => 1, 'activity_type_detail' => 17, 'plan' => 1],
            ['id' => 2, 'activity_type_detail' => 18, 'plan' => 1],
            ['id' => 3, 'activity_type_detail' => 1, 'plan' => 1],
        ]);
        DB::table('activity')->insert([
            $this->activityRow(1, 2, 18, '2026-03-15 08:00:00', lane: 1, team: 3),
            $this->activityRow(2, 1, 17, '2026-03-15 08:30:00', lane: 1, team: null),
            $this->activityRow(3, 1, 17, '2026-03-15 08:00:00', lane: 2, team: 1),
            $this->activityRow(4, 3, 1, '2026-03-15 08:15:00', lane: 1, team: 9),
            $this->activityRow(5, 1, 17, '2026-03-15 09:00:00', lane: 1, team: 1),
            $this->activityRow(6, 1, 17, '2026-03-15 10:00:00', lane: 1, team: 2),
            $this->activityRow(7, 1, 17, '2026-03-15 11:00:00', lane: 1, team: 1),
            $this->activityRow(8, 1, 17, '2026-03-15 10:30:00', lane: 1, team: 4),
        ]);

        $payload = app(PublicPlanService::class)->getLaneMeetings(1, 3, 1);

        $this->assertSame(1, $payload['plan_id']);
        $this->assertSame(3, $payload['program']);
        $this->assertSame(1, $payload['lane']);
        $this->assertSame([
            [
                'start_time' => '2026-03-15 09:00:00',
                'team' => 1,
                'label' => 'Capricorns (12)',
            ],
            [
                'start_time' => '2026-03-15 10:00:00',
                'team' => 2,
                'label' => 'T2 (Noch nicht angemeldet)',
            ],
            [
                'start_time' => '2026-03-15 10:30:00',
                'team' => 4,
                'label' => 'NoHot',
            ],
        ], $payload['meetings']);
    }

    public function test_get_table_matches_lists_every_match_on_that_table(): void
    {
        $this->seedTableMatchCatalog();
        DB::table('team')->insert([
            [
                'id' => 1,
                'event' => 1,
                'first_program' => 3,
                'name' => 'Capricorns',
                'location' => null,
                'team_number_hot' => 12,
            ],
            [
                'id' => 2,
                'event' => 1,
                'first_program' => 3,
                'name' => 'SideTwo',
                'location' => null,
                'team_number_hot' => 7,
            ],
        ]);
        DB::table('team_plan')->insert([
            ['id' => 1, 'plan' => 1, 'team' => 1, 'team_number_plan' => 1, 'noshow' => 0],
            ['id' => 2, 'plan' => 1, 'team' => 2, 'team_number_plan' => 5, 'noshow' => 0],
        ]);
        DB::table('activity_group')->insert([
            ['id' => 10, 'activity_type_detail' => 15, 'plan' => 1],
            ['id' => 11, 'activity_type_detail' => 16, 'plan' => 1],
        ]);
        DB::table('activity')->insert([
            $this->matchRow(20, 11, 16, '2026-03-15 09:00:00', table1: 1, team1: 1, table2: 2, team2: 2),
            $this->matchRow(21, 10, 15, '2026-03-15 09:05:00', table1: 1, team1: 1, table2: 2, team2: 2),
            $this->matchRow(22, 10, 15, '2026-03-15 09:45:00', table1: 2, team1: 3, table2: 1, team2: 5),
            $this->matchRow(23, 10, 15, '2026-03-15 10:00:00', table1: 1, team1: 1, table2: 2, team2: 4),
            $this->matchRow(24, 10, 15, '2026-03-15 10:30:00', table1: 1, team1: null, table2: 2, team2: 2),
            $this->matchRow(25, 10, 15, '2026-03-15 11:00:00', table1: 2, team1: 4, table2: 3, team2: 6),
            $this->matchRow(26, 10, 15, '2026-03-15 12:00:00', table1: 1, team1: 8, table2: 2, team2: 9),
        ]);

        $payload = app(PublicPlanService::class)->getTableMatches(1, 3, 1);

        $this->assertSame(1, $payload['plan_id']);
        $this->assertSame(3, $payload['program']);
        $this->assertSame(1, $payload['table']);
        $this->assertSame([
            [
                'start_time' => '2026-03-15 09:05:00',
                'team' => 1,
                'label' => 'Capricorns (12)',
            ],
            [
                'start_time' => '2026-03-15 09:45:00',
                'team' => 5,
                'label' => 'SideTwo (7)',
            ],
            [
                'start_time' => '2026-03-15 10:00:00',
                'team' => 1,
                'label' => 'Capricorns (12)',
            ],
            [
                'start_time' => '2026-03-15 12:00:00',
                'team' => 8,
                'label' => 'T8 (Noch nicht angemeldet)',
            ],
        ], $payload['matches']);
    }

    private function bindRoles(array $roles): void
    {
        $fetcher = Mockery::mock(RoleFetcherService::class);
        $fetcher->shouldReceive('fetchRoles')->andReturn(collect($roles));
        $this->app->instance(RoleFetcherService::class, $fetcher);
    }

    private function roleRow(
        int $id,
        int $publicPlan,
        string $name,
        ?string $groupLabel = null,
        ?string $differentiationParameter = 'lane',
        int $firstProgram = 3,
    ): object {
        $isF8 = $firstProgram === 8;

        return (object) [
            'id' => $id,
            'name' => $name,
            'name_short' => null,
            'first_program' => $firstProgram,
            'first_program_name' => $isF8 ? 'FUTURE_8' : 'Challenge',
            'first_program_sequence' => $isF8 ? 5 : 2,
            'first_program_display_name' => $isF8 ? 'Future 8+' : 'Challenge',
            'color_hex' => $isF8 ? '51bfb4' : 'ed1c24',
            'logo_stem' => $isF8 ? 'fll_future' : 'fll_challenge',
            'logo_white' => $isF8 ? 'future.png' : 'challenge.png',
            'differentiation_parameter' => $differentiationParameter,
            'public_plan' => $publicPlan,
            'group_label' => $groupLabel,
            'sequence' => $isF8 ? 2 : 4,
        ];
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->date('date')->nullable();
            $table->unsignedTinyInteger('days')->default(1);
            $table->string('slug')->nullable();
            $table->boolean('check_in_enabled')->default(false);
            $table->boolean('cockpit_enabled')->default(false);
        });

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('display_name')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('color_hex')->nullable();
            $table->string('logo_stem')->nullable();
            $table->string('logo_white')->nullable();
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

        Schema::create('m_parameter', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('type')->default('integer');
            $table->string('value')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->string('min')->nullable();
            $table->string('max')->nullable();
            $table->string('step')->nullable();
        });

        Schema::create('plan_param_value', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('parameter');
            $table->string('set_value')->nullable();
        });

        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program')->nullable();
            $table->string('name')->nullable();
            $table->string('location')->nullable();
            $table->string('organization')->nullable();
            $table->unsignedInteger('team_number_hot')->nullable();
        });

        Schema::create('room', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
        });

        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('team');
            $table->unsignedInteger('team_number_plan');
            $table->unsignedInteger('room')->nullable();
            $table->boolean('noshow')->default(false);
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
            $table->boolean('public_plan')->default(false);
            $table->boolean('staffable')->default(false);
            $table->string('group_label')->nullable();
        });

        Schema::create('table_event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedTinyInteger('table_number');
            $table->string('table_name')->nullable();
        });

        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('activity_type')->default(0);
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
            $table->unsignedTinyInteger('jury_lane')->nullable();
            $table->unsignedInteger('jury_team')->nullable();
            $table->unsignedTinyInteger('table_1')->nullable();
            $table->unsignedInteger('table_1_team')->nullable();
            $table->unsignedTinyInteger('table_2')->nullable();
            $table->unsignedInteger('table_2_team')->nullable();
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedTinyInteger('explore_group')->nullable();
        });
    }

    private function seedPlan(): void
    {
        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Test Event',
            'level' => 1,
            'date' => '2026-03-15',
            'days' => 1,
            'slug' => 'test',
            'check_in_enabled' => 0,
            'cockpit_enabled' => 0,
        ]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1, 'name' => 'Plan']);
        DB::table('m_first_program')->insert([
            [
                'id' => 3,
                'name' => 'CHALLENGE',
                'display_name' => 'Challenge',
                'sequence' => 2,
                'color_hex' => 'ed1c24',
                'logo_stem' => 'fll_challenge',
                'logo_white' => 'challenge.png',
            ],
        ]);
        DB::table('event_program')->insert(['id' => 1, 'event' => 1, 'first_program' => 3]);
        DB::table('m_parameter')->insert([
            'id' => 50,
            'name' => 'j_lanes',
            'type' => 'integer',
            'value' => '0',
            'first_program' => 3,
        ]);
        DB::table('plan_param_value')->insert([
            'id' => 1,
            'plan' => 1,
            'parameter' => 50,
            'set_value' => '3',
        ]);
    }

    private function attachFuture8(int $teams = 0, int $fields = 0): void
    {
        DB::table('m_first_program')->insert([
            [
                'id' => 8,
                'name' => 'FUTURE_8',
                'display_name' => 'Future 8+',
                'sequence' => 5,
                'color_hex' => '51bfb4',
                'logo_stem' => 'fll_future',
                'logo_white' => 'future.png',
            ],
        ]);
        DB::table('event_program')->insert(['id' => 2, 'event' => 1, 'first_program' => 8]);

        if ($teams > 0) {
            DB::table('m_parameter')->insert([
                'id' => 80,
                'name' => 'f8_teams',
                'type' => 'integer',
                'value' => '0',
                'first_program' => 8,
            ]);
            DB::table('plan_param_value')->insert([
                'id' => 80,
                'plan' => 1,
                'parameter' => 80,
                'set_value' => (string) $teams,
            ]);
        }

        if ($fields > 0) {
            DB::table('m_parameter')->insert([
                'id' => 81,
                'name' => 'f8_fields',
                'type' => 'integer',
                'value' => '0',
                'first_program' => 8,
            ]);
            DB::table('plan_param_value')->insert([
                'id' => 81,
                'plan' => 1,
                'parameter' => 81,
                'set_value' => (string) $fields,
            ]);
        }
    }

    private function seedLaneMeetingCatalog(): void
    {
        DB::table('m_activity_type_detail')->insert([
            [
                'id' => 17,
                'name' => 'Jurygespräch',
                'code' => 'j_with_team',
                'first_program' => 3,
                'activity_type' => 1,
            ],
            [
                'id' => 18,
                'name' => 'Juryberatung',
                'code' => 'j_scoring',
                'first_program' => 3,
                'activity_type' => 1,
            ],
            [
                'id' => 1,
                'name' => 'Explore judging',
                'code' => 'e_with_team',
                'first_program' => 1,
                'activity_type' => 1,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityRow(
        int $id,
        int $group,
        int $atd,
        string $start,
        int $lane,
        ?int $team,
    ): array {
        return [
            'id' => $id,
            'activity_group' => $group,
            'start' => $start,
            'end' => $start,
            'activity_type_detail' => $atd,
            'jury_lane' => $lane,
            'jury_team' => $team,
            'explore_group' => null,
        ];
    }

    private function seedTableMatchCatalog(): void
    {
        DB::table('m_activity_type_detail')->insert([
            [
                'id' => 15,
                'name' => 'Match',
                'code' => 'r_match',
                'first_program' => 3,
                'activity_type' => 1,
            ],
            [
                'id' => 16,
                'name' => 'Robot-Check',
                'code' => 'r_check',
                'first_program' => 3,
                'activity_type' => 1,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function matchRow(
        int $id,
        int $group,
        int $atd,
        string $start,
        int $table1,
        ?int $team1,
        int $table2,
        ?int $team2,
    ): array {
        return [
            'id' => $id,
            'activity_group' => $group,
            'start' => $start,
            'end' => $start,
            'activity_type_detail' => $atd,
            'jury_lane' => null,
            'jury_team' => null,
            'table_1' => $table1,
            'table_1_team' => $team1,
            'table_2' => $table2,
            'table_2_team' => $team2,
            'explore_group' => null,
        ];
    }
}
