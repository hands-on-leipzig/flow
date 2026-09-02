<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DrahtController;
use App\Models\EventTeamField;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeamPublicFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Team public form tests require sqlite.');
        }

        Carbon::setTestNow('2026-09-02');
        $this->createSchema();
        $this->truncateData();
        $this->seedSeason();
        $this->seedBase();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_lookup_returns_404_when_flag_off(): void
    {
        DB::table('event')->where('id', 1)->update(['public_team_data_entry' => false]);
        $this->mockPeople();
        $controller = app(\App\Http\Controllers\Api\TeamPublicFormController::class);

        try {
            $controller->lookup(
                \Illuminate\Http\Request::create('/api/public-team-form/test/lookup', 'GET', ['email' => 'coach@example.com']),
                'test',
            );
            $this->fail('Expected not found exception.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_lookup_returns_404_when_not_coach(): void
    {
        $this->mockPeople();
        $controller = app(\App\Http\Controllers\Api\TeamPublicFormController::class);

        try {
            $controller->lookup(
                \Illuminate\Http\Request::create('/api/public-team-form/test/lookup', 'GET', ['email' => 'stranger@example.com']),
                'test',
            );
            $this->fail('Expected not found exception.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_lookup_single_team_includes_form(): void
    {
        $this->mockPeople();

        $response = $this->getJson('/api/public-team-form/test/lookup?email=coach@example.com');
        $response->assertOk();
        $response->assertJsonCount(1, 'teams');
        $response->assertJsonPath('teams.0.name', 'Team A');
        $response->assertJsonPath('form.team.people_count', 3);
        $this->assertNotNull(collect($response->json('form.columns'))->firstWhere('key', 'photo_consent'));
    }

    public function test_lookup_multi_team_omits_form_until_team_endpoint(): void
    {
        DB::table('team')->insert([
            'id' => 2,
            'event' => 1,
            'first_program' => 1,
            'name' => 'Team B',
            'team_number_hot' => 20,
            'organization' => 'Other',
        ]);
        $this->mockPeople([
            10 => [
                'coaches' => [['email' => 'coach@example.com']],
                'players' => [['email' => 'p1@example.com'], ['email' => 'p2@example.com']],
            ],
            20 => [
                'coaches' => [['email' => 'Coach@example.com']],
                'players' => [],
            ],
        ]);

        $lookup = $this->getJson('/api/public-team-form/test/lookup?email=coach@example.com');
        $lookup->assertOk();
        $lookup->assertJsonCount(2, 'teams');
        $this->assertArrayNotHasKey('form', $lookup->json());

        $team = $this->getJson('/api/public-team-form/test/team/1?email=coach@example.com');
        $team->assertOk();
        $team->assertJsonPath('form.team.id', 1);
    }

    public function test_save_rejects_sum_mismatch_and_non_public_custom(): void
    {
        $this->mockPeople();
        EventTeamField::create([
            'event' => 1,
            'field_key' => 'note',
            'label' => 'Note',
            'type' => 'text',
            'options' => null,
            'sequence' => 1,
            'public_form' => false,
        ]);

        $badSum = $this->postJson('/api/public-team-form/test/save', [
            'email' => 'coach@example.com',
            'team' => 1,
            'photo_consent' => ['unknown' => 1, 'yes' => 0, 'no' => 0],
        ]);
        $badSum->assertStatus(422);

        $badCustom = $this->postJson('/api/public-team-form/test/save', [
            'email' => 'coach@example.com',
            'team' => 1,
            'custom' => ['note' => 'x'],
        ]);
        $badCustom->assertStatus(422);
    }

    public function test_save_happy_path(): void
    {
        $this->mockPeople();
        EventTeamField::create([
            'event' => 1,
            'field_key' => 'note',
            'label' => 'Note',
            'type' => 'text',
            'options' => null,
            'sequence' => 1,
            'public_form' => true,
        ]);

        $response = $this->postJson('/api/public-team-form/test/save', [
            'email' => 'coach@example.com',
            'team' => 1,
            'photo_consent' => ['unknown' => 0, 'yes' => 2, 'no' => 1],
            'meals' => ['standard' => 2, 'vegetarisch' => 1, 'vegan' => 0, 'keine' => 0],
            'custom' => ['note' => 'Hallo'],
        ]);
        $response->assertOk();
        $response->assertJsonPath('form.photo_consent.yes', 2);
        $response->assertJsonPath('form.meals.standard', 2);
        $response->assertJsonPath('form.custom.note', 'Hallo');
        $this->assertDatabaseHas('event_team_photo_count', ['team' => 1, 'bucket' => 'yes', 'count' => 2]);
    }

    public function test_save_photo_without_people_count_returns_422(): void
    {
        $withCoach = new JsonResponse([
            10 => [
                'coaches' => [['email' => 'coach@example.com']],
                'players' => [['email' => 'p1@example.com']],
            ],
        ]);
        $withoutTeam = new JsonResponse([]);

        $this->mock(DrahtController::class, function ($mock) use ($withCoach, $withoutTeam) {
            $mock->shouldReceive('getPeople')
                ->once()
                ->andReturn($withCoach);
            $mock->shouldReceive('getPeople')
                ->once()
                ->andReturn($withoutTeam);
        });

        $response = $this->postJson('/api/public-team-form/test/save', [
            'email' => 'coach@example.com',
            'team' => 1,
            'photo_consent' => ['unknown' => 0, 'yes' => 1, 'no' => 0],
        ]);
        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Personenanzahl für dieses Team fehlt.');
    }

    /**
     * @param  array<int|string, mixed>|null  $people
     */
    private function mockPeople(?array $people = null): void
    {
        $payload = $people ?? [
            10 => [
                'coaches' => [['email' => 'coach@example.com']],
                'players' => [['email' => 'p1@example.com'], ['email' => 'p2@example.com']],
            ],
        ];

        $this->mock(DrahtController::class, function ($mock) use ($payload) {
            $mock->shouldReceive('getPeople')->andReturn(new JsonResponse($payload));
        });
    }

    private function seedSeason(): void
    {
        if (DB::table('m_season')->count() === 0) {
            DB::table('m_season')->insert([
                'id' => 1,
                'year' => 2026,
            ]);
        }
    }

    private function seedBase(): void
    {
        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Test',
            'slug' => 'test',
            'regional_partner' => 1,
            'level' => 1,
            'season' => 1,
            'date' => '2026-09-02',
            'days' => 1,
            'collect_meal' => true,
            'public_team_data_entry' => true,
        ]);
        DB::table('m_first_program')->insert([
            'id' => 1,
            'name' => 'EXPLORE',
            'sequence' => 1,
        ]);
        DB::table('event_program')->insert([
            'event' => 1,
            'first_program' => 1,
            'draht_id' => 665,
        ]);
        DB::table('team')->insert([
            'id' => 1,
            'event' => 1,
            'first_program' => 1,
            'name' => 'Team A',
            'team_number_hot' => 10,
            'organization' => 'Test School',
        ]);
        foreach ([
            ['standard', 'Standard', 1],
            ['vegetarisch', 'Vegetarisch', 2],
            ['vegan', 'Vegan', 3],
            ['keine', 'Keine', 4],
        ] as [$value, $label, $sequence]) {
            DB::table('event_volunteer_meal_option')->insert([
                'event' => 1,
                'value' => $value,
                'label' => $label,
                'sequence' => $sequence,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function truncateData(): void
    {
        foreach ([
            'event_team_photo_count',
            'event_team_meal_count',
            'event_team_field_value',
            'event_team_field',
            'event_volunteer_meal_option',
            'event_program',
            'm_first_program',
            'team',
            'event',
            'm_season',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('m_season')) {
            Schema::create('m_season', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->unsignedSmallInteger('year');
            });
        }
        if (! Schema::hasTable('event')) {
            Schema::create('event', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->unsignedInteger('regional_partner')->nullable();
                $table->unsignedTinyInteger('level')->default(1);
                $table->unsignedInteger('season')->nullable();
                $table->date('date')->nullable();
                $table->unsignedTinyInteger('days')->default(1);
                $table->boolean('collect_meal')->default(true);
                $table->boolean('public_team_data_entry')->default(false);
            });
        } elseif (! Schema::hasColumn('event', 'public_team_data_entry')) {
            Schema::table('event', function (Blueprint $table) {
                $table->boolean('public_team_data_entry')->default(false);
            });
        }
        if (! Schema::hasTable('m_first_program')) {
            Schema::create('m_first_program', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->string('name')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
            });
        }
        if (! Schema::hasTable('event_program')) {
            Schema::create('event_program', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program')->nullable();
                $table->unsignedInteger('draht_id')->nullable();
            });
        } elseif (! Schema::hasColumn('event_program', 'draht_id')) {
            Schema::table('event_program', function (Blueprint $table) {
                $table->unsignedInteger('draht_id')->nullable();
            });
        }
        if (! Schema::hasTable('team')) {
            Schema::create('team', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program')->nullable();
                $table->string('name');
                $table->unsignedInteger('team_number_hot')->nullable();
                $table->string('organization')->nullable();
            });
        }
        if (! Schema::hasTable('event_volunteer_meal_option')) {
            Schema::create('event_volunteer_meal_option', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->string('value', 64);
                $table->string('label', 120);
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('event_team_field')) {
            Schema::create('event_team_field', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->string('field_key', 64);
                $table->string('label', 120);
                $table->string('type', 20);
                $table->json('options')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->boolean('public_form')->default(false);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('event_team_field_value')) {
            Schema::create('event_team_field_value', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('team');
                $table->unsignedInteger('event_team_field');
                $table->text('value');
                $table->timestamp('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('event_team_meal_count')) {
            Schema::create('event_team_meal_count', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('team');
                $table->string('meal_value', 64);
                $table->unsignedInteger('count')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('event_team_photo_count')) {
            Schema::create('event_team_photo_count', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('team');
                $table->string('bucket', 16);
                $table->unsignedInteger('count')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }
    }
}
