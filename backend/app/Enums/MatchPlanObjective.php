<?php

namespace App\Enums;

/**
 * What a robot-game match plan should optimize when rotating rounds 2 and 3.
 *
 * Challenge prefers many different tables (Q2). Future 8+ will prefer many
 * different opponents (Q3). Same pairing machinery; the primary goal differs.
 *
 * The rotator still uses its current lexicographic order (rematch, then tables)
 * so Challenge pairings do not change in this step. The enum records intent
 * and is the hook for Future 8+ / a later retune.
 */
enum MatchPlanObjective
{
    case TABLES;
    case OPPONENTS;
}
