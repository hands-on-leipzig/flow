<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Models\MSupportedPlan;
use App\Services\QualityEvaluatorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class QualityMassTestJuryRoundsTest extends TestCase
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
    }

    public function test_future_two_teams_uses_catalog_judging_count_not_ceil(): void
    {
        for ($round = 0; $round <= 3; $round++) {
            DB::table('m_match')->insert([
                'first_program' => FirstProgram::FUTURE_8->value,
                'teams' => 2,
                'lanes' => 1,
                'tables' => 2,
                'round' => $round,
                'match_no' => 1,
                'table_1' => 1,
                'table_2' => 2,
                'table_1_team' => 1,
                'table_2_team' => 2,
            ]);
        }

        $row = new MSupportedPlan([
            'first_program' => FirstProgram::FUTURE_8->value,
            'teams' => 2,
            'lanes' => 1,
            'tables' => 2,
        ]);

        $this->assertSame(4, $this->judgingRounds($row));
        $this->assertTrue($this->planSupported($row, [4]));
        $this->assertFalse($this->planSupported($row, [3]));
    }

    public function test_challenge_still_uses_ceil_teams_over_lanes(): void
    {
        $row = new MSupportedPlan([
            'first_program' => FirstProgram::CHALLENGE->value,
            'teams' => 8,
            'lanes' => 2,
            'tables' => 4,
        ]);

        $this->assertSame(4, $this->judgingRounds($row));
    }

    private function judgingRounds(MSupportedPlan $plan): ?int
    {
        $method = new ReflectionMethod(QualityEvaluatorService::class, 'judgingRoundsForSupportedPlan');
        $method->setAccessible(true);

        return $method->invoke(new QualityEvaluatorService, $plan);
    }

    private function planSupported(MSupportedPlan $plan, array $juryRounds): bool
    {
        $method = new ReflectionMethod(QualityEvaluatorService::class, 'isPlanSupported');
        $method->setAccessible(true);

        return $method->invoke(new QualityEvaluatorService, $plan, [
            'min_teams' => 2,
            'max_teams' => 25,
            'jury_lanes' => [1, 2],
            'tables' => [2, 4],
            'jury_rounds' => $juryRounds,
        ]);
    }
}
