<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DrahtController;
use App\Http\Middleware\KeycloakJwtMiddleware;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NoticeApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Notice API tests require sqlite.');
        }

        $this->withoutMiddleware(KeycloakJwtMiddleware::class);
        DB::statement('PRAGMA foreign_keys = ON');
        $this->createSchema();
        $this->seedCatalog();
        $this->mockDraht([]);
        Carbon::setTestNow(Carbon::parse('2026-11-15', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_red_dot_messages_come_before_time_and_sort_within_kind(): void
    {
        $this->seedEvent(date: '2026-11-20', withPlan: false);

        $keys = $this->messageKeys();

        $this->assertSame(
            ['rooms_activities', 'rooms_teams', 'time_registration_closed', 'time_publish_four_weeks'],
            $keys
        );
    }

    public function test_teams_instances_interpolate_program_and_jump_to_that_program(): void
    {
        $this->seedEvent(date: '2026-11-20', programs: [
            ['id' => 1, 'name' => 'EXPLORE', 'display_name' => 'Explore'],
            ['id' => 2, 'name' => 'CHALLENGE', 'display_name' => 'Challenge'],
        ]);
        DB::table('team')->insert([
            ['id' => 1, 'name' => 'Local Explore', 'event' => 1, 'first_program' => 1, 'team_number_hot' => 10],
            ['id' => 2, 'name' => 'Same', 'event' => 1, 'first_program' => 2, 'team_number_hot' => 20],
        ]);
        $this->mockDraht([
            [
                'first_program' => 1,
                'name' => 'EXPLORE',
                'teams' => [['ref' => '10', 'name' => 'Draht Explore']],
            ],
            [
                'first_program' => 2,
                'name' => 'CHALLENGE',
                'teams' => [['ref' => '20', 'name' => 'Same']],
            ],
        ]);

        $payload = $this->getJson('/api/events/1/notices')->assertOk()->json();
        $teams = collect($payload['messages'])->where('key', 'teams_discrepancy')->values();

        $this->assertCount(1, $teams);
        $this->assertSame('Die aktuellen Anmeldungen für Explore weichen von den Teams in FLOW ab. Bitte auf der Teams-Seite abgleichen.', $teams[0]['body']);
        $this->assertSame('/plan/teams/explore', $teams[0]['jump_path']);
        $this->assertSame('explore', $teams[0]['program']);
        $this->assertFalse($payload['dots']['teams_by_program']['challenge']);
        $this->assertTrue($payload['dots']['teams_by_program']['explore']);
    }

    public function test_no_plan_turns_schedule_off_and_rooms_on(): void
    {
        $this->seedEvent(date: '2026-11-20', withPlan: false);

        $payload = $this->getJson('/api/events/1/notices')->assertOk()->json();
        $keys = collect($payload['messages'])->pluck('key')->all();

        $this->assertFalse($payload['dots']['schedule']);
        $this->assertNotContains('schedule_counts', $keys);
        $this->assertTrue($payload['dots']['rooms']);
        $this->assertTrue($payload['dots']['rooms_activities']);
        $this->assertTrue($payload['dots']['rooms_teams']);
        $this->assertContains('rooms_activities', $keys);
        $this->assertContains('rooms_teams', $keys);
        $this->assertFalse($payload['dots']['volunteers_staffing']);
    }

    public function test_count_mismatch_lights_schedule_once(): void
    {
        $this->seedEvent(date: '2026-11-20');
        DB::table('m_parameter')->insert([
            ['id' => 1, 'name' => 'e_teams'],
            ['id' => 2, 'name' => 'c_teams'],
            ['id' => 3, 'name' => 'f8_teams'],
        ]);
        DB::table('plan_param_value')->insert([
            ['plan' => 1, 'parameter' => 1, 'set_value' => '5'],
            ['plan' => 1, 'parameter' => 2, 'set_value' => '0'],
            ['plan' => 1, 'parameter' => 3, 'set_value' => '0'],
        ]);
        $this->mockDraht([
            [
                'first_program' => 1,
                'name' => 'EXPLORE',
                'teams' => [
                    ['ref' => '1', 'name' => 'A'],
                    ['ref' => '2', 'name' => 'B'],
                ],
            ],
        ]);

        $payload = $this->getJson('/api/events/1/notices')->assertOk()->json();
        $schedule = collect($payload['messages'])->where('key', 'schedule_counts');

        $this->assertTrue($payload['dots']['schedule']);
        $this->assertCount(1, $schedule);
        $this->assertSame('Teamzahlen oder Kapazitäten prüfen.', $schedule->first()['body']);
    }

    public function test_unattached_program_does_not_affect_schedule_counts(): void
    {
        $this->seedEvent(date: '2026-11-20');
        DB::table('m_parameter')->insert([
            ['id' => 1, 'name' => 'e_teams'],
            ['id' => 2, 'name' => 'c_teams'],
            ['id' => 3, 'name' => 'f8_teams'],
        ]);
        DB::table('plan_param_value')->insert([
            ['plan' => 1, 'parameter' => 1, 'set_value' => '1'],
            ['plan' => 1, 'parameter' => 2, 'set_value' => '99'],
            ['plan' => 1, 'parameter' => 3, 'set_value' => '0'],
        ]);
        $this->mockDraht([
            [
                'first_program' => 1,
                'name' => 'EXPLORE',
                'teams' => [['ref' => '1', 'name' => 'A']],
            ],
            [
                'first_program' => 2,
                'name' => 'CHALLENGE',
                'teams' => [['ref' => '9', 'name' => 'C']],
            ],
        ]);

        $payload = $this->getJson('/api/events/1/notices')->assertOk()->json();

        $this->assertFalse($payload['dots']['schedule']);
        $this->assertNotContains('schedule_counts', collect($payload['messages'])->pluck('key')->all());
    }

    public function test_time_outside_window_is_omitted_and_restore_does_not_resurrect_it(): void
    {
        $this->seedEvent(date: '2026-11-20');
        Carbon::setTestNow(Carbon::parse('2026-10-15', config('app.timezone')));

        $noticeId = (int) DB::table('m_notice')->where('key', 'time_registration_closed')->value('id');
        $this->postJson("/api/events/1/notices/{$noticeId}/hide")->assertNoContent();
        $this->postJson('/api/events/1/notices/restore')->assertNoContent();

        $payload = $this->getJson('/api/events/1/notices')->assertOk()->json();
        $this->assertNotContains('time_registration_closed', collect($payload['messages'])->pluck('key')->all());
        $this->assertFalse($payload['restore_available']);
    }

    public function test_hide_forbidden_on_red_dot_and_hides_in_window_time_row(): void
    {
        $this->seedEvent(date: '2026-11-20');

        $redId = (int) DB::table('m_notice')->where('key', 'rooms_activities')->value('id');
        $this->postJson("/api/events/1/notices/{$redId}/hide")->assertForbidden();

        $timeId = (int) DB::table('m_notice')->where('key', 'time_registration_closed')->value('id');
        $before = $this->getJson('/api/events/1/notices')->assertOk()->json();
        $this->assertContains('time_registration_closed', collect($before['messages'])->pluck('key')->all());
        $this->assertFalse($before['restore_available']);

        $this->postJson("/api/events/1/notices/{$timeId}/hide")->assertNoContent();

        $hidden = $this->getJson('/api/events/1/notices')->assertOk()->json();
        $this->assertNotContains('time_registration_closed', collect($hidden['messages'])->pluck('key')->all());
        $this->assertTrue($hidden['restore_available']);

        $this->postJson('/api/events/1/notices/restore')->assertNoContent();
        $restored = $this->getJson('/api/events/1/notices')->assertOk()->json();
        $this->assertContains('time_registration_closed', collect($restored['messages'])->pluck('key')->all());
        $this->assertFalse($restored['restore_available']);
    }

    public function test_relative_window_is_inclusive_on_start_and_event_day(): void
    {
        $this->seedEvent(date: '2026-09-20');
        $threeDays = 'Weniger als drei Tage bis zur Veranstaltung. Die Informationen zum Zeitplan auf der öffentlichen Seite sollten jetzt „Volle Details“ sein.';

        Carbon::setTestNow(Carbon::parse('2026-09-18', config('app.timezone')));
        $this->assertContains($threeDays, $this->messageBodies());

        Carbon::setTestNow(Carbon::parse('2026-09-20', config('app.timezone')));
        $this->assertContains($threeDays, $this->messageBodies());

        Carbon::setTestNow(Carbon::parse('2026-09-17', config('app.timezone')));
        $this->assertNotContains($threeDays, $this->messageBodies());

        Carbon::setTestNow(Carbon::parse('2026-09-21', config('app.timezone')));
        $this->assertNotContains($threeDays, $this->messageBodies());
    }

    /**
     * @return list<string>
     */
    private function messageKeys(): array
    {
        return collect($this->getJson('/api/events/1/notices')->assertOk()->json('messages'))
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function messageBodies(): array
    {
        return collect($this->getJson('/api/events/1/notices')->assertOk()->json('messages'))
            ->pluck('body')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{first_program: int, name: string, teams: list<array<string, string>>}>  $programs
     */
    private function mockDraht(array $programs): void
    {
        $this->mock(DrahtController::class, function ($mock) use ($programs) {
            $mock->shouldReceive('show')->andReturn(response()->json([
                'programs' => $programs,
            ]));
        });
    }

    /**
     * @param  list<array{id: int, name: string, display_name: string}>  $programs
     */
    private function seedEvent(string $date, bool $withPlan = true, array $programs = []): void
    {
        if ($programs === []) {
            $programs = [
                ['id' => 1, 'name' => 'EXPLORE', 'display_name' => 'Explore'],
            ];
        }

        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Test Event',
            'date' => $date,
        ]);

        foreach ($programs as $i => $program) {
            DB::table('m_first_program')->insert([
                'id' => $program['id'],
                'name' => $program['name'],
                'display_name' => $program['display_name'],
                'sequence' => $i + 1,
            ]);
            DB::table('event_program')->insert([
                'event' => 1,
                'first_program' => $program['id'],
                'draht_id' => 100 + $program['id'],
            ]);
        }

        if ($withPlan) {
            DB::table('plan')->insert([
                'id' => 1,
                'name' => 'Plan',
                'event' => 1,
            ]);
        }
    }

    private function seedCatalog(): void
    {
        $screens = [
            ['id' => 1, 'key' => 'publish-distribution', 'name' => 'Öffentliche Seite', 'route_path' => '/plan/publish', 'sort_order' => 1],
            ['id' => 3, 'key' => 'teams-program', 'name' => 'Details pro Team', 'route_path' => '/plan/teams/:program', 'sort_order' => 3],
            ['id' => 7, 'key' => 'rooms', 'name' => 'Räume', 'route_path' => '/plan/rooms', 'sort_order' => 4],
            ['id' => 9, 'key' => 'volunteers-staffing', 'name' => 'Zuordnung', 'route_path' => '/plan/volunteers/staffing', 'sort_order' => 7],
            ['id' => 12, 'key' => 'schedule-general', 'name' => 'Ablauf - Allgemein', 'route_path' => '/plan/schedule', 'sort_order' => 10],
        ];
        foreach ($screens as $screen) {
            DB::table('m_help_screen')->insert($screen);
        }

        $notices = [
            ['key' => 'teams_discrepancy', 'kind' => 'red_dot', 'condition_key' => 'teams_discrepancy', 'help_screen' => 3, 'body' => 'Die aktuellen Anmeldungen für {program} weichen von den Teams in FLOW ab. Bitte auf der Teams-Seite abgleichen.', 'sort_order' => 1, 'time_mode' => null],
            ['key' => 'schedule_counts', 'kind' => 'red_dot', 'condition_key' => 'schedule_counts', 'help_screen' => 12, 'body' => 'Teamzahlen oder Kapazitäten prüfen.', 'sort_order' => 2, 'time_mode' => null],
            ['key' => 'rooms_activities', 'kind' => 'red_dot', 'condition_key' => 'rooms_activities', 'help_screen' => 7, 'body' => 'Noch nicht alle Aktivitäten zugeordnet.', 'sort_order' => 3, 'time_mode' => null],
            ['key' => 'rooms_teams', 'kind' => 'red_dot', 'condition_key' => 'rooms_teams', 'help_screen' => 7, 'body' => 'Noch nicht alle Teams zugeordnet.', 'sort_order' => 4, 'time_mode' => null],
            ['key' => 'staffing_below_min', 'kind' => 'red_dot', 'condition_key' => 'staffing_below_min', 'help_screen' => 9, 'body' => 'Einige Rollen in {scope} sind noch unter der Mindestempfehlung.', 'sort_order' => 5, 'time_mode' => null],
            ['key' => 'staffing_surplus', 'kind' => 'red_dot', 'condition_key' => 'staffing_surplus', 'help_screen' => 9, 'body' => 'Überzählige Rollen sind noch besetzt.', 'sort_order' => 6, 'time_mode' => null],
            ['key' => 'time_registration_closed', 'kind' => 'time', 'condition_key' => null, 'help_screen' => 12, 'body' => 'Der Anmeldeschluss war am 31.10.26. Die Anzahl Teams pro Programm sind jetzt fest. Der Ablauf sollte nochmal angeschaut werden.', 'sort_order' => 10, 'time_mode' => 'absolute', 'abs_start' => '2026-11-01', 'abs_end' => '2026-11-30'],
            ['key' => 'time_publish_four_weeks', 'kind' => 'time', 'condition_key' => null, 'help_screen' => 1, 'body' => 'Weniger als vier Wochen bis zur Veranstaltung. Die Informationen zum Zeitplan auf der öffentlichen Seite sollten mindestens „Wichtige Zeiten“ sein.', 'sort_order' => 11, 'time_mode' => 'relative', 'rel_start_days' => -28, 'rel_end_days' => 0],
            ['key' => 'time_publish_three_days', 'kind' => 'time', 'condition_key' => null, 'help_screen' => 1, 'body' => 'Weniger als drei Tage bis zur Veranstaltung. Die Informationen zum Zeitplan auf der öffentlichen Seite sollten jetzt „Volle Details“ sein.', 'sort_order' => 12, 'time_mode' => 'relative', 'rel_start_days' => -2, 'rel_end_days' => 0],
        ];

        foreach ($notices as $row) {
            DB::table('m_notice')->insert([
                'key' => $row['key'],
                'kind' => $row['kind'],
                'condition_key' => $row['condition_key'],
                'help_screen' => $row['help_screen'],
                'title' => null,
                'body' => $row['body'],
                'sort_order' => $row['sort_order'],
                'time_mode' => $row['time_mode'],
                'abs_start' => $row['abs_start'] ?? null,
                'abs_end' => $row['abs_end'] ?? null,
                'rel_start_days' => $row['rel_start_days'] ?? null,
                'rel_end_days' => $row['rel_end_days'] ?? null,
            ]);
        }
    }

    private function createSchema(): void
    {
        Schema::create('m_help_screen', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('name', 255);
            $table->string('route_path', 255);
            $table->unsignedInteger('sort_order');
        });

        Schema::create('m_notice', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('key', 64)->unique();
            $table->string('kind', 16);
            $table->string('condition_key', 64)->nullable();
            $table->unsignedInteger('help_screen');
            $table->string('title', 255)->nullable();
            $table->text('body');
            $table->unsignedInteger('sort_order');
            $table->string('time_mode', 16)->nullable();
            $table->date('abs_start')->nullable();
            $table->date('abs_end')->nullable();
            $table->integer('rel_start_days')->nullable();
            $table->integer('rel_end_days')->nullable();
        });

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->date('date')->nullable();
        });

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('display_name')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('draht_id')->nullable();
        });

        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('event');
        });

        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100);
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->integer('team_number_hot');
        });

        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('team');
            $table->unsignedInteger('plan');
            $table->unsignedInteger('room')->nullable();
        });

        Schema::create('m_parameter', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });

        Schema::create('plan_param_value', function (Blueprint $table) {
            $table->unsignedInteger('plan');
            $table->unsignedInteger('parameter');
            $table->string('set_value')->nullable();
        });

        Schema::create('event_notice_hidden', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('notice');
            $table->unique(['event', 'notice']);
        });

        Schema::create('event_staffing_role', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('m_role')->nullable();
            $table->string('label', 150)->nullable();
            $table->string('group_label', 150)->nullable();
            $table->unsignedSmallInteger('min')->default(0);
            $table->unsignedSmallInteger('best')->default(0);
            $table->boolean('surplus')->default(false);
            $table->unsignedSmallInteger('sequence')->default(0);
        });

        Schema::create('event_staffing_group', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event_staffing_role');
            $table->boolean('surplus')->default(false);
        });

        Schema::create('event_staffing_assignment', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event_staffing_role')->nullable();
            $table->unsignedInteger('event_staffing_group')->nullable();
            $table->unsignedInteger('volunteer_person')->nullable();
        });
    }
}
