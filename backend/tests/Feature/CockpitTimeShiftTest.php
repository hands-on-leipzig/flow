<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Services\CockpitService;
use App\Services\CockpitTimeShiftService;
use App\Support\EventDayClock;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runs against the real clock — no Carbon::setTestNow anywhere. Fixtures are
 * seeded relative to the actual Europe/Berlin time instead.
 */
class CockpitTimeShiftTest extends TestCase
{
    private Carbon $pivot;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Cockpit timeshift tests require sqlite.');
        }

        $this->createSchema();
        $this->truncateData();
        $this->pivot = now(EventDayClock::TZ);
        $this->seedEvent($this->pivot->format('Y-m-d'));
    }

    public function test_only_activities_that_have_not_started_are_shifted(): void
    {
        $past = $this->seedActivity($this->pivot->copy()->subHours(2), 30);
        $upcoming = $this->seedActivity($this->pivot->copy()->addHour(), 30);

        $result = app(CockpitTimeShiftService::class)->shift($this->event(), 15);

        $this->assertSame(1, $result['shifted_count']);
        $this->assertSame(
            $this->pivot->copy()->subHours(2)->format('Y-m-d H:i:s'),
            $this->start($past),
            'An activity that already started must not move.'
        );
        $this->assertSame(
            $this->pivot->copy()->addHour()->addMinutes(15)->format('Y-m-d H:i:s'),
            $this->start($upcoming)
        );
    }

    public function test_start_and_end_move_by_the_same_offset(): void
    {
        $id = $this->seedActivity($this->pivot->copy()->addHour(), 45);

        app(CockpitTimeShiftService::class)->shift($this->event(), 20);

        $this->assertSame(
            $this->pivot->copy()->addHour()->addMinutes(20)->format('Y-m-d H:i:s'),
            $this->start($id)
        );
        $this->assertSame(
            $this->pivot->copy()->addHour()->addMinutes(45 + 20)->format('Y-m-d H:i:s'),
            $this->end($id)
        );
    }

    /**
     * Europe/Berlin is always UTC+1 or UTC+2, so an activity 30 minutes in the
     * past on the Berlin clock is still in the future on the UTC clock. A bare
     * now() implementation would shift it; the Berlin pivot must not.
     */
    public function test_pivot_uses_berlin_clock_not_utc(): void
    {
        $berlinPast = $this->pivot->copy()->subMinutes(30);
        $this->assertTrue(
            $berlinPast->gt(now('UTC')->format('Y-m-d H:i:s')),
            'Precondition: the row is in the future on a naive UTC clock.'
        );

        $id = $this->seedActivity($berlinPast, 60);

        $result = app(CockpitTimeShiftService::class)->shift($this->event(), 10);

        $this->assertSame(0, $result['shifted_count']);
        $this->assertSame($berlinPast->format('Y-m-d H:i:s'), $this->start($id));
    }

    public function test_running_activity_is_untouched_and_next_one_moves(): void
    {
        $running = $this->seedActivity($this->pivot->copy()->subMinutes(10), 30);
        $next = $this->seedActivity($this->pivot->copy()->addMinutes(30), 30);

        $result = app(CockpitTimeShiftService::class)->shift($this->event(), 5);

        $this->assertSame(1, $result['shifted_count']);
        $this->assertSame(
            $this->pivot->copy()->subMinutes(10)->format('Y-m-d H:i:s'),
            $this->start($running)
        );
        $this->assertSame(
            $this->pivot->copy()->addMinutes(35)->format('Y-m-d H:i:s'),
            $this->start($next)
        );
    }

    public function test_other_days_are_not_touched(): void
    {
        $tomorrow = $this->pivot->copy()->addDay()->setTime(9, 0);
        $id = $this->seedActivity($tomorrow, 30);

        app(CockpitTimeShiftService::class)->shift($this->event(), 30);

        $this->assertSame($tomorrow->format('Y-m-d H:i:s'), $this->start($id));
    }

    public function test_end_of_day_recomputes_after_shift(): void
    {
        $this->seedActivity($this->pivot->copy()->addHours(2), 60);

        $before = app(CockpitTimeShiftService::class)->state($this->event());
        $this->assertSame($this->pivot->copy()->addHours(3)->format('H:i'), $before['end_of_day_time']);

        $after = app(CockpitTimeShiftService::class)->shift($this->event(), 15);
        $this->assertSame($this->pivot->copy()->addHours(3)->addMinutes(15)->format('H:i'), $after['end_of_day_time']);
    }

    public function test_extra_block_and_slot_rows_are_untouched(): void
    {
        $this->seedActivity($this->pivot->copy()->addHour(), 30);
        DB::table('extra_block')->insert([
            'id' => 1,
            'plan' => 1,
            'type' => 'free',
            'start' => $this->pivot->copy()->addHour()->format('Y-m-d H:i:s'),
            'end' => $this->pivot->copy()->addHours(2)->format('Y-m-d H:i:s'),
            'active' => true,
        ]);
        DB::table('slot_block_team')->insert([
            'id' => 1,
            'extra_block' => 1,
            'team_number_plan' => 1,
            'start' => $this->pivot->copy()->addHour()->format('Y-m-d H:i:s'),
        ]);

        app(CockpitTimeShiftService::class)->shift($this->event(), 20);

        $this->assertSame(
            $this->pivot->copy()->addHour()->format('Y-m-d H:i:s'),
            DB::table('extra_block')->where('id', 1)->value('start')
        );
        $this->assertSame(
            $this->pivot->copy()->addHour()->format('Y-m-d H:i:s'),
            DB::table('slot_block_team')->where('id', 1)->value('start')
        );
    }

    public function test_locked_plan_is_rejected(): void
    {
        DB::table('plan')->where('id', 1)->update(['locked' => 1]);
        $this->seedActivity($this->pivot->copy()->addHour(), 30);

        try {
            app(CockpitTimeShiftService::class)->shift($this->event(), 15);
            $this->fail('Expected a locked-plan abort.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(423, $e->getStatusCode());
        }
    }

    public function test_event_day_is_projected_when_clock_is_off_day(): void
    {
        $futureDate = $this->pivot->copy()->addDays(30)->format('Y-m-d');
        $pivot = EventDayClock::pivot($futureDate, 1);

        $this->assertSame($futureDate, $pivot->format('Y-m-d'));
        $this->assertSame($this->pivot->format('H:i'), $pivot->format('H:i'));
    }

    public function test_off_day_projection_lands_on_day_one_for_past_events(): void
    {
        $pastStart = $this->pivot->copy()->subDays(30)->format('Y-m-d');
        $pivot = EventDayClock::pivot($pastStart, 2);

        $this->assertSame($pastStart, $pivot->format('Y-m-d'), 'Must land on day 1, not the last day.');
        $this->assertSame($this->pivot->format('H:i'), $pivot->format('H:i'));
    }

    public function test_clock_is_used_directly_inside_the_event_window(): void
    {
        $pivot = EventDayClock::pivot($this->pivot->format('Y-m-d'), 1);

        $this->assertSame($this->pivot->format('Y-m-d H:i'), $pivot->format('Y-m-d H:i'));
    }

    public function test_endpoint_requires_cockpit_token(): void
    {
        $this->getJson('/api/cockpit/day-event/timeshift/bootstrap')->assertUnauthorized();
        $this->postJson('/api/cockpit/day-event/timeshift/shift', ['minutes' => 5])->assertUnauthorized();
    }

    public function test_endpoint_rejects_invalid_minutes_and_ignores_client_now(): void
    {
        $token = (string) $this->postJson('/api/cockpit/day-event/session', ['pin' => '654321'])->json('token');
        $this->assertNotSame('', $token);
        $this->seedActivity($this->pivot->copy()->addHour(), 30);

        $this->withHeader('X-Cockpit-Token', $token)
            ->postJson('/api/cockpit/day-event/timeshift/shift', ['minutes' => 7])
            ->assertStatus(422);

        $this->withHeader('X-Cockpit-Token', $token)
            ->postJson('/api/cockpit/day-event/timeshift/shift', ['minutes' => 65])
            ->assertStatus(422);

        // A client-supplied "now" far in the past must not widen the scope.
        $this->withHeader('X-Cockpit-Token', $token)
            ->postJson('/api/cockpit/day-event/timeshift/shift', [
                'minutes' => 5,
                'now' => $this->pivot->copy()->startOfDay()->format('Y-m-d H:i'),
            ])
            ->assertOk()
            ->assertJsonPath('shifted_count', 1);
    }

    private function event(): Event
    {
        return Event::query()->findOrFail(1);
    }

    private function start(int $activityId): string
    {
        return (string) DB::table('activity')->where('id', $activityId)->value('start');
    }

    private function end(int $activityId): string
    {
        return (string) DB::table('activity')->where('id', $activityId)->value('end');
    }

    private function seedActivity(Carbon $start, int $durationMinutes): int
    {
        return (int) DB::table('activity')->insertGetId([
            'activity_group' => 1,
            'activity_type_detail' => 1,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $start->copy()->addMinutes($durationMinutes)->format('Y-m-d H:i:s'),
        ]);
    }

    private function seedEvent(string $date): void
    {
        // Mirror SeasonService::currentSeasonId()'s fiscal-year rule so the
        // slug lookup resolves whenever the suite runs against the real clock.
        $month = (int) date('n');
        $year = (int) date('Y');
        DB::table('m_season')->insert(['id' => 1, 'year' => $month <= 4 ? $year - 1 : $year]);
        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Day Event',
            'slug' => 'day-event',
            'level' => 1,
            'season' => 1,
            'date' => $date,
            'days' => 1,
            'cockpit_enabled' => true,
            'cockpit_pin' => app(CockpitService::class)->encryptPin('654321'),
        ]);
        DB::table('plan')->insert(['id' => 1, 'name' => 'Zeitplan', 'event' => 1, 'locked' => 0]);
        DB::table('activity_group')->insert(['id' => 1, 'plan' => 1, 'activity_type_detail' => 1]);
    }

    private function truncateData(): void
    {
        foreach ([
            'activity',
            'slot_block_team',
            'extra_block',
            'activity_group',
            'plan',
            'event_program',
            'event',
            'm_first_program',
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

        if (! Schema::hasTable('m_first_program')) {
            Schema::create('m_first_program', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->string('name')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
            });
        }

        // Event::$with eager-loads programs, so this table must exist.
        if (! Schema::hasTable('event_program')) {
            Schema::create('event_program', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program')->nullable();
            });
        }

        if (! Schema::hasTable('event')) {
            Schema::create('event', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->unsignedTinyInteger('level')->default(1);
                $table->unsignedInteger('season')->nullable();
                $table->date('date')->nullable();
                $table->unsignedTinyInteger('days')->default(1);
                $table->boolean('cockpit_enabled')->default(false);
                $table->text('cockpit_pin')->nullable();
            });
        }

        if (! Schema::hasTable('plan')) {
            Schema::create('plan', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->string('name')->nullable();
                $table->unsignedInteger('event');
                $table->boolean('locked')->default(false);
            });
        }

        if (! Schema::hasTable('activity_group')) {
            Schema::create('activity_group', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->unsignedInteger('plan');
                $table->unsignedInteger('activity_type_detail');
            });
        }

        if (! Schema::hasTable('activity')) {
            Schema::create('activity', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('activity_group');
                $table->unsignedInteger('activity_type_detail');
                $table->dateTime('start');
                $table->dateTime('end');
                $table->unsignedInteger('extra_block')->nullable();
            });
        }

        if (! Schema::hasTable('extra_block')) {
            Schema::create('extra_block', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->unsignedInteger('plan');
                $table->string('type')->nullable();
                $table->dateTime('start')->nullable();
                $table->dateTime('end')->nullable();
                $table->boolean('active')->default(false);
            });
        }

        if (! Schema::hasTable('slot_block_team')) {
            Schema::create('slot_block_team', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->unsignedInteger('extra_block');
                $table->unsignedInteger('team_number_plan');
                $table->dateTime('start')->nullable();
            });
        }
    }
}
