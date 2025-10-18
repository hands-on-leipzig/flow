<?php

namespace App\Enums;

enum FirstProgram: int
{
    case JOINT = 0;      // Joint/General activities (not program-specific)
    case DISCOVER = 1;   // Discover program
    case EXPLORE = 2;    // Explore program
    case CHALLENGE = 3;  // Challenge program

    /**
     * Check if this program is Explore-related (includes Discover)
     */
    public function isExplore(): bool
    {
        return $this === self::EXPLORE || $this === self::DISCOVER;
    }

    /**
     * Check if this program is Challenge-related
     */
    public function isChallenge(): bool
    {
        return $this === self::CHALLENGE;
    }

    /**
     * Check if this is a joint/general program
     */
    public function isJoint(): bool
    {
        return $this === self::JOINT;
    }

    /**
     * Get the program letter for shorthand notation
     */
    public function getLetter(): string
    {
        return match($this) {
            self::DISCOVER, self::EXPLORE => 'E',
            self::CHALLENGE => 'C',
            self::JOINT => 'G',
        };
    }
}

