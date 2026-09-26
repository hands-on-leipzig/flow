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

    public function test_address_falls_back_to_street_and_city_when_the_venue_line_fails(): void
    {
        $queries = [];
        Http::fake(function (Request $request) use (&$queries) {
            $query = $request->data()['q'] ?? '';
            $queries[] = $query;

            return Http::response($query === 'Messeallee 1, 04356 Leipzig' ? $this->hit() : []);
        });

        $result = app(GeocodeService::class)->address("Leipziger Messe, Halle 5\nMesseallee 1\n04356 Leipzig");

        $this->assertNotNull($result);
        $this->assertSame('Messeallee 1, 04356 Leipzig', $result['query']);
        $this->assertSame('Leipziger Messe, Halle 5, Messeallee 1, 04356 Leipzig', $queries[0]);
    }

    public function test_address_falls_back_to_the_bare_city(): void
    {
        Http::fake(function (Request $request) {
            return Http::response(($request->data()['q'] ?? '') === 'Leipzig' ? $this->hit() : []);
        });

        $result = app(GeocodeService::class)->address("Irgendein Haus\nUnbekannte Straße 9\n04356 Leipzig");

        $this->assertNotNull($result);
        $this->assertSame('Leipzig', $result['query']);
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
