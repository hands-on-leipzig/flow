<?php

namespace Tests\Unit;

use App\Services\PublicPlanService;
use App\Services\RoleFetcherService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PublicPlanServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('PublicPlanService tests require sqlite.');
        }

        $this->createSchema();
        $this->seedPlan();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_roles_omits_public_plan_zero(): void
    {
        $this->bindRoles([
            $this->roleRow(4, publicPlan: 1, name: 'Juror:in'),
            $this->roleRow(33, publicPlan: 0, name: 'DJ'),
            $this->roleRow(14, publicPlan: 1, name: 'Publikum', differentiationType: null, differentiationSource: null, differentiationParameter: null),
        ]);

        $payload = app(PublicPlanService::class)->getRoles(1);
        $ids = collect($payload['roles'])->pluck('id')->all();

        $this->assertSame([4, 14], $ids);
    }

    public function test_lane_option_labels_use_group_label(): void
    {
        $this->bindRoles([
            $this->roleRow(
                4,
                publicPlan: 1,
                name: 'Juror:in',
                groupLabel: 'Jury-Gruppe',
                differentiationType: 'number',
                differentiationSource: 'select 3 as n',
                differentiationParameter: 'lane',
            ),
        ]);

        $payload = app(PublicPlanService::class)->getRoles(1);
        $labels = collect($payload['roles'][0]['options'])->pluck('label')->all();

        $this->assertSame(['Jury-Gruppe 1', 'Jury-Gruppe 2', 'Jury-Gruppe 3'], $labels);
    }

    private function bindRoles(array $roles): void
    {
        $fetcher = Mockery::mock(RoleFetcherService::class);
        $fetcher->shouldReceive('fetchRoles')->andReturn(collect($roles));
        $this->app->instance(RoleFetcherService::class, $fetcher);
    }

    private function roleRow(
        int $id,
        int $publicPlan,
        string $name,
        ?string $groupLabel = null,
        ?string $differentiationType = 'number',
        ?string $differentiationSource = 'select 0 as n',
        ?string $differentiationParameter = 'lane',
    ): object {
        return (object) [
            'id' => $id,
            'name' => $name,
            'name_short' => null,
            'first_program' => 3,
            'first_program_name' => 'Challenge',
            'color_hex' => 'ed1c24',
            'logo_stem' => 'fll_challenge',
            'logo_white' => 'challenge.png',
            'differentiation_type' => $differentiationType,
            'differentiation_source' => $differentiationSource,
            'differentiation_parameter' => $differentiationParameter,
            'public_plan' => $publicPlan,
            'group_label' => $groupLabel,
        ];
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->string('slug')->nullable();
            $table->boolean('check_in_enabled')->default(false);
            $table->boolean('cockpit_enabled')->default(false);
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->string('name')->nullable();
        });

        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program')->nullable();
            $table->string('name')->nullable();
            $table->string('location')->nullable();
        });

        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('team');
            $table->unsignedInteger('team_number_plan');
            $table->boolean('noshow')->default(false);
        });

        Schema::create('table_event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedTinyInteger('table_number');
            $table->string('table_name')->nullable();
        });
    }

    private function seedPlan(): void
    {
        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Test Event',
            'level' => 1,
            'slug' => 'test',
            'check_in_enabled' => 0,
            'cockpit_enabled' => 0,
        ]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1, 'name' => 'Plan']);
    }
}
