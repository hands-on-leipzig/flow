<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use App\Services\EnrollmentsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class EnrollmentsServiceTest extends TestCase
{
    /** @var array<int, array{ok: bool, data: array<string, mixed>}> */
    private array $drahtByEvent = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('EnrollmentsService tests require sqlite.');
        }

        $this->createSchema();
        $this->drahtByEvent = [];

        $draht = Mockery::mock(DrahtController::class);
        $draht->shouldReceive('fetchScheduleData')
            ->andReturnUsing(function (Event $event) {
                return $this->drahtByEvent[(int) $event->id] ?? [
                    'ok' => true,
                    'data' => ['programs' => []],
                ];
            });
        $this->app->instance(DrahtController::class, $draht);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_histogram_buckets_enrolled_counts_and_26_plus(): void
    {
        $this->seedCatalog();
        $this->insertEvent(1, 'Alpha', '2026-01-10', [
            $this->program(FirstProgram::EXPLORE->value, 101),
            $this->program(FirstProgram::CHALLENGE->value, 102),
        ]);
        $this->insertEvent(2, 'Beta', '2026-01-11', [
            $this->program(FirstProgram::EXPLORE->value, 201),
            $this->program(FirstProgram::CHALLENGE->value, 202),
        ]);
        $this->setDraht(1, [
            $this->drahtProgram(FirstProgram::EXPLORE->value, 101, 8),
            $this->drahtProgram(FirstProgram::CHALLENGE->value, 102, 26),
        ]);
        $this->setDraht(2, [
            $this->drahtProgram(FirstProgram::EXPLORE->value, 201, 8),
            $this->drahtProgram(FirstProgram::CHALLENGE->value, 202, 4),
        ]);

        $result = app(EnrollmentsService::class)->forSeason(2);

        $this->assertSame(2, $result['event_count']);
        $this->assertSame(2, $this->cell($result, 8, 'explore'));
        $this->assertSame(['Alpha', 'Beta'], $this->events($result, 8, 'explore'));
        $this->assertSame(1, $this->cell($result, 4, 'challenge'));
        $this->assertSame(['Beta'], $this->events($result, 4, 'challenge'));
        $this->assertSame(1, $this->cell($result, '26+', 'challenge'));
        $this->assertSame(['Alpha'], $this->events($result, '26+', 'challenge'));
        $this->assertSame(0, $this->cell($result, 8, 'challenge'));
    }

    public function test_hover_and_tables_sort_by_event_name(): void
    {
        $this->seedCatalog();
        $this->insertEvent(1, 'Zebra', '2026-01-01', [
            $this->program(FirstProgram::EXPLORE->value, 101),
            $this->program(FirstProgram::CHALLENGE->value, 102),
            $this->program(FirstProgram::FUTURE_8->value, 103),
        ]);
        $this->insertEvent(2, 'Alpha', '2026-06-01', [
            $this->program(FirstProgram::EXPLORE->value, 201),
            $this->program(FirstProgram::CHALLENGE->value, 202),
            $this->program(FirstProgram::FUTURE_8->value, 203),
        ]);
        $this->insertEvent(3, 'Mitte', '2026-03-01', [
            $this->program(FirstProgram::FUTURE_8->value, 301),
        ]);
        $this->insertEvent(4, 'Anfang', '2026-08-01', [
            $this->program(FirstProgram::FUTURE_8->value, 401),
        ]);
        $this->setDraht(1, [
            $this->drahtProgram(FirstProgram::EXPLORE->value, 101, 8),
            $this->drahtProgram(FirstProgram::CHALLENGE->value, 102, 4, 12),
            $this->drahtProgram(FirstProgram::FUTURE_8->value, 103, 2, 8),
        ]);
        $this->setDraht(2, [
            $this->drahtProgram(FirstProgram::EXPLORE->value, 201, 8),
            $this->drahtProgram(FirstProgram::CHALLENGE->value, 202, 4, 12),
            $this->drahtProgram(FirstProgram::FUTURE_8->value, 203, 2, 8),
        ]);
        $this->setDraht(3, [
            $this->drahtProgram(FirstProgram::FUTURE_8->value, 301, 5, 10),
        ]);
        $this->setDraht(4, [
            $this->drahtProgram(FirstProgram::FUTURE_8->value, 401, 5, 10),
        ]);

        $result = app(EnrollmentsService::class)->forSeason(2);

        $this->assertSame(['Alpha', 'Zebra'], $this->events($result, 8, 'explore'));
        $this->assertSame(['Alpha', 'Zebra'], array_column($result['dual'], 'event_name'));
        $this->assertSame(['Anfang', 'Mitte'], array_column($result['future_standalone'], 'event_name'));
    }

    public function test_histogram_skips_zero_enrolled_and_missing_draht_id(): void
    {
        $this->seedCatalog();
        $this->insertEvent(1, 'No teams', '2026-01-10', [
            $this->program(FirstProgram::EXPLORE->value, 101),
        ]);
        $this->insertEvent(2, 'No draht', '2026-01-11', [
            $this->program(FirstProgram::EXPLORE->value, null),
        ]);
        $this->setDraht(1, [
            $this->drahtProgram(FirstProgram::EXPLORE->value, 101, 0),
        ]);
        $this->setDraht(2, [
            $this->drahtProgram(FirstProgram::EXPLORE->value, null, 5),
        ]);

        $result = app(EnrollmentsService::class)->forSeason(2);

        $this->assertSame(0, $this->cell($result, 5, 'explore'));
        foreach ($result['histogram'] as $row) {
            $this->assertSame(0, $row['explore']);
        }
    }

    public function test_dual_table_only_when_challenge_and_future8_are_attached(): void
    {
        $this->seedCatalog();
        $this->insertEvent(1, 'Both', '2026-02-01', [
            $this->program(FirstProgram::CHALLENGE->value, 11),
            $this->program(FirstProgram::FUTURE_8->value, 12),
        ]);
        $this->insertEvent(2, 'Challenge only', '2026-02-02', [
            $this->program(FirstProgram::CHALLENGE->value, 21),
        ]);
        $this->setDraht(1, [
            $this->drahtProgram(FirstProgram::CHALLENGE->value, 11, 4, 12),
            $this->drahtProgram(FirstProgram::FUTURE_8->value, 12, 6, 8),
        ]);
        $this->setDraht(2, [
            $this->drahtProgram(FirstProgram::CHALLENGE->value, 21, 10, 10),
        ]);

        $result = app(EnrollmentsService::class)->forSeason(2);

        $this->assertCount(1, $result['dual']);
        $this->assertSame('Both', $result['dual'][0]['event_name']);
        $this->assertSame(4, $result['dual'][0]['challenge']['enrolled']);
        $this->assertSame(12, $result['dual'][0]['challenge']['capacity']);
        $this->assertSame(6, $result['dual'][0]['future8']['enrolled']);
        $this->assertSame(8, $result['dual'][0]['future8']['capacity']);
        $this->assertSame([], $result['future_standalone']);
    }

    public function test_future_standalone_when_future8_is_attached_without_challenge(): void
    {
        $this->seedCatalog();
        $this->insertEvent(1, 'F8 only', '2026-03-01', [
            $this->program(FirstProgram::FUTURE_8->value, 31),
        ]);
        $this->insertEvent(2, 'Both', '2026-03-02', [
            $this->program(FirstProgram::CHALLENGE->value, 41),
            $this->program(FirstProgram::FUTURE_8->value, 42),
        ]);
        $this->setDraht(1, [
            $this->drahtProgram(FirstProgram::FUTURE_8->value, 31, 5, 10),
        ]);
        $this->setDraht(2, [
            $this->drahtProgram(FirstProgram::CHALLENGE->value, 41, 8, 12),
            $this->drahtProgram(FirstProgram::FUTURE_8->value, 42, 3, 8),
        ]);

        $result = app(EnrollmentsService::class)->forSeason(2);

        $this->assertCount(1, $result['future_standalone']);
        $this->assertSame('F8 only', $result['future_standalone'][0]['event_name']);
        $this->assertSame(5, $result['future_standalone'][0]['future8']['enrolled']);
        $this->assertSame(10, $result['future_standalone'][0]['future8']['capacity']);
        $this->assertArrayNotHasKey('challenge', $result['future_standalone'][0]);
        $this->assertCount(1, $result['dual']);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function cell(array $result, int|string $teams, string $column): int
    {
        foreach ($result['histogram'] as $row) {
            if ($row['teams'] === $teams) {
                return (int) $row[$column];
            }
        }

        $this->fail("Histogram row {$teams} missing");
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function events(array $result, int|string $teams, string $column): array
    {
        foreach ($result['histogram'] as $row) {
            if ($row['teams'] === $teams) {
                return $row[$column.'_events'];
            }
        }

        $this->fail("Histogram row {$teams} missing");
    }

    private function seedCatalog(): void
    {
        DB::table('m_season')->insert([
            ['id' => 1, 'name' => 'Old', 'year' => 2024],
            ['id' => 2, 'name' => 'Current', 'year' => 2025],
        ]);
        DB::table('m_first_program')->insert([
            ['id' => FirstProgram::DISCOVER->value, 'name' => 'DISCOVER', 'sequence' => 1],
            ['id' => FirstProgram::EXPLORE->value, 'name' => 'EXPLORE', 'sequence' => 2],
            ['id' => FirstProgram::CHALLENGE->value, 'name' => 'CHALLENGE', 'sequence' => 3],
            ['id' => FirstProgram::FUTURE_8->value, 'name' => 'FUTURE_8', 'sequence' => 5],
        ]);
    }

    /**
     * @param  list<array{first_program: int, draht_id: int|null}>  $programs
     */
    private function insertEvent(int $id, string $name, string $date, array $programs): void
    {
        DB::table('event')->insert([
            'id' => $id,
            'name' => $name,
            'season' => 2,
            'date' => $date,
            'regional_partner' => 1,
        ]);
        foreach ($programs as $program) {
            DB::table('event_program')->insert([
                'event' => $id,
                'first_program' => $program['first_program'],
                'draht_id' => $program['draht_id'],
            ]);
        }
    }

    /**
     * @return array{first_program: int, draht_id: int|null}
     */
    private function program(int $firstProgram, ?int $drahtId): array
    {
        return ['first_program' => $firstProgram, 'draht_id' => $drahtId];
    }

    /**
     * @param  list<array<string, mixed>>  $programs
     */
    private function setDraht(int $eventId, array $programs): void
    {
        $this->drahtByEvent[$eventId] = [
            'ok' => true,
            'data' => ['programs' => $programs],
        ];
    }

    /**
     * @return array{first_program: int, draht_id: int|null, teams: list<int>, capacity: int}
     */
    private function drahtProgram(int $firstProgram, ?int $drahtId, int $enrolled, int $capacity = 0): array
    {
        return [
            'first_program' => $firstProgram,
            'draht_id' => $drahtId,
            'teams' => $enrolled > 0 ? range(1, $enrolled) : [],
            'capacity' => $capacity,
        ];
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('m_season', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('year')->nullable();
        });

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('sequence')->default(0);
        });

        Schema::create('event', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('season');
            $table->date('date')->nullable();
            $table->unsignedInteger('regional_partner')->nullable();
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('draht_id')->nullable();
        });
    }
}
