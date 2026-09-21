<?php

namespace Tests\Unit;

use App\Support\CeremonyPrefix;
use PHPUnit\Framework\TestCase;

class CeremonyPrefixTest extends TestCase
{
    /** @return list<array{0: bool, 1: bool, 2: bool, 3: string}> */
    public static function challengeShapedCases(): array
    {
        return [
            'C only, Explore not in slot' => [true, false, false, 'c'],
            'C only, Explore in slot' => [true, false, true, 'g'],
            'F8 only, Explore not in slot' => [false, true, false, 'f8'],
            'F8 only, Explore in slot' => [false, true, true, 'g'],
            'C+F8, Explore not in slot' => [true, true, false, 'c+f8'],
            'C+F8, Explore in slot' => [true, true, true, 'g'],
        ];
    }

    /**
     * @dataProvider challengeShapedCases
     */
    public function test_prefix_for_challenge_shaped_membership(
        bool $challengeOn,
        bool $futureOn,
        bool $exploreInSlot,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            CeremonyPrefix::for($challengeOn, $futureOn, $exploreInSlot),
        );
    }
}
