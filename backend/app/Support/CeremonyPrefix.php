<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Opening/awards ATD prefix from who is on this ceremony slot.
 *
 * g = Explore in the slot; c+f8 = Challenge and Future, Explore not in the slot;
 * c / f8 = one Challenge-shaped program.
 */
final class CeremonyPrefix
{
    public static function for(bool $challengeOn, bool $futureOn, bool $exploreInSlot): string
    {
        if (! $challengeOn && ! $futureOn) {
            throw new InvalidArgumentException(
                'CeremonyPrefix: at least one Challenge-shaped program must be on.'
            );
        }

        if ($exploreInSlot) {
            return 'g';
        }

        if ($challengeOn && $futureOn) {
            return 'c+f8';
        }

        return $challengeOn ? 'c' : 'f8';
    }
}
