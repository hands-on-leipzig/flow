<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DualChallengeShapedSupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Requires sqlite.');
        }

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

        Schema::create('m_supported_plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('teams');
            $table->unsignedInteger('lanes');
            $table->unsignedInteger('tables')->nullable();
        });

        DB::table('m_first_program')->insert([
            ['id' => FirstProgram::EXPLORE->value, 'sequence' => 1],
            ['id' => FirstProgram::CHALLENGE->value, 'sequence' => 2],
            ['id' => FirstProgram::FUTURE_8->value, 'sequence' => 5],
        ]);

        $params = [
            ['id' => 1, 'name' => 'g_plan', 'value' => '1', 'first_program' => null],
            ['id' => 22, 'name' => 'c_teams', 'value' => '8', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 122, 'name' => 'c_mode', 'value' => '0', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 23, 'name' => 'j_lanes', 'value' => '2', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 24, 'name' => 'r_tables', 'value' => '4', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 200, 'name' => 'f8_teams', 'value' => '8', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 201, 'name' => 'f8_mode', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 202, 'name' => 'f8_lanes', 'value' => '2', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 203, 'name' => 'f8_fields', 'value' => '4', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 175, 'name' => 'g_future_first', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 176, 'name' => 'g_per_round', 'value' => '1', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 178, 'name' => 'g_separate_rooms', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
        ];
        foreach ($params as $row) {
            DB::table('m_parameter')->insert($row);
        }

        DB::table('m_supported_plan')->insert([
            ['first_program' => FirstProgram::CHALLENGE->value, 'teams' => 8, 'lanes' => 2, 'tables' => 4],
            ['first_program' => FirstProgram::FUTURE_8->value, 'teams' => 8, 'lanes' => 2, 'tables' => 4],
        ]);
    }

    public function test_presence_lists_both_challenge_shaped_on(): void
    {
        $planId = $this->seedBothOnPlan(perRound: true);

        $params = PlanParameter::load($planId);
        $presence = ProgramPresence::forPlan($planId, $params);

        $this->assertSame(
            [FirstProgram::CHALLENGE->value, FirstProgram::FUTURE_8->value],
            $presence->challengeShapedOnIds()
        );
        $this->assertSame(FirstProgram::CHALLENGE->value, $presence->leadProgramId());
        $this->assertSame([FirstProgram::FUTURE_8->value], $presence->skippedLeadProgramIds());
    }

    public function test_sync_turns_on_mode_when_attached_with_teams(): void
    {
        $planId = $this->seedBothOnPlan(perRound: true);

        // Simulate stale plan: teams set, mode left at catalog 0 / missing override.
        DB::table('plan_param_value')->where('plan', $planId)->where('parameter', 201)->delete();

        $params = PlanParameter::load($planId);
        $presence = ProgramPresence::forPlan($planId, $params);

        $this->assertTrue($presence->challengeShapedOn(FirstProgram::FUTURE_8->value));
        $this->assertSame(
            [FirstProgram::CHALLENGE->value, FirstProgram::FUTURE_8->value],
            $presence->challengeShapedOnIds()
        );
        $this->assertSame(1, (int) $params->get('f8_mode'));
    }

    public function test_is_supported_accepts_policy_b_when_both_on(): void
    {
        $planId = $this->seedBothOnPlan(perRound: false, separateRooms: false);

        $result = app(\App\Services\PlanGeneratorService::class)->isSupported($planId);

        $this->assertTrue($result['supported']);
    }

    public function test_is_supported_accepts_policy_a_when_both_on(): void
    {
        $planId = $this->seedBothOnPlan(perRound: true, separateRooms: false);

        $result = app(\App\Services\PlanGeneratorService::class)->isSupported($planId);

        $this->assertTrue($result['supported']);
    }

    public function test_is_supported_rejects_policy_c_separate_rooms(): void
    {
        $planId = $this->seedBothOnPlan(perRound: true, separateRooms: true);

        $result = app(\App\Services\PlanGeneratorService::class)->isSupported($planId);

        $this->assertFalse($result['supported']);
        $this->assertStringContainsString('g_separate_rooms', $result['details']);
    }

    public function test_game_round_mapping_four_judging_rounds(): void
    {
        // Mirrors ChallengeGenerator / Future8Generator::gameRoundForJudgingBlock (non-finale).
        $mapFour = static fn (int $cBlock): ?int => match ($cBlock) {
            1 => 0,
            2 => 1,
            3 => 2,
            4 => 3,
            5 => 3,
            default => null,
        };

        $mapFive = static fn (int $cBlock, int $jRounds): ?int => match ($cBlock) {
            1 => 0,
            2 => $jRounds == 4 ? 1 : null,
            3 => $jRounds == 4 ? 2 : 1,
            4 => $jRounds == 4 ? 3 : 2,
            5 => 3,
            default => null,
        };

        $this->assertSame(0, $mapFour(1));
        $this->assertSame(1, $mapFour(2));
        $this->assertSame(2, $mapFour(3));
        $this->assertSame(3, $mapFour(4));

        $this->assertSame(0, $mapFive(1, 5));
        $this->assertNull($mapFive(2, 5));
        $this->assertSame(1, $mapFive(3, 5));
        $this->assertSame(2, $mapFive(4, 5));
        $this->assertSame(3, $mapFive(5, 5));
    }

    private function seedBothOnPlan(bool $perRound, bool $separateRooms = false): int
    {
        DB::table('event')->insert(['id' => 1, 'date' => '2026-01-01']);
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => FirstProgram::CHALLENGE->value],
            ['event' => 1, 'first_program' => FirstProgram::FUTURE_8->value],
        ]);

        DB::table('plan_param_value')->insert([
            ['plan' => 1, 'parameter' => 122, 'set_value' => '1'],
            ['plan' => 1, 'parameter' => 22, 'set_value' => '8'],
            ['plan' => 1, 'parameter' => 23, 'set_value' => '2'],
            ['plan' => 1, 'parameter' => 24, 'set_value' => '4'],
            ['plan' => 1, 'parameter' => 201, 'set_value' => '1'],
            ['plan' => 1, 'parameter' => 200, 'set_value' => '8'],
            ['plan' => 1, 'parameter' => 202, 'set_value' => '2'],
            ['plan' => 1, 'parameter' => 203, 'set_value' => '4'],
            ['plan' => 1, 'parameter' => 175, 'set_value' => '0'],
            ['plan' => 1, 'parameter' => 176, 'set_value' => $perRound ? '1' : '0'],
            ['plan' => 1, 'parameter' => 178, 'set_value' => $separateRooms ? '1' : '0'],
        ]);

        return 1;
    }
}
