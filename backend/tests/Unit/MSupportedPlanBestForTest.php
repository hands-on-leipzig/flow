<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Models\MSupportedPlan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MSupportedPlanBestForTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Requires sqlite.');
        }

        Schema::dropAllTables();
        Schema::create('m_supported_plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('teams');
            $table->unsignedInteger('lanes');
            $table->unsignedInteger('tables')->nullable();
            $table->unsignedTinyInteger('alert_level')->nullable();
        });
    }

    public function test_prefers_alert_level_one_for_team_count(): void
    {
        DB::table('m_supported_plan')->insert([
            [
                'id' => 1,
                'first_program' => FirstProgram::CHALLENGE->value,
                'teams' => 8,
                'lanes' => 2,
                'tables' => 4,
                'alert_level' => 0,
            ],
            [
                'id' => 2,
                'first_program' => FirstProgram::CHALLENGE->value,
                'teams' => 8,
                'lanes' => 2,
                'tables' => 2,
                'alert_level' => 1,
            ],
        ]);

        $best = MSupportedPlan::bestFor(FirstProgram::CHALLENGE->value, 8);

        $this->assertNotNull($best);
        $this->assertSame(2, (int) $best->tables);
        $this->assertSame(1, (int) $best->alert_level);
    }

    public function test_falls_back_to_first_row_when_no_alert_level_one(): void
    {
        DB::table('m_supported_plan')->insert([
            [
                'id' => 10,
                'first_program' => FirstProgram::FUTURE_8->value,
                'teams' => 8,
                'lanes' => 2,
                'tables' => 4,
                'alert_level' => 2,
            ],
            [
                'id' => 11,
                'first_program' => FirstProgram::FUTURE_8->value,
                'teams' => 8,
                'lanes' => 3,
                'tables' => 4,
                'alert_level' => 3,
            ],
        ]);

        $best = MSupportedPlan::bestFor(FirstProgram::FUTURE_8->value, 8);

        $this->assertNotNull($best);
        $this->assertSame(10, (int) $best->id);
        $this->assertSame(2, (int) $best->lanes);
    }
}
