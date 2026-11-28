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
     * Resolve a postal address inside one country.
     *
     * DE uses a 5-digit PLZ, AT and CH a 4-digit one. The lookup is the postal
     * code (plus the street when one is present), limited to that country.
     * A free-text search of the venue name is not used: "Chur" inside Germany
     * is a bus stop in Mönchengladbach.
     *
     * @return array{lat: float, lon: float, display_name: string, query: string}|null
     */
    public function address(string $address, ?string $country = null): ?array
    {
        $country = $this->normalizeCountry($country) ?? $this->countryFromAddress($address);
        $parsed = $this->parsePostalAddress($address, $country);

        foreach ($this->addressAttempts($parsed, $country) as $attempt) {
            $result = $this->lookup('address', $attempt['key'], $attempt['query']);
            if ($result === null) {
                continue;
            }
            if ($attempt['city'] !== null && ! $this->displayContains($result['display_name'], $attempt['city'])) {
                continue;
            }

            return $result + ['query' => $attempt['label']];
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
     * @param  array{street: string, postal: ?string, city: ?string, lines: list<string>}  $parsed
     * @return list<array{key: string, label: string, city: ?string, query: array<string, string>}>
     */
    private function addressAttempts(array $parsed, ?string $country): array
    {
        $street = $parsed['street'];
        $postal = $parsed['postal'];
        $city = $parsed['city'];
        $attempts = [];

        if ($postal !== null) {
            $countries = $this->postalCountries($country, $postal);
            $mustMatchCity = $country === null && strlen($postal) === 4;
            foreach ($countries as $code) {
                if ($street !== '') {
                    $attempts[] = [
                        'key' => 'street:'.$code.':'.$postal.':'.$street,
                        'label' => $street.', '.$postal,
                        'city' => $mustMatchCity ? $city : null,
                        'query' => [
                            'street' => $street,
                            'postalcode' => $postal,
                            'countrycodes' => $code,
                        ],
                    ];
                }
                $attempts[] = [
                    'key' => 'postal:'.$code.':'.$postal.':'.($mustMatchCity ? (string) $city : ''),
                    'label' => $postal.($city ? ' '.$city : ''),
                    'city' => $mustMatchCity ? $city : null,
                    'query' => [
                        'postalcode' => $postal,
                        'countrycodes' => $code,
                    ],
                ];
                if ($city !== null && $city !== '') {
                    $attempts[] = [
                        'key' => 'city:'.$code.':'.$postal.':'.$city,
                        'label' => $city,
                        'city' => $mustMatchCity ? $city : null,
                        'query' => [
                            'city' => $city,
                            'countrycodes' => $code,
                        ],
                    ];
                }
            }

            return $attempts;
        }

        $lines = $parsed['lines'];
        if ($lines === []) {
            return [];
        }
        $code = $country ?? '';
        foreach ($this->looseCandidates($lines) as $candidate) {
            $query = ['q' => $candidate];
            if ($code !== '') {
                $query['countrycodes'] = $code;
            }
            $attempts[] = [
                'key' => 'q:'.$code.':'.$candidate,
                'label' => $candidate,
                'city' => null,
                'query' => $query,
            ];
        }

        return $attempts;
    }

    /**
     * Countries whose postal-code length matches. A 4-digit code without a
     * known country is tried in Switzerland and then Austria. Germany is not
     * one of them: its codes have five digits, and a German free-text search
     * of the place name resolves homonyms such as the bus stop Chur.
     *
     * @return list<string>
     */
    private function postalCountries(?string $country, string $postal): array
    {
        if ($country !== null) {
            return [$country];
        }

        return strlen($postal) === 5 ? ['de'] : ['ch', 'at'];
    }

    /**
     * @return array{street: string, postal: ?string, city: ?string, lines: list<string>}
     */
    private function parsePostalAddress(string $address, ?string $country): array
    {
        $lines = $this->splitLines($address);
        $postal = null;
        $city = null;
        $postalIndex = null;

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $found = $this->postalLine($lines[$i], $country);
            if ($found === null) {
                continue;
            }
            $postal = $found['postal'];
            $city = $found['city'];
            $postalIndex = $i;
            break;
        }

        $street = '';
        if ($postalIndex !== null && $postalIndex > 0 && $this->looksLikeStreet($lines[$postalIndex - 1])) {
            // The venue name often sits above the street and may contain a digit ("Halle 5").
            $street = $lines[$postalIndex - 1];
        } else {
            foreach ($lines as $i => $line) {
                if ($i === $postalIndex) {
                    continue;
                }
                if ($this->looksLikeStreet($line)) {
                    $street = $line;
                    break;
                }
            }
        }

        return [
            'street' => $street,
            'postal' => $postal,
            'city' => $city !== '' ? $city : null,
            'lines' => $lines,
        ];
    }

    /**
     * @return array{postal: string, city: string}|null
     */
    private function postalLine(string $line, ?string $country): ?array
    {
        if (! preg_match('/^(?:(?:CH|A|D)[-\s]*)?(\d{4,5})\b\s*(.*)$/iu', $line, $match)) {
            return null;
        }

        $code = $match[1];
        $length = strlen($code);
        if ($country === 'de' && $length !== 5) {
            return null;
        }
        if (($country === 'at' || $country === 'ch') && $length !== 4) {
            return null;
        }
        if ($country === null && $length !== 4 && $length !== 5) {
            return null;
        }

        $city = $this->collapseWhitespace($match[2]);

        return ['postal' => $code, 'city' => $city];
    }

    private function looksLikeStreet(string $line): bool
    {
        return preg_match('/\p{L}/u', $line) === 1
            && preg_match('/\d/', $line) === 1
            && preg_match('/^\d{4,5}\b/u', $line) !== 1;
    }

    private function normalizeCountry(?string $country): ?string
    {
        $value = mb_strtolower(trim((string) $country));
        if ($value === '') {
            return null;
        }

        return match ($value) {
            'de', 'd', 'deutschland', 'germany' => 'de',
            'at', 'a', 'österreich', 'oesterreich', 'austria' => 'at',
            'ch', 'schweiz', 'switzerland', 'suisse', 'svizzera' => 'ch',
            'fr', 'frankreich', 'france' => 'fr',
            default => preg_match('/^[a-z]{2}$/', $value) === 1 ? $value : null,
        };
    }

    private function countryFromAddress(string $address): ?string
    {
        $lower = mb_strtolower($address);
        if (preg_match('/\b(schweiz|switzerland|suisse|svizzera|svizra)\b/u', $lower) === 1) {
            return 'ch';
        }
        if (preg_match('/\b(österreich|oesterreich|austria)\b/u', $lower) === 1) {
            return 'at';
        }
        if (preg_match('/\b(deutschland|germany)\b/u', $lower) === 1) {
            return 'de';
        }
        if (preg_match('/\bCH[-\s]\d{4}\b/u', $address) === 1) {
            return 'ch';
        }
        if (preg_match('/\bA-\d{4}\b/u', $address) === 1) {
            return 'at';
        }

        return null;
    }

    private function displayContains(string $displayName, ?string $city): bool
    {
        if ($city === null || $city === '') {
            return true;
        }

        return mb_stripos($displayName, $city) !== false;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function looseCandidates(array $lines): array
    {
        $candidates = [implode(', ', $lines)];
        if (count($lines) > 1) {
            $candidates[] = implode(', ', array_slice($lines, 1));
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
        $explicit = trim((string) ($query['countrycodes'] ?? ''));
        if ($explicit === '') {
            $countries = trim((string) config('services.nominatim.countrycodes', ''));
            if ($countries !== '') {
                $query['countrycodes'] = $countries;
            }
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
