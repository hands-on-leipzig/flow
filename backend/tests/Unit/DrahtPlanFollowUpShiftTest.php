<?php

namespace Tests\Unit;

use App\Services\DrahtPlanFollowUp;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DrahtPlanFollowUpShiftTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('DrahtPlanFollowUp shift tests require sqlite.');
        }

        Schema::dropAllTables();

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
        });

        Schema::create('extra_block', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan');
            $table->dateTime('start')->nullable();
            $table->dateTime('end')->nullable();
        });

        Schema::create('slot_block_team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('extra_block');
            $table->dateTime('start')->nullable();
        });

        Schema::create('match', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('first_program');
        });
    }

    public function test_shift_moves_clocks_and_drops_matches_for_a_missing_program(): void
    {
        DB::table('event')->insert(['id' => 1]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
        DB::table('event_program')->insert([
            'id' => 1,
            'event' => 1,
            'first_program' => 3,
        ]);
        DB::table('extra_block')->insert([
            [
                'id' => 1,
                'plan' => 1,
                'start' => '2026-01-01 08:00:00',
                'end' => '2026-01-01 09:00:00',
            ],
            [
                'id' => 2,
                'plan' => 1,
                'start' => null,
                'end' => null,
            ],
        ]);
        DB::table('slot_block_team')->insert([
            'id' => 1,
            'extra_block' => 1,
            'start' => '2026-01-01 10:00:00',
        ]);
        DB::table('match')->insert([
            ['id' => 1, 'plan' => 1, 'first_program' => 3],
            ['id' => 2, 'plan' => 1, 'first_program' => 8],
        ]);

        $service = new DrahtPlanFollowUp;
        $service->shiftPlan(1, 7);
        $service->deleteMatchesForDetachedPrograms(1, 1);

        $moved = DB::table('extra_block')->where('id', 1)->first();
        $this->assertSame('2026-01-08 08:00:00', $moved->start);
        $this->assertSame('2026-01-08 09:00:00', $moved->end);

        $untimed = DB::table('extra_block')->where('id', 2)->first();
        $this->assertNull($untimed->start);
        $this->assertNull($untimed->end);

        $this->assertSame(
            '2026-01-08 10:00:00',
            DB::table('slot_block_team')->where('id', 1)->value('start')
        );

        $this->assertDatabaseHas('match', ['id' => 1, 'first_program' => 3]);
        $this->assertDatabaseMissing('match', ['id' => 2]);
    }
}
