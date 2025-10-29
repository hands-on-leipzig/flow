<?php

namespace App\Enums;

enum ExploreMode: int
{
    case NONE = 0;
    case INTEGRATED_MORNING = 1;
    case INTEGRATED_AFTERNOON = 2;
    case DECOUPLED_MORNING = 3;
    case DECOUPLED_AFTERNOON = 4;
    case DECOUPLED_BOTH = 5;
    case HYBRID_MORNING = 6;
    case HYBRID_AFTERNOON = 7;
    case HYBRID_BOTH = 8;
}

