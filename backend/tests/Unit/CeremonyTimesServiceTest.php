<?php

namespace Tests\Unit;

use App\Enums\ExploreMode;
use App\Enums\FirstProgram;
use App\Services\CeremonyTimesService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CeremonyTimesServiceTest extends TestCase
{
    private CeremonyTimesService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('CeremonyTimesService tests require sqlite.');
        }

        $this->createSchema();
        $this->service = new CeremonyTimesService;
    }

    public function test_empty_catalog_returns_incomplete_payload(): void
    {
        $planId = $this->seedPlan();

        $payload = $this->service->forPlan($planId);

        $this->assertTrue($payload['catalog_incomplete']);
        $this->assertSame(CeremonyTimesService::CATALOG_INCOMPLETE_MESSAGE, $payload['error']);
        $this->assertSame([], $payload['ceremonies']);
    }

    public function test_missing_catalog_row_for_plan_ceremony_returns_incomplete(): void
    {
        $planId = $this->seedPlan();
        $this->seedCeremonyCatalog(includeE2Opening: false);
        $this->insertCeremonyActivity($planId, 205, '2026-03-15 14:00:00', '2026-03-15 14:30:00');

        $payload = $this->service->forPlan($planId);

        $this->assertTrue($payload['catalog_incomplete']);
    }

    public function test_returns_ceremonies_sorted_by_start(): void
    {
        $planId = $this->seedPlan(eMode: ExploreMode::DECOUPLED_BOTH->value);
        $this->seedCeremonyCatalog();
        $this->seedPlanParams($planId, eMode: ExploreMode::DECOUPLED_BOTH->value);
        $this->insertCeremonyActivity($planId, 204, '2026-03-15 11:00:00', '2026-03-15 11:45:00', exploreGroup: 1);
        $this->insertCeremonyActivity($planId, 201, '2026-03-15 09:00:00', '2026-03-15 09:30:00');

        $payload = $this->service->forPlan($planId);

        $this->assertFalse($payload['catalog_incomplete']);
        $this->assertCount(2, $payload['ceremonies']);
        $this->assertSame('c_opening', $payload['ceremonies'][0]['code']);
        $this->assertSame('e1_opening', $payload['ceremonies'][1]['code']);
    }

    public function test_decoupled_challenge_opening_start_is_editable(): void
    {
        $planId = $this->seedPlan();
        $this->seedCeremonyCatalog();
        $this->seedPlanParams($planId);
        $this->insertCeremonyActivity($planId, 201, '2026-03-15 09:00:00', '2026-03-15 09:30:00');

        $payload = $this->service->forPlan($planId);
        $opening = $payload['ceremonies'][0];

        $this->assertSame('opening', $opening['kind']);
        $this->assertTrue($opening['start_editable']);
        $this->assertSame(1001, $opening['start_parameter_id']);
        $this->assertSame(30, $opening['duration_value']);
    }

    public function test_awards_start_is_never_editable(): void
    {
        $planId = $this->seedPlan();
        $this->seedCeremonyCatalog();
        $this->seedPlanParams($planId);
        $this->insertCeremonyActivity($planId, 203, '2026-03-15 16:00:00', '2026-03-15 16:45:00');

        $payload = $this->service->forPlan($planId);
        $awards = $payload['ceremonies'][0];

        $this->assertSame('awards', $awards['kind']);
        $this->assertFalse($awards['start_editable']);
        $this->assertNull($awards['start_parameter_id']);
        $this->assertSame(45, $awards['duration_value']);
    }

    public function test_integrated_afternoon_e2_opening_start_is_read_only(): void
    {
        $planId = $this->seedPlan(eMode: ExploreMode::INTEGRATED_AFTERNOON->value, withExplore: true);
        $this->seedCeremonyCatalog();
        $this->seedPlanParams($planId, eMode: ExploreMode::INTEGRATED_AFTERNOON->value);
        $this->insertCeremonyActivity($planId, 205, '2026-03-15 13:00:00', '2026-03-15 13:30:00', exploreGroup: 2);

        $payload = $this->service->forPlan($planId);
        $opening = $payload['ceremonies'][0];

        $this->assertSame('e2_opening', $opening['code']);
        $this->assertFalse($opening['start_editable']);
    }

    public function test_joint_ceremony_lists_all_attached_programs(): void
    {
        $planId = $this->seedPlan(withExplore: true);
        $this->seedCeremonyCatalog();
        $this->seedPlanParams($planId);
        $this->insertCeremonyActivity($planId, 202, '2026-03-15 08:30:00', '2026-03-15 09:00:00');

        $payload = $this->service->forPlan($planId);
        $opening = $payload['ceremonies'][0];

        $this->assertSame('g_opening', $opening['code']);
        $this->assertCount(2, $opening['programs']);
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('event', function (Blueprint $table) {
            $table->id();
            $table->date('date')->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->unsignedTinyInteger('days')->default(1);
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event');
            $table->timestamp('last_change')->nullable();
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event');
            $table->unsignedInteger('first_program');
        });

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->integer('sequence');
            $table->string('color_hex')->nullable();
        });

        Schema::create('m_parameter', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('string');
            $table->string('value')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->string('min')->nullable();
            $table->string('max')->nullable();
            $table->unsignedInteger('step')->nullable();
        });

        Schema::create('plan_param_value', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan');
            $table->unsignedInteger('parameter');
            $table->string('set_value')->nullable();
        });

        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('first_program')->nullable();
        });

        Schema::create('m_ceremonies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('activity_type_detail');
            $table->string('kind');
            $table->unsignedInteger('start_parameter')->nullable();
            $table->unsignedInteger('duration_parameter');
        });

        Schema::create('activity_group', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan');
        });

        Schema::create('activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_group');
            $table->unsignedBigInteger('activity_type_detail');
            $table->dateTime('start')->nullable();
            $table->dateTime('end')->nullable();
            $table->unsignedTinyInteger('explore_group')->nullable();
        });

        DB::table('m_first_program')->insert([
            ['id' => FirstProgram::EXPLORE->value, 'name' => 'EXPLORE', 'display_name' => 'Explore', 'sequence' => 1, 'color_hex' => '00A651'],
            ['id' => FirstProgram::CHALLENGE->value, 'name' => 'CHALLENGE', 'display_name' => 'Challenge', 'sequence' => 2, 'color_hex' => 'ED1C24'],
        ]);
    }

    private function seedPlan(int $eMode = 0, bool $withExplore = false): int
    {
        DB::table('event')->insert(['id' => 1, 'date' => '2026-03-15', 'level' => 1, 'days' => 1]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1, 'last_change' => now()]);
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => FirstProgram::CHALLENGE->value],
        ]);
        if ($withExplore) {
            DB::table('event_program')->insert([
                ['event' => 1, 'first_program' => FirstProgram::EXPLORE->value],
            ]);
        }

        return 1;
    }

    private function seedPlanParams(int $planId, int $eMode = 0, int $cMode = 1, int $f8Mode = 0): void
    {
        $params = [
            'e_mode' => (string) $eMode,
            'c_mode' => (string) $cMode,
            'f8_mode' => (string) $f8Mode,
            'c_teams' => '12',
            'c_start_opening' => '09:00',
            'c_duration_opening' => '30',
            'c_duration_awards' => '45',
            'g_start_opening' => '08:30',
            'g_duration_opening' => '30',
            'g_duration_awards' => '45',
            'e1_start_opening' => '10:00',
            'e1_duration_opening' => '30',
            'e1_duration_awards' => '30',
            'e2_start_opening' => '13:00',
            'e2_duration_opening' => '30',
            'e2_duration_awards' => '30',
        ];

        foreach ($params as $name => $value) {
            $paramId = DB::table('m_parameter')->where('name', $name)->value('id');
            if ($paramId === null) {
                continue;
            }
            DB::table('plan_param_value')->insert([
                'plan' => $planId,
                'parameter' => $paramId,
                'set_value' => $value,
            ]);
        }
    }

    private function seedCeremonyCatalog(bool $includeE2Opening = true): void
    {
        DB::table('m_activity_type_detail')->insert([
            ['id' => 201, 'code' => 'c_opening', 'name' => 'Challenge opening', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 202, 'code' => 'g_opening', 'name' => 'Joint opening', 'first_program' => FirstProgram::JOINT->value],
            ['id' => 203, 'code' => 'c_awards', 'name' => 'Challenge awards', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 204, 'code' => 'e1_opening', 'name' => 'Explore 1 opening', 'first_program' => FirstProgram::EXPLORE->value],
            ['id' => 205, 'code' => 'e2_opening', 'name' => 'Explore 2 opening', 'first_program' => FirstProgram::EXPLORE->value],
        ]);

        DB::table('m_parameter')->insert([
            ['id' => 900, 'name' => 'e_mode', 'type' => 'integer', 'value' => '0', 'first_program' => null, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 901, 'name' => 'c_mode', 'type' => 'integer', 'value' => '1', 'first_program' => FirstProgram::CHALLENGE->value, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 902, 'name' => 'f8_mode', 'type' => 'integer', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 903, 'name' => 'c_teams', 'type' => 'integer', 'value' => '12', 'first_program' => FirstProgram::CHALLENGE->value, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1001, 'name' => 'c_start_opening', 'type' => 'time', 'value' => '09:00', 'first_program' => FirstProgram::CHALLENGE->value, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1002, 'name' => 'c_duration_opening', 'type' => 'integer', 'value' => '30', 'first_program' => FirstProgram::CHALLENGE->value, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1003, 'name' => 'c_duration_awards', 'type' => 'integer', 'value' => '45', 'first_program' => FirstProgram::CHALLENGE->value, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1004, 'name' => 'g_start_opening', 'type' => 'time', 'value' => '08:30', 'first_program' => null, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1005, 'name' => 'g_duration_opening', 'type' => 'integer', 'value' => '30', 'first_program' => null, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1006, 'name' => 'g_duration_awards', 'type' => 'integer', 'value' => '45', 'first_program' => null, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1007, 'name' => 'e1_start_opening', 'type' => 'time', 'value' => '10:00', 'first_program' => FirstProgram::EXPLORE->value, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1008, 'name' => 'e1_duration_opening', 'type' => 'integer', 'value' => '30', 'first_program' => FirstProgram::EXPLORE->value, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1009, 'name' => 'e1_duration_awards', 'type' => 'integer', 'value' => '30', 'first_program' => FirstProgram::EXPLORE->value, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1010, 'name' => 'e2_start_opening', 'type' => 'time', 'value' => '13:00', 'first_program' => FirstProgram::EXPLORE->value, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1011, 'name' => 'e2_duration_opening', 'type' => 'integer', 'value' => '30', 'first_program' => FirstProgram::EXPLORE->value, 'min' => null, 'max' => null, 'step' => null],
            ['id' => 1012, 'name' => 'e2_duration_awards', 'type' => 'integer', 'value' => '30', 'first_program' => FirstProgram::EXPLORE->value, 'min' => null, 'max' => null, 'step' => null],
        ]);

        $ceremonies = [
            ['activity_type_detail' => 201, 'kind' => 'opening', 'start_parameter' => 1001, 'duration_parameter' => 1002],
            ['activity_type_detail' => 202, 'kind' => 'opening', 'start_parameter' => 1004, 'duration_parameter' => 1005],
            ['activity_type_detail' => 203, 'kind' => 'awards', 'start_parameter' => null, 'duration_parameter' => 1003],
            ['activity_type_detail' => 204, 'kind' => 'opening', 'start_parameter' => 1007, 'duration_parameter' => 1008],
        ];
        if ($includeE2Opening) {
            $ceremonies[] = ['activity_type_detail' => 205, 'kind' => 'opening', 'start_parameter' => 1010, 'duration_parameter' => 1011];
        }
        DB::table('m_ceremonies')->insert($ceremonies);
    }

    private function insertCeremonyActivity(
        int $planId,
        int $atdId,
        string $start,
        string $end,
        ?int $exploreGroup = null,
    ): void {
        $groupId = DB::table('activity_group')->insertGetId(['plan' => $planId]);
        DB::table('activity')->insert([
            'activity_group' => $groupId,
            'activity_type_detail' => $atdId,
            'start' => $start,
            'end' => $end,
            'explore_group' => $exploreGroup,
        ]);
    }
}
