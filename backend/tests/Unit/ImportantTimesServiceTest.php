<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Services\ImportantTimesService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportantTimesServiceTest extends TestCase
{
    private ImportantTimesService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('ImportantTimesService tests require sqlite.');
        }

        $this->createSchema();
        $this->service = new ImportantTimesService;
    }

    public function test_returns_only_activities_with_public_time(): void
    {
        $planId = $this->seedPlan();
        $this->insertActivity($planId, 101, '2026-03-15 09:00:00', true);
        $this->insertActivity($planId, 102, '2026-03-15 10:00:00', false);

        $payload = $this->service->buildPayload($planId, '2026-03-15 08:00:00');

        $this->assertCount(1, $payload['lanes']);
        $this->assertCount(1, $payload['lanes'][0]['times']);
        $this->assertSame('Challenge opening', $payload['lanes'][0]['times'][0]['label']);
    }

    public function test_joint_activity_is_copied_to_all_lanes_with_joint_flag(): void
    {
        $planId = $this->seedPlan(withExplore: true);
        $this->insertActivity($planId, 103, '2026-03-15 09:15:00', true);

        $payload = $this->service->buildPayload($planId, '2026-03-15 08:00:00');

        $this->assertCount(2, $payload['lanes']);
        foreach ($payload['lanes'] as $lane) {
            $this->assertCount(1, $lane['times']);
            $this->assertTrue($lane['times'][0]['joint']);
            $this->assertSame('Joint opening', $lane['times'][0]['label']);
        }
    }

    public function test_program_specific_activity_goes_to_one_lane_only(): void
    {
        $planId = $this->seedPlan(withExplore: true);
        $this->insertActivity($planId, 101, '2026-03-15 09:00:00', true);
        $this->insertActivity($planId, 104, '2026-03-15 11:00:00', true);

        $payload = $this->service->buildPayload($planId, '2026-03-15 08:00:00');

        $explore = collect($payload['lanes'])->firstWhere('program_id', FirstProgram::EXPLORE->value);
        $challenge = collect($payload['lanes'])->firstWhere('program_id', FirstProgram::CHALLENGE->value);

        $this->assertFalse($explore['times'][0]['joint']);
        $this->assertSame('Explore awards', $explore['times'][0]['label']);
        $this->assertSame('Challenge opening', $challenge['times'][0]['label']);
    }

    public function test_times_are_sorted_chronologically_within_lane(): void
    {
        $planId = $this->seedPlan();
        $this->insertActivity($planId, 101, '2026-03-15 11:00:00', true);
        $this->insertActivity($planId, 105, '2026-03-15 09:00:00', true);

        $payload = $this->service->buildPayload($planId, '2026-03-15 08:00:00');
        $times = $payload['lanes'][0]['times'];

        $this->assertSame('Challenge briefing', $times[0]['label']);
        $this->assertSame('Challenge opening', $times[1]['label']);
    }

    public function test_empty_lane_is_omitted(): void
    {
        $planId = $this->seedPlan(withExplore: true);
        $this->insertActivity($planId, 101, '2026-03-15 09:00:00', true);

        $payload = $this->service->buildPayload($planId, '2026-03-15 08:00:00');

        $this->assertCount(1, $payload['lanes']);
        $this->assertSame(FirstProgram::CHALLENGE->value, $payload['lanes'][0]['program_id']);
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('event', function (Blueprint $table) {
            $table->id();
            $table->date('date')->nullable();
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event');
            $table->timestamp('last_change')->nullable();
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event');
            $table->unsignedInteger('first_program');
        });

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->integer('sequence');
            $table->string('color_hex')->nullable();
        });

        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('name_preview')->nullable();
            $table->unsignedInteger('first_program')->nullable();
            $table->boolean('public_time')->default(false);
        });

        Schema::create('activity_group', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan');
        });

        Schema::create('activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_group');
            $table->unsignedBigInteger('activity_type_detail');
            $table->dateTime('start')->nullable();
            $table->unsignedBigInteger('extra_block')->nullable();
            $table->boolean('public_time')->default(false);
        });

        Schema::create('extra_block', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan');
            $table->unsignedInteger('first_program')->nullable();
            $table->string('name')->nullable();
            $table->string('type')->default('free');
            $table->boolean('public_time')->default(false);
        });

        DB::table('m_first_program')->insert([
            ['id' => FirstProgram::EXPLORE->value, 'name' => 'EXPLORE', 'display_name' => 'Explore', 'sequence' => 1, 'color_hex' => '00A651'],
            ['id' => FirstProgram::CHALLENGE->value, 'name' => 'CHALLENGE', 'display_name' => 'Challenge', 'sequence' => 2, 'color_hex' => 'ED1C24'],
        ]);

        DB::table('m_activity_type_detail')->insert([
            ['id' => 101, 'code' => 'c_opening', 'name' => 'Challenge opening', 'name_preview' => null, 'first_program' => FirstProgram::CHALLENGE->value, 'public_time' => false],
            ['id' => 102, 'code' => 'c_hidden', 'name' => 'Hidden', 'name_preview' => null, 'first_program' => FirstProgram::CHALLENGE->value, 'public_time' => false],
            ['id' => 103, 'code' => 'g_opening', 'name' => 'Joint opening', 'name_preview' => null, 'first_program' => FirstProgram::JOINT->value, 'public_time' => false],
            ['id' => 104, 'code' => 'e_awards', 'name' => 'Explore awards', 'name_preview' => null, 'first_program' => FirstProgram::EXPLORE->value, 'public_time' => false],
            ['id' => 105, 'code' => 'c_briefing', 'name' => 'Challenge briefing', 'name_preview' => null, 'first_program' => FirstProgram::CHALLENGE->value, 'public_time' => false],
        ]);
    }

    private function seedPlan(bool $withExplore = false): int
    {
        DB::table('event')->insert(['id' => 1, 'date' => '2026-03-15']);
        DB::table('plan')->insert(['id' => 1, 'event' => 1, 'last_change' => now()]);
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => FirstProgram::CHALLENGE->value],
        ]);
        if ($withExplore) {
            DB::table('event_program')->insert([
                ['event' => 1, 'first_program' => FirstProgram::EXPLORE->value],
            ]);
        }

        return 1;
    }

    private function insertActivity(int $planId, int $atdId, string $start, bool $publicTime): void
    {
        $groupId = DB::table('activity_group')->insertGetId(['plan' => $planId]);
        DB::table('activity')->insert([
            'activity_group' => $groupId,
            'activity_type_detail' => $atdId,
            'start' => $start,
            'public_time' => $publicTime,
        ]);
    }
}
