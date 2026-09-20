<?php

namespace Tests\Feature;

use App\Http\Middleware\KeycloakJwtMiddleware;
use App\Print\GotenbergChromium;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrintOverviewSheetApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Print overview-sheet API tests require sqlite.');
        }

        $this->withoutMiddleware(KeycloakJwtMiddleware::class);
        Schema::dropAllTables();
        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
        });
        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->date('date')->nullable();
        });
    }

    public function test_start_404_when_no_plan(): void
    {
        $this->postJson('/api/print/99/overview-sheet')
            ->assertStatus(404)
            ->assertExactJson(['error' => 'Plan not found']);
    }

    public function test_start_422_when_role_is_not_audience(): void
    {
        DB::table('plan')->insert(['id' => 42, 'event' => 7]);

        $this->postJson('/api/print/7/overview-sheet', ['role_id' => 4])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Kein Publikum-Plan für diese Auswahl.']);
    }

    public function test_start_503_when_gotenberg_is_not_configured(): void
    {
        DB::table('plan')->insert(['id' => 42, 'event' => 7]);
        config(['services.gotenberg.url' => null]);

        $this->postJson('/api/print/7/overview-sheet')
            ->assertStatus(503)
            ->assertExactJson(['error' => 'PDF-Dienst nicht erreichbar.']);
    }

    public function test_show_streams_pdf_after_start(): void
    {
        DB::table('plan')->insert(['id' => 42, 'event' => 7]);
        DB::table('event')->insert(['id' => 7, 'date' => '2026-05-16']);
        config([
            'services.gotenberg.url' => 'http://gotenberg.test',
            'services.gotenberg.print_page_base_url' => 'http://host.docker.internal:5173',
            'app.frontend_url' => 'http://localhost:5173',
        ]);

        Http::fake([
            'http://gotenberg.test/forms/chromium/convert/url' => Http::response(
                '%PDF-1.4 fake',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);

        $start = $this->postJson('/api/print/7/overview-sheet');
        $start->assertStatus(202)
            ->assertJsonPath('filename', 'FLOW_Uebersichtsplan_(16.05.26).pdf')
            ->assertJsonStructure(['id', 'filename']);
        $jobId = $start->json('id');

        $response = $this->get("/api/print/7/overview-sheet/{$jobId}");
        $response->assertOk();
        $this->assertSame('%PDF-1.4 fake', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame(
            'FLOW_Uebersichtsplan_(16.05.26).pdf',
            $response->headers->get('X-Filename'),
        );

        Http::assertSent(function (Request $request) {
            return $request->url() === 'http://gotenberg.test/forms/chromium/convert/url'
                && $request->isMultipart()
                && str_contains($request->body(), 'http://host.docker.internal:5173/public-schedule/42/print?role=14')
                && ! str_contains($request->body(), 'size=a3')
                && str_contains($request->body(), GotenbergChromium::WAIT_FOR_PRINT_READY)
                && str_contains($request->body(), 'preferCssPageSize')
                && str_contains($request->body(), 'printBackground');
        });
    }

    public function test_start_422_when_paper_is_invalid(): void
    {
        DB::table('plan')->insert(['id' => 42, 'event' => 7]);

        $this->postJson('/api/print/7/overview-sheet', ['paper' => 'letter'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Ungültiges Format.']);
    }

    public function test_show_streams_a3_pdf_after_start(): void
    {
        DB::table('plan')->insert(['id' => 42, 'event' => 7]);
        DB::table('event')->insert(['id' => 7, 'date' => '2026-05-16']);
        config([
            'services.gotenberg.url' => 'http://gotenberg.test',
            'services.gotenberg.print_page_base_url' => 'http://host.docker.internal:5173',
            'app.frontend_url' => 'http://localhost:5173',
        ]);

        Http::fake([
            'http://gotenberg.test/forms/chromium/convert/url' => Http::response(
                '%PDF-1.4 fake',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);

        $start = $this->postJson('/api/print/7/overview-sheet', ['paper' => 'a3']);
        $start->assertStatus(202)
            ->assertJsonPath('filename', 'FLOW_Uebersichtsplan_A3_(16.05.26).pdf')
            ->assertJsonStructure(['id', 'filename']);
        $jobId = $start->json('id');

        $response = $this->get("/api/print/7/overview-sheet/{$jobId}");
        $response->assertOk();
        $this->assertSame(
            'FLOW_Uebersichtsplan_A3_(16.05.26).pdf',
            $response->headers->get('X-Filename'),
        );

        Http::assertSent(function (Request $request) {
            return $request->url() === 'http://gotenberg.test/forms/chromium/convert/url'
                && $request->isMultipart()
                && str_contains($request->body(), 'http://host.docker.internal:5173/public-schedule/42/print?role=14&size=a3')
                && str_contains($request->body(), '11.69')
                && str_contains($request->body(), '16.54');
        });
    }

    public function test_print_page_url_defaults_to_frontend_url(): void
    {
        config([
            'services.gotenberg.print_page_base_url' => null,
            'app.frontend_url' => 'https://dev.flow.hands-on-technology.org',
        ]);

        $url = app(GotenbergChromium::class)->printPageUrl(1372, 6);

        $this->assertSame(
            'https://dev.flow.hands-on-technology.org/public-schedule/1372/print?role=6',
            $url,
        );
    }

    public function test_print_page_url_appends_a3_size(): void
    {
        config([
            'services.gotenberg.print_page_base_url' => null,
            'app.frontend_url' => 'https://dev.flow.hands-on-technology.org',
        ]);

        $url = app(GotenbergChromium::class)->printPageUrl(1372, 6, 'a3');

        $this->assertSame(
            'https://dev.flow.hands-on-technology.org/public-schedule/1372/print?role=6&size=a3',
            $url,
        );
    }
}
