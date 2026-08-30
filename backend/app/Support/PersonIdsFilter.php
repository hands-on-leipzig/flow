<?php

namespace App\Support;

use Illuminate\Http\Request;

final class PersonIdsFilter
{
    /**
     * @return list<int>|null null = no filter (export all); empty list = export none
     */
    public static function parse(Request $request): ?array
    {
        if (! $request->has('person_ids')) {
            return null;
        }

        $raw = $request->query('person_ids');
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        $ids = [];
        foreach ($parts as $part) {
            $id = (int) trim((string) $part);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
