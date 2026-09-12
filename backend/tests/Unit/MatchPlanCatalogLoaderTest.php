<?php

namespace Tests\Unit;

use App\Core\MatchPlanCatalogLoader;
use App\Enums\FirstProgram;
use App\Models\MatchEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MatchPlanCatalogLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Requires sqlite.');
        }

        Schema::dropAllTables();

        Schema::create('m_match', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('teams');
            $table->unsignedInteger('lanes');
            $table->unsignedInteger('tables');
            $table->unsignedInteger('round');
            $table->unsignedInteger('match_no');
            $table->unsignedInteger('table_1');
            $table->unsignedInteger('table_2');
            $table->unsignedInteger('table_1_team');
            $table->unsignedInteger('table_2_team');
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
    }

    public function test_exact_key_loads_and_persists(): void
    {
        $this->insertCatalogRow(8, 2, 4, 0, 1, 1, 2, 8, 3);
        $this->insertCatalogRow(8, 2, 4, 1, 1, 3, 4, 1, 2);

        $plan = (new MatchPlanCatalogLoader)->load(FirstProgram::FUTURE_8, 10, 8, 2, 4);

        $this->assertCount(2, $plan->entries);
        $this->assertSame([
            'round' => 0,
            'match' => 1,
            'table_1' => 1,
            'table_2' => 2,
            'team_1' => 8,
            'team_2' => 3,
        ], $plan->entries[0]);
        $this->assertSame(1, $plan->entries[1]['round']);
        $this->assertSame(1, $plan->entries[1]['team_1']);

        $live = MatchEntry::where('plan', 10)->where('first_program', FirstProgram::FUTURE_8->value)->get();
        $this->assertCount(2, $live);
        $this->assertSame(8, (int) $live[0]->table_1_team);
        $this->assertSame(1, (int) $live[1]->match_no);
    }

    public function test_teams_plus_one_remaps_volunteer_cell_to_zero(): void
    {
        $this->insertCatalogRow(8, 2, 4, 0, 1, 1, 2, 1, 8);

        $plan = (new MatchPlanCatalogLoader)->load(FirstProgram::FUTURE_8, 11, 7, 2, 4);

        $this->assertSame(0, $plan->entries[0]['team_2']);
        $this->assertSame(1, $plan->entries[0]['team_1']);
        $this->assertSame(0, (int) MatchEntry::where('plan', 11)->value('table_2_team'));
    }

    public function test_miss_both_keys_throws_naming_the_lookup(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Teams=9, Jurygruppen=2, Spielfelder=4');
        $this->expectExceptionMessage('Teams+1=10');

        (new MatchPlanCatalogLoader)->load(FirstProgram::FUTURE_8, 1, 9, 2, 4);
    }

    public function test_replaces_existing_live_rows_for_plan_and_program(): void
    {
        $this->insertCatalogRow(8, 2, 4, 0, 1, 1, 2, 1, 2);

        MatchEntry::insert([
            'plan' => 5,
            'first_program' => FirstProgram::FUTURE_8->value,
            'round' => 3,
            'match_no' => 9,
            'table_1' => 1,
            'table_2' => 2,
            'table_1_team' => 99,
            'table_2_team' => 98,
        ]);
        MatchEntry::insert([
            'plan' => 5,
            'first_program' => FirstProgram::CHALLENGE->value,
            'round' => 0,
            'match_no' => 1,
            'table_1' => 1,
            'table_2' => 2,
            'table_1_team' => 1,
            'table_2_team' => 2,
        ]);

        (new MatchPlanCatalogLoader)->load(FirstProgram::FUTURE_8, 5, 8, 2, 4);

        $this->assertSame(1, MatchEntry::where('plan', 5)->where('first_program', FirstProgram::FUTURE_8->value)->count());
        $this->assertSame(1, MatchEntry::where('plan', 5)->where('first_program', FirstProgram::CHALLENGE->value)->count());
        $this->assertSame(1, (int) MatchEntry::where('plan', 5)->where('first_program', FirstProgram::FUTURE_8->value)->value('table_1_team'));
    }

    public function test_rejects_challenge_program(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Challenge stays on MatchPlanBuilder');

        (new MatchPlanCatalogLoader)->load(FirstProgram::CHALLENGE, 1, 8, 2, 4);
    }

    private function insertCatalogRow(
        int $teams,
        int $lanes,
        int $tables,
        int $round,
        int $matchNo,
        int $table1,
        int $table2,
        int $team1,
        int $team2,
    ): void {
        DB::table('m_match')->insert([
            'first_program' => FirstProgram::FUTURE_8->value,
            'teams' => $teams,
            'lanes' => $lanes,
            'tables' => $tables,
            'round' => $round,
            'match_no' => $matchNo,
            'table_1' => $table1,
            'table_2' => $table2,
            'table_1_team' => $team1,
            'table_2_team' => $team2,
        ]);
    }
}
