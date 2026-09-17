<?php

namespace Tests\Unit;

use App\Print\RoleSheetCatalog;
use App\Services\PublicPlanService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class RoleSheetCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('RoleSheetCatalog tests require sqlite.');
        }

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

    public function test_omits_publikum_ids_and_per_program_moderator(): void
    {
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);

        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->with(1)->andReturn([
            'title_short' => 'Challenge Event Test',
            'programs' => [
                ['id' => 3, 'display_name' => 'Challenge', 'sequence' => 2],
            ],
            'roles' => [
                $this->role(4, 'Juror:in', 3),
                $this->role(14, 'Publikum', 3),
                $this->role(6, 'Explore-Publikum', 1),
                $this->role(10, 'Future-Publikum', 8),
                $this->role(24, 'Publikum', null),
                $this->role(2, 'Moderator:in', null),
                $this->role(99, 'Moderator:in', 3),
                $this->role(7, 'Publikum', 3),
            ],
        ]);

        $catalog = new RoleSheetCatalog($publicPlan);
        $payload = $catalog->forEvent(1);

        $this->assertNotNull($payload);
        $this->assertSame(1, $payload['plan_id']);
        $this->assertSame(1, $payload['event_id']);
        $this->assertSame('Challenge Event Test', $payload['title_short']);
        $this->assertSame([4, 2], array_column($payload['roles'], 'id'));
        $this->assertSame('Moderator:in', $payload['roles'][1]['name']);
        $this->assertNull($payload['roles'][1]['first_program']);
    }

    public function test_returns_null_when_no_plan(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->never();
        $catalog = new RoleSheetCatalog($publicPlan);

        $this->assertNull($catalog->forEvent(99));
    }

    /**
     * @return array<string, mixed>
     */
    private function role(int $id, string $name, ?int $firstProgram): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'first_program' => $firstProgram,
            'color_hex' => 'ed1c24',
            'logo_stem' => 'fll_challenge',
            'options' => [],
        ];
    }
}
