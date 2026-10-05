<?php

namespace Tests\Unit;

use App\Core\ActivityWriter;
use App\Core\RobotGameGenerator;
use App\Core\RobotGameWriteConfig;
use App\Core\TimeCursor;
use App\Enums\FirstProgram;
use App\Support\IntegratedExploreState;
use App\Support\MatchPlan;
use App\Support\PlanParameter;
use DateTime;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class WriteMatchAtAlliancePrefixTest extends TestCase
{
    public function test_alliance_starts_before_zip_match_start(): void
    {
        $calls = [];
        $writer = $this->createMock(ActivityWriter::class);
        $writer->expects($this->exactly(2))
            ->method('insertActivity')
            ->willReturnCallback(function (string $code, TimeCursor $time, int $duration) use (&$calls) {
                $calls[] = [
                    'code' => $code,
                    'start' => $time->current()->format('H:i'),
                    'duration' => $duration,
                ];

                return 1;
            });

        $rg = new RobotGameGenerator(
            $writer,
            $this->parameters([
                'f8_r_alliance_meeting' => [1, 'boolean'],
                'f8_r_duration_alliance_meeting' => [5, 'integer'],
                'f8_r_duration_match_ex' => [10, 'integer'],
                'f8_r_duration_match_in' => [15, 'integer'],
                'f8_r_duration_test_match' => [15, 'integer'],
            ]),
            new TimeCursor(new DateTime('2026-01-01 10:00:00')),
            new IntegratedExploreState,
            new MatchPlan(FirstProgram::FUTURE_8, []),
            RobotGameWriteConfig::future8(),
        );

        $rg->writeMatchAt(
            [
                'round' => 1,
                'match' => 1,
                'table_1' => 1,
                'table_2' => 2,
                'team_1' => 1,
                'team_2' => 3,
            ],
            1,
            new DateTime('2026-01-01 10:10:00'),
            allowRobotCheck: true,
        );

        $this->assertSame('f8_r_alliance', $calls[0]['code']);
        $this->assertSame('10:05', $calls[0]['start']);
        $this->assertSame(5, $calls[0]['duration']);
        $this->assertSame('f8_r_match', $calls[1]['code']);
        $this->assertSame('10:10', $calls[1]['start']);
        $this->assertSame(10, $calls[1]['duration']);
    }

    /**
     * @param  array<string, array{0: mixed, 1: string}>  $values
     */
    private function parameters(array $values): PlanParameter
    {
        $ref = new ReflectionClass(PlanParameter::class);
        $params = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('params')->setValue($params, []);
        foreach ($values as $key => [$value, $type]) {
            $params->add($key, $value, $type);
        }

        return $params;
    }
}
