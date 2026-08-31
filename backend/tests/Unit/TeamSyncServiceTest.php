<?php

namespace Tests\Unit;

use App\Services\EventAttentionService;
use App\Services\TeamSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeamSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('TeamSyncService tests require sqlite.');
        }

        $this->createSchema();
    }

    public function test_sync_applies_remove_add_and_update(): void
    {
        $this->seedMinimalData();

        $this->mock(EventAttentionService::class, function ($mock) {
            $mock->shouldReceive('updateEventAttentionStatus')->once()->with(1);
        });

        $event = \App\Models\Event::query()->without('programs')->find(1);
        $service = app(TeamSyncService::class);

        $result = $service->sync($event, 'challenge', [
            ['ref' => 101, 'name' => 'Renamed Local'],
            ['ref' => 200, 'name' => 'Fresh Team'],
        ]);

        $this->assertSame(1, $result['applied']['removed']);
        $this->assertSame(1, $result['applied']['added']);
        $this->assertSame(1, $result['applied']['updated']);

        $this->assertDatabaseMissing('team', ['id' => 10]);
        $this->assertDatabaseHas('team', ['team_number_hot' => 200, 'name' => 'Fresh Team']);
        $this->assertDatabaseHas('team', ['id' => 11, 'name' => 'Renamed Local']);
    }

    public function test_sync_does_not_touch_other_programs(): void
    {
        $this->seedMinimalData();

        DB::table('m_first_program')->insert([
            'id' => 2,
            'name' => 'EXPLORE',
            'sequence' => 1,
        ]);

        DB::table('team')->insert([
            ['id' => 20, 'name' => 'Explore Team', 'event' => 1, 'first_program' => 2, 'team_number_hot' => 501],
        ]);

        DB::table('team_plan')->insert([
            ['team' => 20, 'plan' => 1, 'team_number_plan' => 1, 'room' => null, 'noshow' => 0],
        ]);

        $this->mock(EventAttentionService::class, function ($mock) {
            $mock->shouldReceive('updateEventAttentionStatus')->once()->with(1);
        });

        $event = \App\Models\Event::query()->without('programs')->find(1);
        $service = app(TeamSyncService::class);

        $service->sync($event, 'challenge', [
            ['ref' => 101, 'name' => 'Renamed Local'],
            ['ref' => 200, 'name' => 'Fresh Team'],
        ]);

        $this->assertDatabaseHas('team', [
            'id' => 20,
            'name' => 'Explore Team',
            'first_program' => 2,
        ]);
        $this->assertDatabaseHas('team_plan', [
            'team' => 20,
            'plan' => 1,
            'team_number_plan' => 1,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 50);
            $table->unsignedInteger('sequence')->default(0);
        });

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100);
            $table->boolean('needs_attention')->default(false);
            $table->timestamp('needs_attention_checked_at')->nullable();
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100);
            $table->unsignedInteger('event');
        });

        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100);
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->integer('team_number_hot');
            $table->string('location', 255)->nullable();
            $table->string('organization', 255)->nullable();
        });

        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('team');
            $table->unsignedInteger('plan');
            $table->unsignedInteger('team_number_plan');
            $table->unsignedInteger('room')->nullable();
            $table->boolean('noshow')->default(false);
        });
    }

    private function seedMinimalData(): void
    {
        DB::table('m_first_program')->insert([
            'id' => 3,
            'name' => 'CHALLENGE',
            'sequence' => 2,
        ]);

        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Test Event',
        ]);

        DB::table('plan')->insert([
            'id' => 1,
            'name' => 'Plan',
            'event' => 1,
        ]);

        DB::table('team')->insert([
            ['id' => 10, 'name' => 'Old Local', 'event' => 1, 'first_program' => 3, 'team_number_hot' => 100],
            ['id' => 11, 'name' => 'Old Name', 'event' => 1, 'first_program' => 3, 'team_number_hot' => 101],
        ]);

        DB::table('team_plan')->insert([
            ['team' => 10, 'plan' => 1, 'team_number_plan' => 1, 'room' => null, 'noshow' => 0],
            ['team' => 11, 'plan' => 1, 'team_number_plan' => 2, 'room' => null, 'noshow' => 0],
        ]);
    }
}
