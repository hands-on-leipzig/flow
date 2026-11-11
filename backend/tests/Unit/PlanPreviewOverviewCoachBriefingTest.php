<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Http\Controllers\Api\PlanExportController;
use App\Services\ActivityFetcherService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlanPreviewOverviewCoachBriefingTest extends TestCase
{
    private const JUROR_ROLE = 4;

    private const JURY_ATD = 36;

    private const COACH_ATD = 35;

    private const F8_COACH_ATD = 80;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Coach briefing Überblick tests require sqlite.');
        }

        $this->createSchema();
        $this->seedCatalog();
    }

    public function test_fetcher_omits_coach_briefing_for_juror_without_extra_codes(): void
    {
        $this->insertBriefingPair();

        $codes = app(ActivityFetcherService::class)
            ->fetchActivities(plan: 1, roles: [self::JUROR_ROLE], includeActivityMeta: true)
            ->pluck('activity_type_code')
            ->all();

        $this->assertContains('j_briefing', $codes);
        $this->assertNotContains('c_briefing', $codes);
    }

    public function test_fetcher_includes_coach_briefing_when_code_is_opted_in(): void
    {
        $this->insertBriefingPair();

        $codes = app(ActivityFetcherService::class)
            ->fetchActivities(
                plan: 1,
                roles: [self::JUROR_ROLE],
                includeActivityMeta: true,
                alsoAtdCodes: ['c_briefing', 'e_briefing_coach', 'f8_briefing'],
            )
            ->pluck('activity_type_code')
            ->all();

        $this->assertContains('j_briefing', $codes);
        $this->assertContains('c_briefing', $codes);
    }

    public function test_overview_places_challenge_coach_briefing_in_allgemein_3(): void
    {
        $this->insertBriefingPair();

        $events = $this->overviewEvents(['c_briefing', 'e_briefing_coach', 'f8_briefing']);

        $this->assertSame('Allgemein-3', $events['Coaches-Briefing']['assigned_column'] ?? null);
        $this->assertSame('Challenge', $events['Jury-Briefing']['assigned_column'] ?? null);
    }

    public function test_overview_places_future_coach_briefing_in_allgemein_4(): void
    {
        $this->insertFutureCoachBriefing();

        $events = $this->overviewEvents(['c_briefing', 'e_briefing_coach', 'f8_briefing']);

        $this->assertSame('Allgemein-4', $events['Coaches-Briefing']['assigned_column'] ?? null);
    }

    public function test_overview_without_opt_in_does_not_show_coach_briefing(): void
    {
        $this->insertBriefingPair();

        $events = $this->overviewEvents([]);

        $this->assertArrayNotHasKey('Coaches-Briefing', $events);
        $this->assertArrayHasKey('Jury-Briefing', $events);
    }

    /**
     * @param  list<string>  $alsoAtdCodes
     * @return array<string, array<string, mixed>>
     */
    private function overviewEvents(array $alsoAtdCodes): array
    {
        $data = app(PlanExportController::class)->getEventOverviewData(1, [self::JUROR_ROLE], false, $alsoAtdCodes);
        $byName = [];
        foreach ($data['eventsByDay'] as $day) {
            foreach ($day['events'] as $event) {
                $byName[$event['group_name']] = $event;
            }
        }

        return $byName;
    }

    private function insertBriefingPair(): void
    {
        DB::table('activity_group')->insert([
            ['id' => 1, 'plan' => 1, 'activity_type_detail' => self::JURY_ATD],
            ['id' => 2, 'plan' => 1, 'activity_type_detail' => self::COACH_ATD],
        ]);
        DB::table('activity')->insert([
            $this->activityRow(1, 1, self::JURY_ATD, '2026-03-15 08:00:00', '2026-03-15 08:15:00'),
            $this->activityRow(2, 2, self::COACH_ATD, '2026-03-15 08:00:00', '2026-03-15 08:20:00'),
        ]);
        DB::table('m_visibility')->insert([
            ['activity_type_detail' => self::JURY_ATD, 'role' => self::JUROR_ROLE],
            ['activity_type_detail' => self::COACH_ATD, 'role' => 3],
        ]);
    }

    private function insertFutureCoachBriefing(): void
    {
        DB::table('activity_group')->insert([
            ['id' => 1, 'plan' => 1, 'activity_type_detail' => self::JURY_ATD],
            ['id' => 3, 'plan' => 1, 'activity_type_detail' => self::F8_COACH_ATD],
        ]);
        DB::table('activity')->insert([
            $this->activityRow(1, 1, self::JURY_ATD, '2026-03-15 08:00:00', '2026-03-15 08:15:00'),
            $this->activityRow(3, 3, self::F8_COACH_ATD, '2026-03-15 08:00:00', '2026-03-15 08:20:00'),
        ]);
        DB::table('m_visibility')->insert([
            ['activity_type_detail' => self::JURY_ATD, 'role' => self::JUROR_ROLE],
            ['activity_type_detail' => self::F8_COACH_ATD, 'role' => 21],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityRow(int $id, int $group, int $atd, string $start, string $end): array
    {
        return [
            'id' => $id,
            'activity_group' => $group,
            'activity_type_detail' => $atd,
            'start' => $start,
            'end' => $end,
            'jury_lane' => null,
            'jury_team' => null,
            'table_1' => null,
            'table_1_team' => null,
            'table_2' => null,
            'table_2_team' => null,
            'extra_block' => null,
            'slot_team' => null,
        ];
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
            $table->string('official_name')->nullable();
        });

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->date('date')->nullable();
            $table->unsignedTinyInteger('days')->default(1);
            $table->unsignedInteger('level')->default(1);
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
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

        Schema::create('m_activity_type', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('overview_plan_column')->nullable();
            $table->unsignedInteger('first_program')->nullable();
        });

        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('name_preview')->nullable();
            $table->string('presence')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('activity_type')->nullable();
            $table->unsignedInteger('role')->nullable();
        });

        Schema::create('m_visibility', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedInteger('role');
        });

        Schema::create('extra_block', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan')->nullable();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('room')->nullable();
        });

        Schema::create('activity_group', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedInteger('plan');
            $table->unsignedInteger('explore_group')->nullable();
        });

        Schema::create('activity', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('activity_group');
            $table->datetime('start');
            $table->datetime('end');
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedInteger('room_type')->nullable();
            $table->unsignedTinyInteger('jury_lane')->nullable();
            $table->unsignedInteger('jury_team')->nullable();
            $table->unsignedTinyInteger('table_1')->nullable();
            $table->unsignedInteger('table_1_team')->nullable();
            $table->unsignedTinyInteger('table_2')->nullable();
            $table->unsignedInteger('table_2_team')->nullable();
            $table->unsignedInteger('extra_block')->nullable();
            $table->unsignedInteger('slot_team')->nullable();
        });

        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('plan');
            $table->unsignedInteger('team_number_plan');
            $table->unsignedInteger('team')->nullable();
        });

        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
        });

        Schema::create('table_event', function (Blueprint $table) {
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('table_number');
            $table->string('table_name')->nullable();
        });
    }

    private function seedCatalog(): void
    {
        DB::table('m_first_program')->insert([
            ['id' => FirstProgram::EXPLORE->value, 'name' => 'Explore', 'display_name' => 'Explore', 'sequence' => 1],
            ['id' => FirstProgram::CHALLENGE->value, 'name' => 'Challenge', 'display_name' => 'Challenge', 'sequence' => 2],
            ['id' => FirstProgram::FUTURE_8->value, 'name' => 'FUTURE_8', 'display_name' => 'Future 8+', 'sequence' => 5],
        ]);

        DB::table('event')->insert(['id' => 1, 'date' => '2026-03-15', 'days' => 1, 'level' => 1]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => FirstProgram::CHALLENGE->value],
            ['event' => 1, 'first_program' => FirstProgram::FUTURE_8->value],
        ]);

        DB::table('m_activity_type')->insert([
            ['id' => 2, 'name' => 'Jury', 'overview_plan_column' => 'Challenge', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 7, 'name' => 'Orga', 'overview_plan_column' => '', 'first_program' => null],
        ]);

        DB::table('m_activity_type_detail')->insert([
            [
                'id' => self::JURY_ATD,
                'name' => 'Jury-Briefing',
                'code' => 'j_briefing',
                'name_preview' => 'Briefing',
                'first_program' => FirstProgram::CHALLENGE->value,
                'activity_type' => 2,
                'role' => 27,
            ],
            [
                'id' => self::COACH_ATD,
                'name' => 'Coaches-Briefing',
                'code' => 'c_briefing',
                'name_preview' => 'Briefing',
                'first_program' => FirstProgram::CHALLENGE->value,
                'activity_type' => 7,
                'role' => 31,
            ],
            [
                'id' => self::F8_COACH_ATD,
                'name' => 'Coaches-Briefing',
                'code' => 'f8_briefing',
                'name_preview' => 'Briefing',
                'first_program' => FirstProgram::FUTURE_8->value,
                'activity_type' => 7,
                'role' => 31,
            ],
        ]);
    }
}
