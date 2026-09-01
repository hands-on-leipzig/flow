<?php

namespace Tests\Unit;

use App\Services\DrahtProgramDetachService;
use App\Support\ProgramPresence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DrahtProgramDetachServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('DrahtProgramDetachService tests require sqlite.');
        }

        $this->createSchema();
    }

    public function test_detach_stale_by_draht_ids(): void
    {
        $this->seedData();

        $service = app(DrahtProgramDetachService::class);
        $detached = $service->detachStaleByDrahtIds(1, [617, 665]);

        $this->assertSame([8], $detached);
        $this->assertDatabaseMissing('event_program', ['event' => 1, 'first_program' => 8]);
        $this->assertDatabaseHas('event_program', ['event' => 1, 'first_program' => 3, 'draht_id' => 665]);
    }

    public function test_detach_program_purges_orphan_plan_params(): void
    {
        $this->seedData();

        DB::table('m_parameter')->insert([
            ['id' => 900, 'name' => 'f8_teams', 'value' => '1', 'first_program' => 8],
            ['id' => 901, 'name' => 'f8_mode', 'value' => '1', 'first_program' => 8],
            ['id' => 902, 'name' => 'c_teams', 'value' => '12', 'first_program' => 3],
        ]);

        DB::table('plan_param_value')->insert([
            ['plan' => 1, 'parameter' => 900, 'set_value' => '1'],
            ['plan' => 1, 'parameter' => 901, 'set_value' => '1'],
            ['plan' => 1, 'parameter' => 902, 'set_value' => '12'],
        ]);

        app(DrahtProgramDetachService::class)->detachProgram(1, 8);

        $this->assertDatabaseMissing('plan_param_value', ['plan' => 1, 'parameter' => 900]);
        $this->assertDatabaseMissing('plan_param_value', ['plan' => 1, 'parameter' => 901]);
        $this->assertDatabaseHas('plan_param_value', ['plan' => 1, 'parameter' => 902]);
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100);
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('draht_id')->nullable();
        });

        Schema::create('m_parameter', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100);
            $table->string('value', 255)->nullable();
            $table->unsignedInteger('first_program')->nullable();
        });

        Schema::create('plan_param_value', function (Blueprint $table) {
            $table->unsignedInteger('plan');
            $table->unsignedInteger('parameter');
            $table->string('set_value', 255)->nullable();
        });
    }

    private function seedData(): void
    {
        DB::table('event')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
        DB::table('event_program')->insert([
            ['id' => 1, 'event' => 1, 'first_program' => 2, 'draht_id' => 617],
            ['id' => 2, 'event' => 1, 'first_program' => 3, 'draht_id' => 665],
            ['id' => 3, 'event' => 1, 'first_program' => 8, 'draht_id' => 733],
        ]);
    }
}
