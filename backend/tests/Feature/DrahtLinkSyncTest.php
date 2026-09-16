<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use App\Services\DrahtLinkSyncService;
use App\Services\EventSlugService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The copy of the public link inside DRAHT: one push per DRAHT id, and never from a
 * non-production instance.
 */
class DrahtLinkSyncTest extends TestCase
{
    private const SEASON = 3;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('DRAHT link sync tests require sqlite.');
        }

        config(['app.public_url' => 'https://handson.tools']);

        $this->createSchema();
    }

    public function test_every_draht_id_of_an_event_receives_the_same_link(): void
    {
        $draht = $this->fakeDraht();
        $this->pretendToBeProduction();

        $event = $this->eventWithSlug([1001, 1002]);
        $result = app(DrahtLinkSyncService::class)->pushEvent($event);

        $this->assertSame([1001, 1002], $result['pushed']);
        $this->assertSame([], $result['failed']);
        $this->assertNull($result['skipped']);
        $this->assertSame([
            [1001, 'https://handson.tools/aachen'],
            [1002, 'https://handson.tools/aachen'],
        ], $draht->calls);
    }

    public function test_a_failed_push_is_reported_and_does_not_stop_the_others(): void
    {
        $draht = $this->fakeDraht(fn (int $drahtId) => $drahtId !== 1001);
        $this->pretendToBeProduction();

        $result = app(DrahtLinkSyncService::class)->pushEvent($this->eventWithSlug([1001, 1002]));

        $this->assertSame([1001], $result['failed']);
        $this->assertSame([1002], $result['pushed']);
    }

    public function test_nothing_is_written_outside_production(): void
    {
        $draht = $this->fakeDraht();

        $result = app(DrahtLinkSyncService::class)->pushEvent($this->eventWithSlug([1001]));

        $this->assertSame([], $draht->calls);
        $this->assertSame([], $result['pushed']);
        $this->assertStringStartsWith('environment ', (string) $result['skipped']);
    }

    public function test_an_event_without_slug_or_draht_id_is_skipped(): void
    {
        $draht = $this->fakeDraht();
        $this->pretendToBeProduction();
        $sync = app(DrahtLinkSyncService::class);

        $withoutDrahtId = $this->eventWithSlug([]);
        $this->assertSame('no draht id', $sync->pushEvent($withoutDrahtId)['skipped']);

        $withoutSlug = $this->insertEvent(['id' => 2, 'name' => 'Koeln']);
        $this->insertProgram(9, 2, 2, 1009);
        $this->assertSame('no slug', $sync->pushEvent($withoutSlug->refresh())['skipped']);

        $this->assertSame([], $draht->calls);
    }

    public function test_the_command_pushes_a_whole_season_and_can_only_report(): void
    {
        $draht = $this->fakeDraht();
        $this->pretendToBeProduction();
        $this->eventWithSlug([1001]);

        $this->artisan('flow:push-public-links', ['--season' => self::SEASON, '--dry-run' => true])
            ->assertExitCode(0);
        $this->assertSame([], $draht->calls);

        $this->artisan('flow:push-public-links', ['--season' => self::SEASON])
            ->assertExitCode(0);
        $this->assertSame([[1001, 'https://handson.tools/aachen']], $draht->calls);
    }

    public function test_without_a_season_option_the_current_season_is_pushed(): void
    {
        $draht = $this->fakeDraht();
        $this->pretendToBeProduction();
        $this->eventWithSlug([1001]);

        $this->artisan('flow:push-public-links')->assertExitCode(0);

        $this->assertSame([[1001, 'https://handson.tools/aachen']], $draht->calls);
    }

    private function pretendToBeProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    /**
     * @param  callable(int): bool|null  $answer
     */
    private function fakeDraht(?callable $answer = null): DrahtController
    {
        $fake = new class($answer) extends DrahtController
        {
            /** @var list<array{0: int, 1: string}> */
            public array $calls = [];

            /** @var callable(int): bool|null */
            private $answer;

            public function __construct(?callable $answer)
            {
                $this->answer = $answer;
            }

            public function updateEventLink(int $drahtEventId, string $link): bool
            {
                $this->calls[] = [$drahtEventId, $link];

                return $this->answer === null ? true : ($this->answer)($drahtEventId);
            }
        };

        $this->app->instance(DrahtController::class, $fake);

        return $fake;
    }

    /**
     * @param  list<int>  $drahtIds
     */
    private function eventWithSlug(array $drahtIds): Event
    {
        $event = $this->insertEvent(['id' => 1, 'name' => 'Aachen']);

        foreach ($drahtIds as $index => $drahtId) {
            $this->insertProgram($index + 1, 1, 2, $drahtId);
        }

        app(EventSlugService::class)->ensure($event->refresh());

        return $event->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertEvent(array $attributes): Event
    {
        return Event::query()->create(array_merge([
            'name' => 'Event',
            'regional_partner' => 1,
            'level' => 1,
            'season' => self::SEASON,
            'date' => '2026-08-24',
            'days' => 1,
        ], $attributes))->refresh();
    }

    private function insertProgram(int $id, int $event, int $firstProgram, ?int $drahtId): void
    {
        DB::table('event_program')->insert([
            'id' => $id,
            'event' => $event,
            'first_program' => $firstProgram,
            'draht_id' => $drahtId,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('event_slug_history');
        Schema::dropIfExists('event_program');
        Schema::dropIfExists('event');
        Schema::dropIfExists('m_first_program');
        Schema::dropIfExists('m_season');

        Schema::create('m_season', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('year');
        });

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('letter')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
        });

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('slug_manual')->default(false);
            $table->unsignedInteger('regional_partner')->default(1);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('season')->default(1);
            $table->date('date');
            $table->unsignedTinyInteger('days')->default(1);
            $table->string('link')->nullable();
            $table->text('qrcode')->nullable();
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('draht_id')->nullable();
        });

        Schema::create('event_slug_history', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('season');
            $table->string('slug');
            $table->timestamp('replaced_at')->nullable();

            $table->unique(['slug', 'season']);
        });

        DB::table('m_season')->insert([
            ['id' => self::SEASON, 'name' => 'Saison 2026', 'year' => 2026],
        ]);

        DB::table('m_first_program')->insert([
            ['id' => 2, 'name' => 'EXPLORE', 'display_name' => 'Explore', 'letter' => 'E', 'sequence' => 1],
        ]);
    }
}
