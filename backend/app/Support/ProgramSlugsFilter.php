<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ProgramSlugsFilter
{
    /**
     * @return list<string>|null null = no filter (all programs); empty list = none
     */
    public static function parse(Request $request): ?array
    {
        if (! $request->has('programs')) {
            return null;
        }

        $raw = $request->query('programs');
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        $slugs = [];
        foreach ($parts as $part) {
            $slug = strtolower(trim(str_replace('-', '_', (string) $part)));
            if ($slug !== '') {
                $slugs[$slug] = $slug;
            }
        }

        return array_values($slugs);
    }
}
