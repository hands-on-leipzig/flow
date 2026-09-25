<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Http\Controllers\Api\ExtraBlockController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SlotTeamActivitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Slot team activity tests require sqlite.');
        }

        $this->createSchema();
        $this->seedPlan();
    }

    public function test_future_hover_uses_future_team_role_not_challenge(): void
    {
        $this->insertActivity(
            id: 1,
            atd: 74,
            program: FirstProgram::FUTURE_8->value,
            name: 'Spiel-Match',
            code: 'f8_r_match',
            teamColumn: 'table_1_team',
            team: 2,
            visibilityRole: 21,
        );
        $this->insertActivity(
            id: 2,
            atd: 75,
            program: FirstProgram::FUTURE_8->value,
            name: 'Jurygespräch',
            code: 'f8_j_with_team',
            teamColumn: 'jury_team',
            team: 2,
            visibilityRole: 3,
        );

        $labels = $this->activityLabels(FirstProgram::FUTURE_8->value, 2);

        $this->assertSame(['Spiel-Match'], $labels);
    }

    public function test_challenge_hover_still_uses_challenge_team_role(): void
    {
        $this->insertActivity(
            id: 1,
            atd: 15,
            program: FirstProgram::CHALLENGE->value,
            name: 'Robot-Game Match',
            code: 'r_match',
            teamColumn: 'table_1_team',
            team: 1,
            visibilityRole: 3,
        );
        $this->insertActivity(
            id: 2,
            atd: 8,
            program: FirstProgram::CHALLENGE->value,
            name: 'Jurygespräch',
            code: 'j_with_team',
            teamColumn: 'jury_team',
            team: 1,
            visibilityRole: 21,
        );

        $labels = $this->activityLabels(FirstProgram::CHALLENGE->value, 1);

        $this->assertSame(['Robot-Game Match'], $labels);
    }

    /**
     * @return list<string>
     */
    private function activityLabels(int $programId, int $teamNumberPlan): array
    {
        $payload = app(ExtraBlockController::class)
            ->slotTeamActivities(1, 10, $programId, $teamNumberPlan)
            ->getData(true);

        return array_column($payload['activities'], 'label');
    }

    private function insertActivity(
        int $id,
        int $atd,
        int $program,
        string $name,
        string $code,
        string $teamColumn,
        int $team,
        int $visibilityRole,
    ): void {
        DB::table('m_activity_type_detail')->insert([
            'id' => $atd,
            'name' => $name,
            'code' => $code,
            'first_program' => $program,
        ]);
        DB::table('m_visibility')->insert([
            'id' => $id,
            'activity_type_detail' => $atd,
            'role' => $visibilityRole,
        ]);
        DB::table('activity_group')->insert([
            'id' => $id,
            'activity_type_detail' => $atd,
            'plan' => 1,
        ]);
        DB::table('activity')->insert([
            'id' => $id,
            'activity_group' => $id,
            'start' => '2026-03-15 10:00:00',
            'end' => '2026-03-15 10:20:00',
            'activity_type_detail' => $atd,
            $teamColumn => $team,
        ]);
    }

    private function seedPlan(): void
    {
        DB::table('m_first_program')->insert([
            ['id' => FirstProgram::CHALLENGE->value, 'sequence' => 2],
            ['id' => FirstProgram::FUTURE_8->value, 'sequence' => 5],
        ]);
        DB::table('event')->insert([
            'id' => 1,
            'date' => '2026-03-15',
            'days' => 1,
            'level' => 1,
        ]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => FirstProgram::CHALLENGE->value],
            ['event' => 1, 'first_program' => FirstProgram::FUTURE_8->value],
        ]);

        $params = [
            ['id' => 22, 'name' => 'c_teams', 'value' => '8', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 122, 'name' => 'c_mode', 'value' => '0', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 40, 'name' => 'c_duration_transfer', 'value' => '5', 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 200, 'name' => 'f8_teams', 'value' => '8', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 201, 'name' => 'f8_mode', 'value' => '0', 'first_program' => FirstProgram::FUTURE_8->value],
            ['id' => 210, 'name' => 'f8_duration_transfer', 'value' => '5', 'first_program' => FirstProgram::FUTURE_8->value],
        ];
        foreach ($params as $row) {
            DB::table('m_parameter')->insert($row);
        }

        DB::table('extra_block')->insert([
            'id' => 10,
            'plan' => 1,
            'first_program' => FirstProgram::JOINT->value,
            'name' => 'Slot',
            'duration' => 20,
            'type' => 'slot',
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedSmallInteger('sequence')->default(0);
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
            $table->id();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
        });

        Schema::create('m_parameter', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('type')->default('integer');
            $table->string('value')->nullable();
            $table->string('min')->nullable();
            $table->string('max')->nullable();
            $table->string('step')->nullable();
            $table->unsignedInteger('first_program')->nullable();
        });

        Schema::create('plan_param_value', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('parameter');
            $table->string('set_value')->nullable();
        });

        Schema::create('extra_block', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('first_program')->default(0);
            $table->string('name')->nullable();
            $table->unsignedInteger('duration')->default(0);
            $table->string('type')->nullable();
        });

        Schema::create('slot_block_team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('extra_block');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('team_number_plan');
            $table->dateTime('start')->nullable();
        });

        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->unsignedInteger('first_program')->nullable();
        });

        Schema::create('m_visibility', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedInteger('role');
        });

        Schema::create('activity_group', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('activity_type_detail');
        });

        Schema::create('activity', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('activity_group');
            $table->dateTime('start');
            $table->dateTime('end');
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedInteger('extra_block')->nullable();
            $table->unsignedInteger('jury_team')->nullable();
            $table->unsignedInteger('table_1_team')->nullable();
            $table->unsignedInteger('table_2_team')->nullable();
            $table->unsignedInteger('slot_team')->nullable();
        });

        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('first_program');
            $table->string('name')->nullable();
            $table->string('team_number_hot')->nullable();
        });

        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('team');
            $table->unsignedInteger('team_number_plan');
        });
    }
}
