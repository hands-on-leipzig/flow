<?php

namespace Tests\Feature;

use App\Enums\FirstProgram;
use App\Http\Controllers\Api\DrahtController;
use App\Http\Middleware\KeycloakJwtMiddleware;
use App\Services\LabelPdfService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class NameTagsApiTest extends TestCase
{
    /** @var list<array{person_name: string, team_name: string, program: string}> */
    private array $capturedTags = [];

    private mixed $draht = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Name tags API tests require sqlite.');
        }

        $this->withoutMiddleware(KeycloakJwtMiddleware::class);
        Schema::dropAllTables();
        $this->createSchema();
        $this->seedBase();
        $this->mockPdf();
        $this->draht = null;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_empty_filters_return_german_404(): void
    {
        $this->postJson('/api/export/name-tags/1', [])
            ->assertStatus(404)
            ->assertExactJson([
                'error' => 'Keine Personen gefunden, die den ausgewählten Filtern entsprechen.',
            ]);
    }

    public function test_get_without_body_returns_german_404(): void
    {
        $this->getJson('/api/export/name-tags/1')
            ->assertStatus(404)
            ->assertExactJson([
                'error' => 'Keine Personen gefunden, die den ausgewählten Filtern entsprechen.',
            ]);
    }

    public function test_filename_is_flow_namensschilder(): void
    {
        $this->seedChallengeTeam(planNumber: 1, noshow: 0);
        $this->mockDraht(665, [
            1001 => [
                'coaches' => [],
                'players' => [['firstname' => 'Ada', 'name' => 'Lovelace']],
            ],
        ]);

        $response = $this->postJson('/api/export/name-tags/1', [
            'filters' => [
                'program:3' => ['players' => true, 'coaches' => false, 'helpers' => false],
            ],
        ]);

        $response->assertOk();
        $filename = $response->headers->get('X-Filename');
        $this->assertNotNull($filename);
        $this->assertStringStartsWith('FLOW_Namensschilder_', $filename);
        $this->assertStringEndsWith('.pdf', $filename);
    }

    public function test_challenge_cap_does_not_drop_future_team(): void
    {
        $this->seedChallengeTeam(planNumber: 3, noshow: 0);
        $this->seedFutureTeam(planNumber: 3, noshow: 0);
        $this->setCap('c_teams', 2);
        $this->setCap('f8_teams', 8);
        $this->mockDraht(665, [
            1001 => [
                'players' => [['firstname' => 'Chal', 'name' => 'Overflow']],
                'coaches' => [],
            ],
        ]);
        $this->mockDraht(800, [
            2001 => [
                'players' => [['firstname' => 'Fay', 'name' => 'Future']],
                'coaches' => [],
            ],
        ]);

        $this->postJson('/api/export/name-tags/1', [
            'filters' => [
                'program:3' => ['players' => true],
                'program:8' => ['players' => true],
            ],
        ])->assertOk();

        $this->assertCount(1, $this->capturedTags);
        $this->assertSame('Fay Future', $this->capturedTags[0]['person_name']);
        $this->assertSame('future_8', $this->capturedTags[0]['program']);
    }

    public function test_noshow_team_is_excluded(): void
    {
        $this->seedChallengeTeam(planNumber: 1, noshow: 1);
        $this->mockDraht(665, [
            1001 => [
                'players' => [['firstname' => 'No', 'name' => 'Show']],
                'coaches' => [],
            ],
        ]);

        $this->postJson('/api/export/name-tags/1', [
            'filters' => ['program:3' => ['players' => true]],
        ])
            ->assertStatus(404);
    }

    public function test_overflow_team_is_excluded(): void
    {
        $this->seedChallengeTeam(planNumber: 5, noshow: 0);
        $this->setCap('c_teams', 2);
        $this->mockDraht(665, [
            1001 => [
                'players' => [['firstname' => 'Over', 'name' => 'Flow']],
                'coaches' => [],
            ],
        ]);

        $this->postJson('/api/export/name-tags/1', [
            'filters' => ['program:3' => ['players' => true]],
        ])
            ->assertStatus(404);
    }

    public function test_two_assignments_yield_two_helper_stickers(): void
    {
        $this->seedHelperPerson(10, 'Max', 'Muster');
        $this->seedCatalogRole(6, 'Juror:in', FirstProgram::CHALLENGE->value);
        $this->seedStaffingRole(1, 6, 'Juror:in', 1);
        $this->seedStaffingRole(2, 6, 'Jury-Helfer:in', 2);
        DB::table('event_staffing_assignment')->insert([
            ['event_staffing_role' => 1, 'event_staffing_group' => null, 'volunteer_person' => 10, 'created_at' => now()],
            ['event_staffing_role' => 2, 'event_staffing_group' => null, 'volunteer_person' => 10, 'created_at' => now()],
        ]);

        $this->postJson('/api/export/name-tags/1', [
            'filters' => ['program:3' => ['helpers' => true]],
        ])->assertOk();

        $this->assertCount(2, $this->capturedTags);
        $this->assertSame('Max Muster', $this->capturedTags[0]['person_name']);
        $this->assertSame('Max Muster', $this->capturedTags[1]['person_name']);
        $this->assertSame('challenge', $this->capturedTags[0]['program']);
    }

    public function test_cross_helper_uses_default_program_key(): void
    {
        $this->seedHelperPerson(11, 'Kim', 'Cross');
        $this->seedCatalogRole(14, 'Publikum', null);
        $this->seedStaffingRole(3, 14, 'Publikum', 1);
        DB::table('event_staffing_assignment')->insert([
            'event_staffing_role' => 3,
            'event_staffing_group' => null,
            'volunteer_person' => 11,
            'created_at' => now(),
        ]);

        $this->postJson('/api/export/name-tags/1', [
            'filters' => ['cross' => ['helpers' => true]],
        ])->assertOk();

        $this->assertCount(1, $this->capturedTags);
        $this->assertSame('default', $this->capturedTags[0]['program']);
        $this->assertSame('Publikum', $this->capturedTags[0]['team_name']);
    }

    public function test_missing_logo_id_is_ok(): void
    {
        $this->seedChallengeTeam(planNumber: 1, noshow: 0);
        $this->mockDraht(665, [
            1001 => [
                'players' => [['firstname' => 'Ada', 'name' => 'Lovelace']],
                'coaches' => [],
            ],
        ]);

        $this->postJson('/api/export/name-tags/1', [
            'filters' => ['program:3' => ['players' => true]],
        ])->assertOk();
    }

    public function test_foreign_logo_id_returns_422(): void
    {
        DB::table('logo')->insert(['id' => 99, 'path' => 'logos/other.png']);

        $this->postJson('/api/export/name-tags/1', [
            'logo_id' => 99,
            'filters' => ['cross' => ['helpers' => true]],
        ])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Logo gehört nicht zu diesem Event.']);
    }

    public function test_assigned_logo_id_is_accepted(): void
    {
        $this->seedHelperPerson(12, 'Lia', 'Logo');
        $this->seedCatalogRole(14, 'Publikum', null);
        $this->seedStaffingRole(4, 14, 'Publikum', 1);
        DB::table('event_staffing_assignment')->insert([
            'event_staffing_role' => 4,
            'event_staffing_group' => null,
            'volunteer_person' => 12,
            'created_at' => now(),
        ]);
        DB::table('logo')->insert(['id' => 5, 'path' => 'logos/event.png']);
        DB::table('event_logo')->insert(['event' => 1, 'logo' => 5, 'sort_order' => 0]);

        $this->postJson('/api/export/name-tags/1', [
            'logo_id' => 5,
            'filters' => ['cross' => ['helpers' => true]],
        ])->assertOk();
    }

    public function test_volunteer_labels_route_is_gone(): void
    {
        $this->postJson('/api/export/volunteer-labels/1', ['volunteers' => []])
            ->assertStatus(404);
    }

    private function mockPdf(): void
    {
        $this->capturedTags = [];
        $pdf = Mockery::mock(LabelPdfService::class);
        $pdf->shouldReceive('generateNameTags')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (array $nameTags) {
                $this->capturedTags = $nameTags;

                return "%PDF-1.4\n".str_repeat('x', 200);
            });
        $this->app->instance(LabelPdfService::class, $pdf);
    }

    private function mockDraht(int $drahtId, array $payload): void
    {
        if ($this->draht === null) {
            $this->draht = Mockery::mock(DrahtController::class);
            $this->draht->shouldReceive('getPeople')
                ->andReturn(new JsonResponse([]))
                ->byDefault();
            $this->app->instance(DrahtController::class, $this->draht);
        }
        $this->draht->shouldReceive('getPeople')
            ->with($drahtId)
            ->andReturn(new JsonResponse($payload));
    }

    private function seedBase(): void
    {
        DB::table('m_first_program')->insert([
            ['id' => 2, 'name' => 'EXPLORE', 'sequence' => 1],
            ['id' => 3, 'name' => 'CHALLENGE', 'sequence' => 2],
            ['id' => 8, 'name' => 'FUTURE_8', 'sequence' => 3],
        ]);
        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Test',
            'date' => '2026-09-21',
        ]);
        DB::table('event_program')->insert([
            ['id' => 1, 'event' => 1, 'first_program' => 3, 'draht_id' => 665],
            ['id' => 2, 'event' => 1, 'first_program' => 8, 'draht_id' => 800],
        ]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
        DB::table('m_parameter')->insert([
            ['id' => 22, 'name' => 'c_teams'],
            ['id' => 23, 'name' => 'e_teams'],
            ['id' => 24, 'name' => 'f8_teams'],
        ]);
    }

    private function seedChallengeTeam(int $planNumber, int $noshow): void
    {
        DB::table('team')->insert([
            'id' => 10,
            'event' => 1,
            'first_program' => FirstProgram::CHALLENGE->value,
            'team_number_hot' => 1001,
            'name' => 'Alpha',
        ]);
        DB::table('team_plan')->insert([
            'plan' => 1,
            'team' => 10,
            'team_number_plan' => $planNumber,
            'noshow' => $noshow,
        ]);
    }

    private function seedFutureTeam(int $planNumber, int $noshow): void
    {
        DB::table('team')->insert([
            'id' => 20,
            'event' => 1,
            'first_program' => FirstProgram::FUTURE_8->value,
            'team_number_hot' => 2001,
            'name' => 'Future Team',
        ]);
        DB::table('team_plan')->insert([
            'plan' => 1,
            'team' => 20,
            'team_number_plan' => $planNumber,
            'noshow' => $noshow,
        ]);
    }

    private function setCap(string $name, int $value): void
    {
        $paramId = (int) DB::table('m_parameter')->where('name', $name)->value('id');
        DB::table('plan_param_value')->updateOrInsert(
            ['plan' => 1, 'parameter' => $paramId],
            ['set_value' => (string) $value],
        );
    }

    private function seedHelperPerson(int $id, string $first, string $last): void
    {
        DB::table('volunteer_person')->insert([
            'id' => $id,
            'regional_partner' => 1,
            'first_name' => $first,
            'last_name' => $last,
            'updated_at' => now(),
        ]);
    }

    private function seedCatalogRole(int $id, string $name, ?int $firstProgram): void
    {
        DB::table('m_role')->insert([
            'id' => $id,
            'name' => $name,
            'first_program' => $firstProgram,
        ]);
    }

    private function seedStaffingRole(int $id, ?int $mRole, string $label, int $sequence): void
    {
        DB::table('event_staffing_role')->insert([
            'id' => $id,
            'event' => 1,
            'm_role' => $mRole,
            'label' => $label,
            'sequence' => $sequence,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('sequence')->default(0);
        });
        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('season')->nullable();
            $table->date('date')->nullable();
        });
        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('draht_id')->nullable();
        });
        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
        });
        Schema::create('m_parameter', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('plan_param_value', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('plan');
            $table->unsignedInteger('parameter');
            $table->string('set_value')->nullable();
        });
        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->integer('team_number_hot')->nullable();
            $table->string('name');
        });
        Schema::create('team_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('plan');
            $table->unsignedInteger('team');
            $table->integer('team_number_plan')->nullable();
            $table->integer('noshow')->nullable();
        });
        Schema::create('volunteer_person', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('regional_partner')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('m_role', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('first_program')->nullable();
        });
        Schema::create('event_staffing_role', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('m_role')->nullable();
            $table->string('label')->nullable();
            $table->string('group_label')->nullable();
            $table->unsignedInteger('sequence')->default(0);
        });
        Schema::create('event_staffing_group', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event_staffing_role');
            $table->unsignedSmallInteger('group_index')->default(1);
        });
        Schema::create('event_staffing_assignment', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event_staffing_role');
            $table->unsignedInteger('event_staffing_group')->nullable();
            $table->unsignedInteger('volunteer_person');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('logo', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('path')->nullable();
        });
        Schema::create('event_logo', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->unsignedInteger('logo');
            $table->unsignedInteger('sort_order')->default(0);
        });
        Schema::create('m_season', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
        });
    }
}
