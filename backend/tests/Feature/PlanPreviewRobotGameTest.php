<?php

namespace Tests\Feature;

use App\Enums\FirstProgram;
use App\Http\Middleware\KeycloakJwtMiddleware;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlanPreviewRobotGameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Match-Plan preview tests require sqlite.');
        }

        $this->withoutMiddleware(KeycloakJwtMiddleware::class);
        $this->createSchema();
    }

    public function test_future_preview_includes_all_catalog_rounds(): void
    {
        $this->seedPlan(FirstProgram::FUTURE_8->value, maxRound: 5);
        $this->insertScoringPath(FirstProgram::FUTURE_8->value, maxRound: 5);

        $response = $this->getJson('/api/plans/preview/1/robot-game');

        $response->assertOk();
        $this->assertSame(
            ['Testrunde', 'Runde 1', 'Runde 2', 'Runde 3', 'Runde 4', 'Runde 5'],
            array_column($response->json('rounds'), 'name')
        );
        $this->assertSame([0, 1, 2, 3, 4, 5], array_column($response->json('rounds'), 'round'));

        $teamOne = collect($response->json('team_summary'))->firstWhere('team', 1);
        $this->assertNotNull($teamOne);
        $this->assertSame(5, $teamOne['different_opponents']);
        $this->assertSame(2, $teamOne['different_tables']);
    }

    public function test_challenge_preview_stays_tr_plus_three(): void
    {
        $this->seedPlan(FirstProgram::CHALLENGE->value, maxRound: 3);
        $this->insertScoringPath(FirstProgram::CHALLENGE->value, maxRound: 3);

        $response = $this->getJson('/api/plans/preview/1/robot-game');

        $response->assertOk();
        $this->assertSame(
            ['Testrunde', 'Runde 1', 'Runde 2', 'Runde 3'],
            array_column($response->json('rounds'), 'name')
        );
        $this->assertSame([0, 1, 2, 3], array_column($response->json('rounds'), 'round'));

        $teamOne = collect($response->json('team_summary'))->firstWhere('team', 1);
        $this->assertNotNull($teamOne);
        $this->assertSame(3, $teamOne['different_opponents']);
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event');
        });

        Schema::create('event', function (Blueprint $table) {
            $table->id();
            $table->date('date')->nullable();
            $table->integer('level')->default(1);
            $table->integer('days')->default(1);
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

        Schema::create('match', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('round');
            $table->unsignedInteger('match_no');
            $table->unsignedInteger('table_1');
            $table->unsignedInteger('table_2');
            $table->unsignedInteger('table_1_team');
            $table->unsignedInteger('table_2_team');
        });

        Schema::create('table_event', function (Blueprint $table) {
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('table_number');
            $table->string('table_name')->nullable();
        });
    }

    private function seedPlan(int $firstProgram, int $maxRound): void
    {
        $isFuture = $firstProgram === FirstProgram::FUTURE_8->value;

        DB::table('m_first_program')->insert([
            ['id' => FirstProgram::CHALLENGE->value, 'sequence' => 2],
            ['id' => FirstProgram::FUTURE_8->value, 'sequence' => 5],
        ]);

        DB::table('m_parameter')->insert([
            ['id' => 22, 'name' => 'c_teams', 'value' => '0', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 122, 'name' => 'c_mode', 'value' => '0', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 23, 'name' => 'r_tables', 'value' => '2', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 200, 'name' => 'f8_teams', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 201, 'name' => 'f8_mode', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 202, 'name' => 'f8_fields', 'value' => '2', 'first_program' => FirstProgram::FUTURE_8->value],
        ]);

        DB::table('event')->insert(['id' => 1, 'date' => '2026-01-01', 'level' => 1, 'days' => 1]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => $firstProgram],
        ]);

        $teamsParam = $isFuture ? 200 : 22;
        $modeParam = $isFuture ? 201 : 122;
        DB::table('plan_param_value')->insert([
            ['plan' => 1, 'parameter' => $teamsParam, 'set_value' => (string) ($maxRound + 2)],
            ['plan' => 1, 'parameter' => $modeParam, 'set_value' => '1'],
        ]);
    }

    private function insertScoringPath(int $firstProgram, int $maxRound): void
    {
        $rows = [];
        for ($round = 0; $round <= $maxRound; $round++) {
            $opponent = $round === 0 ? 2 : $round + 2;
            $rows[] = [
                'plan' => 1,
                'first_program' => $firstProgram,
                'round' => $round,
                'match_no' => 1,
                'table_1' => $round % 2 === 0 ? 1 : 2,
                'table_2' => $round % 2 === 0 ? 2 : 1,
                'table_1_team' => 1,
                'table_2_team' => $opponent,
            ];
        }

        DB::table('match')->insert($rows);
    }
}
