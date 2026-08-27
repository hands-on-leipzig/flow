<?php

namespace Tests\Unit;

use App\Core\PolicyBRoundScheduler;
use App\Enums\FirstProgram;
use App\Support\PlanParameter;
use DateTime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Narrow Policy B verification (scheduler constraints + support regress).
 *
 * Manual UI check (not automated): event with Challenge + Future 8+ attached,
 * f8_per_round=0, f8_separate_rooms=0 — generate and confirm R1–3 matches
 * interleave (no r_check), TR parallel, drain when match counts differ.
 */
class PolicyBVerificationTest extends TestCase
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

        foreach ([
            ['id' => 1, 'name' => 'g_plan', 'value' => '1', 'first_program' => null],
            ['id' => 22, 'name' => 'c_teams', 'value' => '8', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 122, 'name' => 'c_mode', 'value' => '0', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 23, 'name' => 'j_lanes', 'value' => '2', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 24, 'name' => 'r_tables', 'value' => '4', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 200, 'name' => 'f8_teams', 'value' => '8', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 201, 'name' => 'f8_mode', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 202, 'name' => 'f8_lanes', 'value' => '2', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 203, 'name' => 'f8_fields', 'value' => '4', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 175, 'name' => 'f8_future_first', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 176, 'name' => 'f8_per_round', 'value' => '1', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 178, 'name' => 'f8_separate_rooms', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
        ] as $row) {
            DB::table('m_parameter')->insert($row);
        }

        DB::table('m_supported_plan')->insert([
            ['first_program' => FirstProgram::CHALLENGE->value, 'teams' => 8, 'lanes' => 2, 'tables' => 4],
            ['first_program' => FirstProgram::FUTURE_8->value, 'teams' => 8, 'lanes' => 2, 'tables' => 4],
        ]);
    }

    public function test_policy_a_still_supported_when_per_round_true(): void
    {
        $this->seedBothOnPlan(perRound: true);
        $result = app(\App\Services\PlanGeneratorService::class)->isSupported(1);
        $this->assertTrue($result['supported']);
        $this->assertTrue((bool) PlanParameter::load(1)->get('f8_per_round'));
    }

    public function test_two_plus_two_zip_ordering_challenge_first(): void
    {
        $scheduler = new PolicyBRoundScheduler;
        $plan = $scheduler->plan(
            $this->matchEntries(2),
            $this->matchEntries(2),
            2,
            2,
            10,
            15,
            5,
            5,
            5,
            false,
            new DateTime('2026-01-01 10:00:00'),
        );

        $programs = array_column($plan['events'], 'program');
        $this->assertSame(['challenge', 'future', 'challenge', 'future'], $programs);

        $times = array_map(fn ($e) => $e['start']->format('H:i'), $plan['events']);
        $this->assertSame(['10:00', '10:05', '10:15', '10:20'], $times);
    }

    public function test_mixed_four_field_wave_then_single_uses_shared_ns(): void
    {
        $scheduler = new PolicyBRoundScheduler;
        // C4 (wave 2) + F2 (single): shared ns between units (not both-two D−ns).
        $plan = $scheduler->plan(
            $this->matchEntries(4),
            $this->matchEntries(2),
            4,
            2,
            10,
            15,
            5,
            5,
            5,
            false,
            new DateTime('2026-01-01 10:00:00'),
        );

        $seq = array_map(
            fn ($e) => [$e['program'], $e['index'], $e['start']->format('H:i')],
            $plan['events']
        );

        $this->assertSame([
            ['challenge', 0, '10:00'],
            ['challenge', 1, '10:05'],
            ['future', 0, '10:10'],
            ['challenge', 2, '10:15'],
            ['challenge', 3, '10:20'],
            ['future', 1, '10:25'],
        ], $seq);
    }

    public function test_protected_match_meta_from_dry_run_starts(): void
    {
        $scheduler = new PolicyBRoundScheduler;
        $plan = $scheduler->plan(
            $this->matchEntries(4),
            $this->matchEntries(4),
            2,
            2,
            10,
            15,
            5,
            5,
            5,
            false,
            new DateTime('2026-01-01 10:00:00'),
        );

        $meta = PolicyBRoundScheduler::withProtectedMatch($plan['meta']['challenge'], 2);
        $this->assertNotNull($meta['protectedMatchStart']);
        $this->assertSame(
            $meta['starts'][2]->format('H:i'),
            $meta['protectedMatchStart']->format('H:i')
        );

        $rT2M = PolicyBRoundScheduler::minutesBetween(
            new DateTime('2026-01-01 10:00:00'),
            $meta['protectedMatchStart']
        );
        $this->assertGreaterThan(0, $rT2M);
    }

    /**
     * @return list<array{round: int, match: int, table_1: int, table_2: int, team_1: int, team_2: int}>
     */
    private function matchEntries(int $count): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = [
                'round' => 1,
                'match' => $i,
                'table_1' => 1,
                'table_2' => 2,
                'team_1' => $i,
                'team_2' => $i + 100,
            ];
        }

        return $out;
    }

    private function seedBothOnPlan(bool $perRound): void
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
            ['plan' => 1, 'parameter' => 178, 'set_value' => '0'],
        ]);
    }
}
