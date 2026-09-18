<?php

namespace Tests\Unit;

use App\Print\RoomSheetAssembler;
use App\Services\ActivityFetcherService;
use App\Services\EventTitleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class RoomSheetAssemblerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('RoomSheetAssembler tests require sqlite.');
        }

        Schema::dropAllTables();
        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('level')->nullable();
        });
        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
        });
        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->string('logo_stem')->nullable();
        });
        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
        });
        Schema::create('room', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->string('name');
            $table->unsignedInteger('sequence')->nullable();
        });
        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('team_number_hot')->nullable();
            $table->unsignedInteger('first_program')->nullable();
        });
        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('plan');
            $table->unsignedInteger('team');
            $table->unsignedInteger('team_number_plan')->nullable();
            $table->unsignedInteger('room')->nullable();
            $table->boolean('noshow')->default(false);
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_null_when_plan_missing(): void
    {
        $fetcher = Mockery::mock(ActivityFetcherService::class);
        $fetcher->shouldReceive('fetchActivities')->never();

        $this->assertNull((new RoomSheetAssembler($fetcher, new EventTitleService))->assemble(9));
    }

    public function test_team_only_room_has_columns_and_no_activities(): void
    {
        $this->seedEvent();
        $this->attachProgram(3, 'CHALLENGE', 'Challenge', 2);
        $this->insertRoom(10, 'Aula', 1);
        $this->insertTeam(1, 3, 'Alpha', 1, 10, 1);

        $fetcher = Mockery::mock(ActivityFetcherService::class);
        $fetcher->shouldReceive('fetchActivities')->once()->andReturn(collect());

        $document = (new RoomSheetAssembler($fetcher, new EventTitleService))->assemble(1);

        $this->assertNotNull($document);
        $this->assertFalse($document['show_program_logos']);
        $this->assertCount(1, $document['sections']);
        $this->assertSame('Aula', $document['sections'][0]['subject']);
        $this->assertSame([], $document['sections'][0]['activities']);
        $this->assertCount(1, $document['sections'][0]['team_columns']);
        $this->assertSame('Alpha (0001)', $document['sections'][0]['team_columns'][0]['teams'][0]['label']);
    }

    public function test_activity_only_room_omits_team_columns(): void
    {
        $this->seedEvent();
        $this->attachProgram(3, 'CHALLENGE', 'Challenge', 2);
        $this->insertRoom(10, 'Halle', 1);

        $fetcher = Mockery::mock(ActivityFetcherService::class);
        $fetcher->shouldReceive('fetchActivities')->once()->andReturn(collect([
            $this->activity(1, 10, '09:00:00', '09:15:00', 'Eröffnung', 'g_opening', 3),
        ]));

        $document = (new RoomSheetAssembler($fetcher, new EventTitleService))->assemble(1);

        $this->assertArrayNotHasKey('team_columns', $document['sections'][0]);
        $this->assertCount(1, $document['sections'][0]['activities']);
        $this->assertSame('09:00', $document['sections'][0]['activities'][0]['start']);
        $this->assertSame('Eröffnung', $document['sections'][0]['activities'][0]['action']);
    }

    public function test_two_programs_make_logo_column_and_empty_explore_column(): void
    {
        $this->seedEvent();
        $this->attachProgram(2, 'EXPLORE', 'Explore', 1);
        $this->attachProgram(3, 'CHALLENGE', 'Challenge', 2);
        $this->insertRoom(10, 'Aula', 1);
        $this->insertTeam(1, 3, 'Alpha', 1, 10, 1);

        $fetcher = Mockery::mock(ActivityFetcherService::class);
        $fetcher->shouldReceive('fetchActivities')->once()->andReturn(collect());

        $document = (new RoomSheetAssembler($fetcher, new EventTitleService))->assemble(1);

        $this->assertTrue($document['show_program_logos']);
        $columns = $document['sections'][0]['team_columns'];
        $this->assertCount(2, $columns);
        $this->assertSame(2, $columns[0]['program_id']);
        $this->assertSame([], $columns[0]['teams']);
        $this->assertSame(3, $columns[1]['program_id']);
        $this->assertSame('Alpha (0001)', $columns[1]['teams'][0]['label']);
    }

    public function test_empty_room_is_omitted_and_order_follows_sequence(): void
    {
        $this->seedEvent();
        $this->attachProgram(3, 'CHALLENGE', 'Challenge', 2);
        $this->insertRoom(1, 'Empty', 1);
        $this->insertRoom(2, 'Second', 3);
        $this->insertRoom(3, 'First', 2);
        $this->insertTeam(1, 3, 'Alpha', 1, 3, 1);
        $this->insertTeam(2, 3, 'Beta', 2, 2, 2);

        $fetcher = Mockery::mock(ActivityFetcherService::class);
        $fetcher->shouldReceive('fetchActivities')->once()->andReturn(collect());

        $document = (new RoomSheetAssembler($fetcher, new EventTitleService))->assemble(1);

        $this->assertSame(['First', 'Second'], array_column($document['sections'], 'subject'));
    }

    public function test_slot_activity_uses_team_plan_name(): void
    {
        $this->seedEvent();
        $this->attachProgram(3, 'CHALLENGE', 'Challenge', 2);
        $this->insertRoom(10, 'Halle', 1);
        $this->insertTeam(1, 3, 'Alpha', 4, 10, 2);

        $fetcher = Mockery::mock(ActivityFetcherService::class);
        $fetcher->shouldReceive('fetchActivities')->once()->andReturn(collect([
            $this->activity(5, 10, '11:00:00', '11:10:00', 'Slot', 'c_slot_block', 3, 2),
        ]));

        $document = (new RoomSheetAssembler($fetcher, new EventTitleService))->assemble(1);

        $this->assertSame(
            'Slot, Alpha (0004)',
            $document['sections'][0]['activities'][0]['action']
        );
    }

    private function seedEvent(): void
    {
        DB::table('event')->insert(['id' => 1, 'name' => 'Leipzig', 'level' => 1]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
    }

    private function attachProgram(int $id, string $name, string $display, int $sequence): void
    {
        DB::table('m_first_program')->insert([
            'id' => $id,
            'name' => $name,
            'display_name' => $display,
            'sequence' => $sequence,
            'logo_stem' => 'fll_'.strtolower($display),
        ]);
        DB::table('event_program')->insert(['event' => 1, 'first_program' => $id]);
    }

    private function insertRoom(int $id, string $name, int $sequence): void
    {
        DB::table('room')->insert([
            'id' => $id,
            'event' => 1,
            'name' => $name,
            'sequence' => $sequence,
        ]);
    }

    private function insertTeam(int $id, int $program, string $name, int $hot, int $room, int $number): void
    {
        DB::table('team')->insert([
            'id' => $id,
            'event' => 1,
            'name' => $name,
            'team_number_hot' => $hot,
            'first_program' => $program,
        ]);
        DB::table('team_plan')->insert([
            'plan' => 1,
            'team' => $id,
            'team_number_plan' => $number,
            'room' => $room,
            'noshow' => 0,
        ]);
    }

    private function activity(
        int $id,
        int $roomId,
        string $start,
        string $end,
        string $name,
        string $code,
        int $program,
        ?int $slotTeam = null,
    ): object {
        return (object) [
            'activity_id' => $id,
            'room_id' => $roomId,
            'start_time' => $start,
            'end_time' => $end,
            'activity_name' => $name,
            'activity_atd_name' => $name,
            'activity_type_code' => $code,
            'activity_first_program_id' => $program,
            'slot_team' => $slotTeam,
        ];
    }
}
