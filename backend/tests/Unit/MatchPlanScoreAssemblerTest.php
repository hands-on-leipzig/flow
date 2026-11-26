<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Http\Controllers\Api\PlanExportController;
use App\Print\MatchPlanScoreAssembler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FLOW-1628: Challenge and Future 8+ share the match table per plan.
 * Match-Plan SCORE must only list Challenge matches.
 */
class MatchPlanScoreAssemblerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Match-plan-score tests require sqlite.');
        }

        Schema::dropAllTables();
        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('level')->default(0);
        });
        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
        });
        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->unsignedInteger('sequence')->default(0);
        });
        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
        });
        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('team_number_hot')->nullable();
        });
        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('team');
            $table->unsignedInteger('plan');
            $table->unsignedInteger('team_number_plan');
            $table->boolean('noshow')->nullable();
        });
        Schema::create('match', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('plan');
            $table->unsignedInteger('first_program');
            $table->integer('round');
            $table->integer('match_no');
            $table->integer('table_1');
            $table->integer('table_2');
            $table->integer('table_1_team');
            $table->integer('table_2_team');
        });

        $challenge = FirstProgram::CHALLENGE->value;
        $future = FirstProgram::FUTURE_8->value;

        DB::table('event')->insert(['id' => 1, 'name' => 'Chur', 'level' => 1]);
        DB::table('plan')->insert(['id' => 10, 'event' => 1]);
        DB::table('m_first_program')->insert([
            ['id' => $challenge, 'name' => 'CHALLENGE', 'display_name' => 'Challenge', 'sequence' => 2],
            ['id' => $future, 'name' => 'FUTURE_8', 'display_name' => 'Future 8+', 'sequence' => 3],
        ]);
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => $challenge],
            ['event' => 1, 'first_program' => $future],
        ]);
        DB::table('team')->insert([
            ['id' => 101, 'name' => 'C-Eins', 'first_program' => $challenge, 'team_number_hot' => 1001],
            ['id' => 102, 'name' => 'C-Zwei', 'first_program' => $challenge, 'team_number_hot' => 1002],
            ['id' => 201, 'name' => 'F-Eins', 'first_program' => $future, 'team_number_hot' => 2001],
            ['id' => 202, 'name' => 'F-Zwei', 'first_program' => $future, 'team_number_hot' => 2002],
        ]);
        DB::table('team_plan')->insert([
            ['team' => 101, 'plan' => 10, 'team_number_plan' => 1, 'noshow' => null],
            ['team' => 102, 'plan' => 10, 'team_number_plan' => 2, 'noshow' => null],
            ['team' => 201, 'plan' => 10, 'team_number_plan' => 1, 'noshow' => null],
            ['team' => 202, 'plan' => 10, 'team_number_plan' => 2, 'noshow' => null],
        ]);

        $rows = [];
        foreach ([1, 2, 3] as $round) {
            // Challenge: match 1 is team 1 vs 2.
            $rows[] = $this->match($challenge, $round, 1, 1, 2);
            // Future 8+ in the same plan and round: matches 1 and 2 with swapped slots.
            $rows[] = $this->match($future, $round, 1, 2, 1);
            $rows[] = $this->match($future, $round, 2, 1, 0);
        }
        DB::table('match')->insert($rows);
    }

    public function test_assembler_lists_only_challenge_matches(): void
    {
        $document = app(MatchPlanScoreAssembler::class)->assemble(10);

        $this->assertNotNull($document);
        $rounds = $document['sections'][0]['rounds'];
        $this->assertCount(3, $rounds);
        foreach ($rounds as $round) {
            $this->assertCount(1, $round['matches']);
            $match = $round['matches'][0];
            $this->assertSame(1, (int) $match['match_no']);
            $this->assertSame('C-Eins', $match['team_1']['name']);
            $this->assertSame('C-Zwei', $match['team_2']['name']);
        }
    }

    public function test_match_teams_dialog_lists_only_challenge_matches(): void
    {
        $response = app(PlanExportController::class)->matchTeams(10, 2);
        $matches = $response->getData(true)['matches'];

        $this->assertCount(1, $matches);
        $this->assertSame('C-Eins', $matches[0]['team_1']['name']);
        $this->assertSame('C-Zwei', $matches[0]['team_2']['name']);
    }

    /**
     * @return array<string, int>
     */
    private function match(int $program, int $round, int $no, int $team1, int $team2): array
    {
        return [
            'plan' => 10,
            'first_program' => $program,
            'round' => $round,
            'match_no' => $no,
            'table_1' => 1,
            'table_2' => 2,
            'table_1_team' => $team1,
            'table_2_team' => $team2,
        ];
    }
}
