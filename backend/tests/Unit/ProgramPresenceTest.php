<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProgramPresenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('ProgramPresence tests require sqlite.');
        }

        $this->createSchema();
    }

    public function test_purge_removes_off_event_program_overrides(): void
    {
        $planId = $this->seedChallengeOnlyPlan();

        $deleted = ProgramPresence::purgeParametersOutsideEvent($planId);

        $this->assertGreaterThan(0, $deleted);
        $this->assertDatabaseMissing('plan_param_value', [
            'plan' => $planId,
            'parameter' => 7,
        ]);
    }

    public function test_plan_parameter_loads_attached_programs_only(): void
    {
        $planId = $this->seedChallengeOnlyPlan();

        ProgramPresence::purgeParametersOutsideEvent($planId);
        $params = PlanParameter::load($planId);

        $this->assertFalse($params->has('e_mode'));
        $this->assertTrue($params->has('c_mode'));
        $this->assertFalse($params->has('f8_mode'));
    }

    public function test_challenge_shaped_on_requires_mode_and_teams(): void
    {
        $planId = $this->seedChallengeOnlyPlan();
        $params = PlanParameter::load($planId);
        $presence = ProgramPresence::forPlan($planId, $params);

        $this->assertTrue($presence->challengeShapedOn(FirstProgram::CHALLENGE->value));
        $this->assertFalse($presence->challengeShapedOn(FirstProgram::FUTURE_8->value));
    }

    public function test_lead_priority_prefers_challenge_over_future(): void
    {
        $planId = $this->seedBothLeadsPlan();
        $params = PlanParameter::load($planId);
        $presence = ProgramPresence::forPlan($planId, $params);

        $this->assertSame(FirstProgram::CHALLENGE->value, $presence->leadProgramId());
        $this->assertSame([FirstProgram::FUTURE_8->value], $presence->skippedLeadProgramIds());
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

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

        Schema::create('event', function (Blueprint $table) {
            $table->id();
            $table->date('date')->nullable();
            $table->integer('level')->default(1);
            $table->integer('days')->default(1);
        });
    }

    private function seedCatalogParameters(): void
    {
        DB::table('m_first_program')->insert([
            ['id' => FirstProgram::EXPLORE->value, 'sequence' => 1],
            ['id' => FirstProgram::CHALLENGE->value, 'sequence' => 2],
            ['id' => FirstProgram::FUTURE_8->value, 'sequence' => 5],
        ]);

        $rows = [
            ['id' => 1, 'name' => 'g_plan', 'value' => '1', 'first_program' => null],
            ['id' => 6, 'name' => 'e_teams', 'value' => '0', 'first_program' => FirstProgram::EXPLORE->value],
            ['id' => 7, 'name' => 'e_mode', 'value' => '0', 'first_program' => FirstProgram::EXPLORE->value],
            ['id' => 22, 'name' => 'c_teams', 'value' => '8', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 122, 'name' => 'c_mode', 'value' => '0', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 200, 'name' => 'f8_teams', 'value' => '8', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 201, 'name' => 'f8_mode', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
        ];

        foreach ($rows as $row) {
            DB::table('m_parameter')->insert($row);
        }
    }

    private function seedChallengeOnlyPlan(): int
    {
        $this->seedCatalogParameters();

        DB::table('event')->insert(['id' => 1, 'date' => '2026-01-01']);
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => FirstProgram::CHALLENGE->value],
        ]);

        DB::table('plan_param_value')->insert([
            ['plan' => 1, 'parameter' => 7, 'set_value' => '1'],
            ['plan' => 1, 'parameter' => 122, 'set_value' => '1'],
            ['plan' => 1, 'parameter' => 22, 'set_value' => '8'],
            ['plan' => 1, 'parameter' => 201, 'set_value' => '1'],
        ]);

        return 1;
    }

    private function seedBothLeadsPlan(): int
    {
        $this->seedCatalogParameters();

        DB::table('event')->insert(['id' => 2, 'date' => '2026-01-02']);
        DB::table('plan')->insert(['id' => 2, 'event' => 2]);
        DB::table('event_program')->insert([
            ['event' => 2, 'first_program' => FirstProgram::CHALLENGE->value],
            ['event' => 2, 'first_program' => FirstProgram::FUTURE_8->value],
        ]);

        DB::table('plan_param_value')->insert([
            ['plan' => 2, 'parameter' => 122, 'set_value' => '1'],
            ['plan' => 2, 'parameter' => 22, 'set_value' => '8'],
            ['plan' => 2, 'parameter' => 201, 'set_value' => '1'],
            ['plan' => 2, 'parameter' => 200, 'set_value' => '8'],
        ]);

        return 2;
    }
}
