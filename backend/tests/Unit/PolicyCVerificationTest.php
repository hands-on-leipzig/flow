<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Services\AfternoonBlockOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Narrow Policy C verification (afternoon order filter + support pointer).
 *
 * Manual UI check: event with Challenge + Future 8+, g_separate_rooms=1 —
 * (1) without Explore: joint g_opening, independent mornings/afternoons, joint g_awards / g_end;
 * (2) with Explore integrated morning: Explore hole follows Challenge only; F8 stays on opening-end clock;
 * Nachmittag shows two lists; A/B (g_separate_rooms=0) keeps one shared list.
 *
 * isSupported A/B/C allow: DualChallengeShapedSupportTest.
 */
class PolicyCVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Requires sqlite.');
        }

        Schema::dropAllTables();

        Schema::create('plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event');
        });

        Schema::create('event', function (Blueprint $table) {
            $table->id();
            $table->integer('level')->default(1);
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event');
            $table->unsignedInteger('first_program');
        });

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('sequence')->default(0);
        });

        Schema::create('m_activity_type', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('first_program')->nullable();
        });

        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name')->nullable();
            $table->string('name_preview')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('activity_type');
            $table->integer('afternoon_chain')->nullable();
            $table->integer('afternoon_default')->nullable();
            $table->unsignedInteger('afternoon_parameter')->nullable();
        });

        Schema::create('m_parameter', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('context')->default('afternoon');
            $table->integer('level')->default(1);
        });

        Schema::create('afternoon_block_order', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedSmallInteger('sequence')->default(0);
        });

        DB::table('m_first_program')->insert([
            ['id' => FirstProgram::CHALLENGE->value, 'name' => 'Challenge', 'sequence' => 2],
            ['id' => FirstProgram::FUTURE_8->value, 'name' => 'Future 8+', 'sequence' => 5],
        ]);

        DB::table('m_activity_type')->insert([
            ['id' => 1, 'first_program' => FirstProgram::CHALLENGE->value],
            ['id' => 2, 'first_program' => FirstProgram::FUTURE_8->value],
        ]);

        // afternoon_parameter null → always included (no level gate).
        DB::table('m_activity_type_detail')->insert([
            [
                'id' => 10,
                'code' => 'c_presentations',
                'name' => 'C Pres',
                'name_preview' => 'C',
                'first_program' => FirstProgram::CHALLENGE->value,
                'activity_type' => 1,
                'afternoon_chain' => 0,
                'afternoon_default' => 1,
                'afternoon_parameter' => null,
            ],
            [
                'id' => 12,
                'code' => 'c_extra',
                'name' => 'C Extra',
                'name_preview' => 'CX',
                'first_program' => FirstProgram::CHALLENGE->value,
                'activity_type' => 1,
                'afternoon_chain' => 0,
                'afternoon_default' => 2,
                'afternoon_parameter' => null,
            ],
            [
                'id' => 20,
                'code' => 'f8_presentations',
                'name' => 'F8 Pres',
                'name_preview' => 'F',
                'first_program' => FirstProgram::FUTURE_8->value,
                'activity_type' => 2,
                'afternoon_chain' => 0,
                'afternoon_default' => 3,
                'afternoon_parameter' => null,
            ],
            [
                'id' => 21,
                'code' => 'f8_round_4',
                'name' => 'F8 R4',
                'name_preview' => '4',
                'first_program' => FirstProgram::FUTURE_8->value,
                'activity_type' => 2,
                'afternoon_chain' => 0,
                'afternoon_default' => 4,
                'afternoon_parameter' => null,
            ],
        ]);

        DB::table('event')->insert(['id' => 1, 'level' => 1]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => FirstProgram::CHALLENGE->value],
            ['event' => 1, 'first_program' => FirstProgram::FUTURE_8->value],
        ]);
    }

    public function test_concat_save_preserves_per_program_sequence_when_filtered(): void
    {
        $service = app(AfternoonBlockOrderService::class);

        // Policy C UI save: Challenge order then Future order (concat).
        $service->saveOrder(1, [12, 10, 21, 20]);

        $resolved = $service->resolvedBlocks(1);
        $challenge = $this->filterByProgram($resolved, FirstProgram::CHALLENGE->value);
        $future = $this->filterByProgram($resolved, FirstProgram::FUTURE_8->value);

        $this->assertSame(['c_extra', 'c_presentations'], $challenge->pluck('code')->all());
        $this->assertSame(['f8_round_4', 'f8_presentations'], $future->pluck('code')->all());
    }

    /**
     * @param  Collection<int, object>  $blocks
     * @return Collection<int, object>
     */
    private function filterByProgram(Collection $blocks, int $firstProgram): Collection
    {
        return $blocks
            ->filter(fn ($block) => (int) ($block->first_program ?? 0) === $firstProgram)
            ->values();
    }
}
