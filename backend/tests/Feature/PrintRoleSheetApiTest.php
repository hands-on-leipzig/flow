<?php

namespace Tests\Feature;

use App\Http\Middleware\KeycloakJwtMiddleware;
use App\Services\PublicPlanService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PrintRoleSheetApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Print role-sheet API tests require sqlite.');
        }

        $this->withoutMiddleware(KeycloakJwtMiddleware::class);
        Schema::dropAllTables();
        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_catalog_404_when_no_plan(): void
    {
        $this->getJson('/api/print/99/role-sheets/catalog')
            ->assertStatus(404)
            ->assertExactJson(['error' => 'Plan not found']);
    }

    public function test_download_422_when_role_ids_empty_or_unknown(): void
    {
        DB::table('plan')->insert(['id' => 1, 'event' => 7]);

        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->andReturn([
            'title_short' => 'Challenge Event Test',
            'programs' => [],
            'roles' => [
                [
                    'id' => 4,
                    'name' => 'Juror:in',
                    'first_program' => 3,
                    'options' => [],
                ],
            ],
        ]);
        $this->app->instance(PublicPlanService::class, $publicPlan);

        $this->postJson('/api/print/7/role-sheets', ['role_ids' => []])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Bitte mindestens eine Rolle wählen.']);

        $this->postJson('/api/print/7/role-sheets', ['role_ids' => [4, 14]])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Bitte mindestens eine Rolle wählen.']);
    }
}
