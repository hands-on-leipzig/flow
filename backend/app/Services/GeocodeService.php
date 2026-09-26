<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nominatim lookups for the two kinds of places FLOW shows on a map:
 * a venue (a full postal address) and a team home town (a place name).
 */
class GeocodeService
{
    private const USER_AGENT = 'FLL Flow Planning Tool (https://github.com/hands-on-leipzig/flow)';

    private const HIT_TTL_DAYS = 30;

    private const MISS_TTL_HOURS = 6;

    /**
     * Resolve a postal address. Tries the address as given, then progressively
     * coarser variants (street + PLZ/city, PLZ + city, city) so that a venue
     * name or an extra line does not break the lookup.
     *
     * @return array{lat: float, lon: float, display_name: string, query: string}|null
     */
    public function address(string $address): ?array
    {
        foreach ($this->addressCandidates($address) as $candidate) {
            $result = $this->lookup('address', $candidate, ['q' => $candidate]);
            if ($result) {
                return $result + ['query' => $candidate];
            }
        }

        return null;
    }

    /**
     * Resolve a place name. A free-text lookup finds villages and districts
     * that the structured city search misses; the structured search is the
     * fallback for names that free text resolves to something else.
     *
     * @return array{lat: float, lon: float, display_name: string, query: string}|null
     */
    public function city(string $city, bool $cacheOnly = false): ?array
    {
        foreach ($this->cityCandidates($city) as $candidate) {
            $result = $this->lookup('city-q', $candidate, ['q' => $candidate], $cacheOnly);
            if ($result) {
                return $result + ['query' => $candidate];
            }

            $result = $this->lookup('city', $candidate, ['city' => $candidate], $cacheOnly);
            if ($result) {
                return $result + ['query' => $candidate];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function addressCandidates(string $address): array
    {
        $lines = $this->splitLines($address);
        if ($lines === []) {
            return [];
        }

        $candidates = [implode(', ', $lines)];

        // The first line is often a venue or hall name that Nominatim does not know.
        if (count($lines) > 1) {
            $candidates[] = implode(', ', array_slice($lines, 1));
        }

        $plzIndex = null;
        for ($i = count($lines) - 1; $i >= 1; $i--) {
            if (preg_match('/\b\d{5}\b/', $lines[$i])) {
                $plzIndex = $i;
                break;
            }
        }

        if ($plzIndex !== null) {
            $plzCity = $lines[$plzIndex];
            $candidates[] = $lines[$plzIndex - 1] . ', ' . $plzCity;
            $candidates[] = $lines[0] . ', ' . $plzCity;
            $candidates[] = $plzCity;

            $city = trim(preg_replace('/\b\d{5}\b/', '', $plzCity));
            if ($city !== '') {
                $candidates[] = $city;
            }
        } elseif (count($lines) >= 2) {
            $candidates[] = $lines[0] . ', ' . $lines[count($lines) - 1];
            $candidates[] = $lines[count($lines) - 1];
        }

        return $this->unique($candidates);
    }

    /**
     * @return list<string>
     */
    private function cityCandidates(string $city): array
    {
        $city = $this->collapseWhitespace($city);
        if ($city === '') {
            return [];
        }

        $candidates = [$city];

        // "Leipzig OT Liebertwolkwitz" / "Halle (Saale)" / "Kelbra / Kyffhäuser"
        $withoutSuffix = preg_split('/\s+(?:OT|Ortsteil)\s+/iu', $city)[0];
        $candidates[] = preg_replace('/\s*\([^)]*\)/u', '', $withoutSuffix);
        $candidates[] = trim(explode('/', $withoutSuffix)[0]);

        return $this->unique($candidates);
    }

    /**
     * @param  array<string, string>  $query
     * @return array{lat: float, lon: float, display_name: string}|null
     */
    private function lookup(string $kind, string $value, array $query, bool $cacheOnly = false): ?array
    {
        // `false` marks a known miss; the cache cannot hold null.
        $key = 'geocode:' . $kind . ':' . sha1(mb_strtolower($value));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === false || $cacheOnly) {
            return null;
        }

        $result = $this->call($query);
        Cache::put(
            $key,
            $result ?? false,
            $result ? now()->addDays(self::HIT_TTL_DAYS) : now()->addHours(self::MISS_TTL_HOURS)
        );

        return $result;
    }

    /**
     * @param  array<string, string>  $query
     * @return array{lat: float, lon: float, display_name: string}|null
     */
    private function call(array $query): ?array
    {
        $this->throttle();

        $query += ['format' => 'json', 'limit' => 1];
        $countries = trim((string) config('services.nominatim.countrycodes', ''));
        if ($countries !== '') {
            $query['countrycodes'] = $countries;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout((int) config('services.nominatim.timeout', 8))
                ->get((string) config('services.nominatim.url'), $query);
        } catch (\Throwable $e) {
            Log::warning('Geocoding request failed', ['query' => $query, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Geocoding request rejected', ['query' => $query, 'status' => $response->status()]);

            return null;
        }

        $data = $response->json();
        if (! is_array($data) || ! isset($data[0]['lat'], $data[0]['lon'])) {
            return null;
        }

        return [
            'lat' => (float) $data[0]['lat'],
            'lon' => (float) $data[0]['lon'],
            'display_name' => (string) ($data[0]['display_name'] ?? ''),
        ];
    }

    private function throttle(): void
    {
        $interval = (float) config('services.nominatim.min_interval', 1.1);
        $last = Cache::get('geocode:last-call');
        if ($interval > 0 && is_numeric($last)) {
            $wait = $interval - (microtime(true) - (float) $last);
            if ($wait > 0) {
                usleep((int) round($wait * 1_000_000));
            }
        }

        Cache::put('geocode:last-call', microtime(true), now()->addMinute());
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $address): array
    {
        $parts = preg_split('/[\r\n]+/', $address) ?: [];
        $lines = [];
        foreach ($parts as $part) {
            $part = $this->collapseWhitespace($part);
            if ($part !== '') {
                $lines[] = $part;
            }
        }

        return $lines;
    }

    private function collapseWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function unique(array $values): array
    {
        $seen = [];
        foreach ($values as $value) {
            $value = $this->collapseWhitespace((string) $value);
            if ($value !== '') {
                $seen[mb_strtolower($value)] = $value;
            }
        }

        return array_values($seen);
    }
}
