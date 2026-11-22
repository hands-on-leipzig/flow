<?php

namespace Tests\Unit;

use App\Services\GeocodeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.nominatim.min_interval' => 0]);
    }

    private function hit(): array
    {
        return [['lat' => '51.34', 'lon' => '12.37', 'display_name' => 'Leipzig']];
    }

    public function test_address_uses_the_german_postal_code_and_skips_the_venue_name(): void
    {
        $queries = [];
        Http::fake(function (Request $request) use (&$queries) {
            $data = $request->data();
            $queries[] = $data;

            $matched = ($data['postalcode'] ?? '') === '04356'
                && ($data['street'] ?? '') === 'Messeallee 1'
                && ($data['countrycodes'] ?? '') === 'de';

            return Http::response($matched ? $this->hit() : []);
        });

        $result = app(GeocodeService::class)->address("Leipziger Messe, Halle 5\nMesseallee 1\n04356 Leipzig");

        $this->assertNotNull($result);
        $this->assertSame('Messeallee 1, 04356', $result['query']);
        $this->assertSame('de', $queries[0]['countrycodes'] ?? null);
        $this->assertArrayNotHasKey('q', $queries[0]);
    }

    public function test_address_falls_back_to_the_bare_city(): void
    {
        Http::fake(function (Request $request) {
            $data = $request->data();

            return Http::response(
                ($data['city'] ?? '') === 'Leipzig' && ($data['countrycodes'] ?? '') === 'de'
                    ? $this->hit()
                    : []
            );
        });

        $result = app(GeocodeService::class)->address("Irgendein Haus\nUnbekannte Straße 9\n04356 Leipzig");

        $this->assertNotNull($result);
        $this->assertSame('Leipzig', $result['query']);
    }

    public function test_swiss_postal_code_stays_in_switzerland(): void
    {
        Http::fake(function (Request $request) {
            $data = $request->data();
            if (($data['countrycodes'] ?? '') === 'de') {
                return Http::response([[
                    'lat' => '51.1119449',
                    'lon' => '6.3949392',
                    'display_name' => 'Chur, Am Tömp, Mönchengladbach, Deutschland',
                ]]);
            }
            if (($data['postalcode'] ?? '') === '7000' && ($data['countrycodes'] ?? '') === 'ch') {
                return Http::response([[
                    'lat' => '46.8565943',
                    'lon' => '9.5286710',
                    'display_name' => '7000, Chur, Graubünden, Schweiz',
                ]]);
            }

            return Http::response([]);
        });

        $address = "Pädagogische Hochschule Graubünden\nScalärastraße 17\n7000 Chur";
        $result = app(GeocodeService::class)->address($address, 'CH');

        $this->assertNotNull($result);
        $this->assertSame(46.8565943, $result['lat']);
        $this->assertSame(9.528671, $result['lon']);
    }

    public function test_four_digit_code_without_country_does_not_search_germany(): void
    {
        $countries = [];
        Http::fake(function (Request $request) use (&$countries) {
            $data = $request->data();
            $countries[] = $data['countrycodes'] ?? '';
            if (($data['postalcode'] ?? '') === '7000' && ($data['countrycodes'] ?? '') === 'ch') {
                return Http::response([[
                    'lat' => '46.85',
                    'lon' => '9.53',
                    'display_name' => '7000, Chur, Schweiz',
                ]]);
            }
            if (($data['countrycodes'] ?? '') === 'at') {
                return Http::response([[
                    'lat' => '47.85',
                    'lon' => '16.52',
                    'display_name' => '7000, Eisenstadt, Österreich',
                ]]);
            }

            return Http::response([]);
        });

        $result = app(GeocodeService::class)->address("Scalärastraße 17\n7000 Chur");

        $this->assertNotNull($result);
        $this->assertSame(46.85, $result['lat']);
        $this->assertNotContains('de', $countries);
    }

    public function test_city_uses_free_text_so_villages_resolve(): void
    {
        Http::fake(function (Request $request) {
            return Http::response(($request->data()['q'] ?? '') === 'Machern' ? $this->hit() : []);
        });

        $result = app(GeocodeService::class)->city('Machern');

        $this->assertNotNull($result);
        $this->assertSame(51.34, $result['lat']);
    }

    public function test_city_strips_district_and_parenthesis_suffixes(): void
    {
        Http::fake(function (Request $request) {
            return Http::response(($request->data()['q'] ?? '') === 'Leipzig' ? $this->hit() : []);
        });

        $this->assertNotNull(app(GeocodeService::class)->city('Leipzig OT Liebertwolkwitz'));
        $this->assertNotNull(app(GeocodeService::class)->city('Leipzig (Sachsen)'));
    }

    public function test_hits_are_cached(): void
    {
        Http::fake(fn () => Http::response($this->hit()));

        app(GeocodeService::class)->city('Leipzig');
        app(GeocodeService::class)->city('Leipzig');

        Http::assertSentCount(1);
    }

    public function test_misses_are_cached_so_they_do_not_burn_the_rate_limit(): void
    {
        Http::fake(fn () => Http::response([]));

        $this->assertNull(app(GeocodeService::class)->city('Nirgendwo'));
        $this->assertNull(app(GeocodeService::class)->city('Nirgendwo'));

        Http::assertSentCount(2); // free text plus structured, then answered from cache
    }

    public function test_cache_only_lookup_does_not_call_nominatim(): void
    {
        Http::fake(fn () => Http::response($this->hit()));

        $this->assertNull(app(GeocodeService::class)->city('Leipzig', cacheOnly: true));
        Http::assertNothingSent();

        app(GeocodeService::class)->city('Leipzig');
        $this->assertNotNull(app(GeocodeService::class)->city('Leipzig', cacheOnly: true));
    }
}
