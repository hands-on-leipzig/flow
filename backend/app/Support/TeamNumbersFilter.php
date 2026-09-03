<?php

namespace App\Support;

use Illuminate\Http\Request;

final class TeamNumbersFilter
{
    /**
     * @return list<int>|null null = no filter (all teams); empty list = none
     */
    public static function parse(Request $request): ?array
    {
        if (! $request->has('team_numbers')) {
            return null;
        }

        $raw = $request->query('team_numbers');
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        $nums = [];
        foreach ($parts as $part) {
            $num = (int) $part;
            if ($num <= 0) continue;
            $nums[$num] = $num;
        }

        return array_values($nums);
    }
}

