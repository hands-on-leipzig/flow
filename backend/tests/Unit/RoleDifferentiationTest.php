<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Support\PlanParameter;
use App\Support\RoleDifferentiation;
use Mockery;
use Tests\TestCase;

class RoleDifferentiationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_null_or_empty_inputs_are_zero(): void
    {
        $params = $this->params([]);

        $this->assertSame(0, RoleDifferentiation::optionCount(null, 'lane', $params));
        $this->assertSame(0, RoleDifferentiation::optionCount(FirstProgram::CHALLENGE->value, null, $params));
        $this->assertSame(0, RoleDifferentiation::optionCount(FirstProgram::CHALLENGE->value, '', $params));
        $this->assertSame(0, RoleDifferentiation::optionCount(FirstProgram::CHALLENGE->value, 'list', $params));
        $this->assertSame(0, RoleDifferentiation::optionCount(FirstProgram::DISCOVER->value, 'lane', $params));
    }

    public function test_lane_counts_by_program(): void
    {
        $params = $this->params([
            'e1_lanes' => 2,
            'e2_lanes' => 3,
            'j_lanes' => 4,
            'f8_lanes' => 5,
        ]);

        $this->assertSame(5, RoleDifferentiation::optionCount(FirstProgram::EXPLORE->value, 'lane', $params));
        $this->assertSame(4, RoleDifferentiation::optionCount(FirstProgram::CHALLENGE->value, 'lane', $params));
        $this->assertSame(5, RoleDifferentiation::optionCount(FirstProgram::FUTURE_8->value, 'lane', $params));
    }

    public function test_table_counts_by_program(): void
    {
        $params = $this->params([
            'r_tables' => 6,
            'f8_fields' => 7,
        ]);

        $this->assertSame(0, RoleDifferentiation::optionCount(FirstProgram::EXPLORE->value, 'table', $params));
        $this->assertSame(6, RoleDifferentiation::optionCount(FirstProgram::CHALLENGE->value, 'table', $params));
        $this->assertSame(7, RoleDifferentiation::optionCount(FirstProgram::FUTURE_8->value, 'table', $params));
    }

    public function test_team_counts_use_e_teams_for_explore(): void
    {
        $params = $this->params([
            'e_teams' => 9,
            'e1_teams' => 4,
            'e2_teams' => 5,
            'c_teams' => 8,
            'f8_teams' => 10,
        ]);

        $this->assertSame(9, RoleDifferentiation::optionCount(FirstProgram::EXPLORE->value, 'team', $params));
        $this->assertSame(8, RoleDifferentiation::optionCount(FirstProgram::CHALLENGE->value, 'team', $params));
        $this->assertSame(10, RoleDifferentiation::optionCount(FirstProgram::FUTURE_8->value, 'team', $params));
    }

    /**
     * @param array<string, int> $values
     */
    private function params(array $values): PlanParameter
    {
        $params = Mockery::mock(PlanParameter::class);
        $params->shouldReceive('get')->andReturnUsing(
            function (string $key, mixed $default = 0) use ($values): mixed {
                return $values[$key] ?? $default;
            }
        );

        return $params;
    }
}
