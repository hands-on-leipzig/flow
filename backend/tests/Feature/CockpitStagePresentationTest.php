<?php

namespace Tests\Feature;

use App\Enums\FirstProgram;
use App\Models\Event;
use App\Services\CockpitService;
use App\Services\CockpitStagePresentationService;
use App\Support\ProgramCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Programs are addressed by m_first_program.name; the fixtures seed the
 * catalog from the FirstProgram enum and resolve ids back through
 * ProgramCatalog, so no program id is written down here either.
 */
class CockpitStagePresentationTest extends TestCase
{
    private const CHALLENGE = 'CHALLENGE';

    private const FUTURE_8 = 'FUTURE_8';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Cockpit stage presentation tests require sqlite.');
        }

        $this->createSchema();
        $this->truncateData();
        $this->seedCatalog();
        $this->seedEvent();
    }

    // --- sections -------------------------------------------------------

    public function test_section_comes_from_presence_and_the_program_parameter(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);

        $state = $this->service()->state($this->event());

        $this->assertTrue($state['has_plan']);
        $this->assertCount(1, $state['programs']);
        $this->assertSame(self::CHALLENGE, $state['programs'][0]['program']);
        $this->assertSame('Challenge', $state['programs'][0]['program_label']);
        $this->assertSame(3, $state['programs'][0]['presentations']);
        $this->assertCount(3, $state['programs'][0]['slots']);
    }

    public function test_each_program_reads_its_own_presentation_parameter(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $this->attach(self::FUTURE_8, teams: 4);
        $this->setParam('c_presentations', 2);
        $this->setParam('f8_presentations', 4);

        $state = $this->service()->state($this->event());

        $this->assertSame(
            [self::CHALLENGE, self::FUTURE_8],
            array_column($state['programs'], 'program'),
            'Sections follow m_first_program.sequence.'
        );
        $this->assertSame(2, $state['programs'][0]['presentations']);
        $this->assertSame(4, $state['programs'][1]['presentations']);
    }

    public function test_program_with_zero_presentations_has_no_section(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $this->setParam('c_presentations', 0);

        $this->assertSame([], $this->service()->state($this->event())['programs']);
    }

    public function test_program_that_is_not_on_has_no_section(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $this->attach(self::FUTURE_8, teams: 0);

        $state = $this->service()->state($this->event());

        $this->assertSame([self::CHALLENGE], array_column($state['programs'], 'program'));
    }

    public function test_no_plan_reports_no_plan(): void
    {
        DB::table('plan')->delete();

        $state = $this->service()->state($this->event());

        $this->assertFalse($state['has_plan']);
        $this->assertSame([], $state['programs']);
    }

    // --- saving ---------------------------------------------------------

    public function test_selection_is_saved_and_returned(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $b = $this->seedTeam(self::CHALLENGE, 'Beta');

        $state = $this->service()->saveSelection($this->event(), self::CHALLENGE, [$b, null, $a]);
        $slots = $state['programs'][0]['slots'];

        $this->assertSame([$b, null, $a], array_column($slots, 'team'));
        $this->assertSame(['Beta', null, 'Alpha'], array_column($slots, 'team_name'));
    }

    public function test_duplicate_teams_are_rejected(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');

        $this->assertAborts(422, fn () => $this->service()->saveSelection(
            $this->event(),
            self::CHALLENGE,
            [$a, $a, null]
        ));
    }

    public function test_team_from_another_program_is_rejected(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $this->attach(self::FUTURE_8, teams: 4);
        $future = $this->seedTeam(self::FUTURE_8, 'Future Team');

        $this->assertAborts(422, fn () => $this->service()->saveSelection(
            $this->event(),
            self::CHALLENGE,
            [$future]
        ));
    }

    public function test_team_from_another_event_is_rejected(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $other = $this->seedTeam(self::CHALLENGE, 'Fremd', eventId: 2);

        $this->assertAborts(422, fn () => $this->service()->saveSelection(
            $this->event(),
            self::CHALLENGE,
            [$other]
        ));
    }

    public function test_unknown_program_is_rejected(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);

        $this->assertAborts(422, fn () => $this->service()->saveSelection(
            $this->event(),
            'EXPLORE',
            []
        ));
        $this->assertAborts(422, fn () => $this->service()->saveSelection(
            $this->event(),
            'NOT_A_PROGRAM',
            []
        ));
    }

    public function test_more_teams_than_presentations_is_rejected(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $this->setParam('c_presentations', 1);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $b = $this->seedTeam(self::CHALLENGE, 'Beta');

        $this->assertAborts(422, fn () => $this->service()->saveSelection(
            $this->event(),
            self::CHALLENGE,
            [$a, $b]
        ));
    }

    // --- lock -----------------------------------------------------------

    public function test_locking_blocks_saving_and_unlocking_reopens_it(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $this->setParam('c_presentations', 1);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a]);

        $locked = $this->service()->setLock($this->event(), self::CHALLENGE, true);
        $this->assertTrue($locked['programs'][0]['locked']);

        $this->assertAborts(423, fn () => $this->service()->saveSelection(
            $this->event(),
            self::CHALLENGE,
            [null]
        ));

        $open = $this->service()->setLock($this->event(), self::CHALLENGE, false);
        $this->assertFalse($open['programs'][0]['locked']);

        $state = $this->service()->saveSelection($this->event(), self::CHALLENGE, [null]);
        $this->assertNull($state['programs'][0]['slots'][0]['team']);
    }

    public function test_lock_is_per_program(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $this->attach(self::FUTURE_8, teams: 4);
        $this->setParam('c_presentations', 1);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a]);

        $state = $this->service()->setLock($this->event(), self::CHALLENGE, true);

        $this->assertTrue($state['programs'][0]['locked']);
        $this->assertFalse($state['programs'][1]['locked'], 'F8+ must stay open.');
    }

    public function test_locking_allows_empty_slots(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $c = $this->seedTeam(self::CHALLENGE, 'Gamma');

        // Nothing selected at all — empty places are allowed.
        $empty = $this->service()->setLock($this->event(), self::CHALLENGE, true);
        $this->assertTrue($empty['programs'][0]['locked']);
        $this->service()->setLock($this->event(), self::CHALLENGE, false);

        // Slots exist but are empty.
        $this->service()->saveSelection($this->event(), self::CHALLENGE, [null, null, null]);
        $allEmpty = $this->service()->setLock($this->event(), self::CHALLENGE, true);
        $this->assertTrue($allEmpty['programs'][0]['locked']);
        $this->service()->setLock($this->event(), self::CHALLENGE, false);

        // Partially filled is fine.
        $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a, null, $c]);
        $partial = $this->service()->setLock($this->event(), self::CHALLENGE, true);
        $this->assertTrue($partial['programs'][0]['locked']);
        $this->assertNull($partial['programs'][0]['slots'][1]['team']);
    }

    public function test_locking_with_lowered_presentations_uses_current_range(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $b = $this->seedTeam(self::CHALLENGE, 'Beta');
        $c = $this->seedTeam(self::CHALLENGE, 'Gamma');
        $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a, $b, $c]);

        // Two presentations now, but three filled rows are still on file.
        $this->setParam('c_presentations', 2);
        $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a, null]);

        $state = $this->service()->setLock($this->event(), self::CHALLENGE, true);
        $this->assertTrue($state['programs'][0]['locked']);
        $this->assertCount(2, $state['programs'][0]['slots']);
        $this->assertNull($state['programs'][0]['slots'][1]['team']);
    }

    public function test_lock_time_is_recorded_in_local_time(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $this->setParam('c_presentations', 1);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a]);

        $this->assertNull($this->service()->state($this->event())['programs'][0]['locked_at_time']);

        $berlin = now('Europe/Berlin');
        $state = $this->service()->setLock($this->event(), self::CHALLENGE, true);

        // Tolerate a minute rolling over during the call. A UTC-formatted
        // value would differ by the whole Berlin offset and fail here.
        $this->assertContains($state['programs'][0]['locked_at_time'], [
            $berlin->format('H:i'),
            $berlin->copy()->addMinute()->format('H:i'),
        ]);
    }

    public function test_unlocking_keeps_the_last_lock_time(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $this->setParam('c_presentations', 1);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a]);
        $locked = $this->service()->setLock($this->event(), self::CHALLENGE, true);

        $open = $this->service()->setLock($this->event(), self::CHALLENGE, false);

        $this->assertFalse($open['programs'][0]['locked']);
        $this->assertSame(
            $locked['programs'][0]['locked_at_time'],
            $open['programs'][0]['locked_at_time'],
            'Last locked is kept; there is no history and no clearing.'
        );
    }

    // --- parameter changes ----------------------------------------------

    public function test_lowering_presentations_shrinks_and_prunes_slots(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $b = $this->seedTeam(self::CHALLENGE, 'Beta');
        $c = $this->seedTeam(self::CHALLENGE, 'Gamma');
        $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a, $b, $c]);
        $this->assertSame(3, DB::table('stage_presentation_team')->count());

        $this->setParam('c_presentations', 2);
        $state = $this->service()->state($this->event());
        $this->assertCount(2, $state['programs'][0]['slots']);

        $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a, $b]);
        $this->assertSame(2, DB::table('stage_presentation_team')->count());
    }

    // --- no-show --------------------------------------------------------

    public function test_noshow_team_is_not_offered_but_stays_selected(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $b = $this->seedTeam(self::CHALLENGE, 'Beta');
        $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a, $b]);

        DB::table('team_plan')->insert([
            'team' => $a,
            'plan' => 1,
            'team_number_plan' => 1,
            'noshow' => true,
        ]);

        $section = $this->service()->state($this->event())['programs'][0];

        $this->assertSame(['Beta'], array_column($section['teams'], 'name'));
        $this->assertSame($a, $section['slots'][0]['team']);
        $this->assertSame('Alpha', $section['slots'][0]['team_name']);

        // Re-saving the other slots must not fail on the no-show team.
        $again = $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a, null]);
        $this->assertSame($a, $again['programs'][0]['slots'][0]['team']);
    }

    // --- reset ----------------------------------------------------------

    public function test_reset_drops_selection_and_lock_of_this_event_only(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $this->setParam('c_presentations', 1);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $this->service()->saveSelection($this->event(), self::CHALLENGE, [$a]);
        $this->service()->setLock($this->event(), self::CHALLENGE, true);

        $otherStage = (int) DB::table('stage_presentation')->insertGetId([
            'event' => 2,
            'first_program' => $this->programId(self::CHALLENGE),
            'locked' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stage_presentation_team')->insert([
            'stage_presentation' => $otherStage,
            'slot' => 1,
            'team' => null,
        ]);

        $this->assertSame(1, $this->service()->reset($this->event()));

        $section = $this->service()->state($this->event())['programs'][0];
        $this->assertNull($section['slots'][0]['team']);
        $this->assertFalse($section['locked']);
        $this->assertNull($section['locked_at_time'], 'The lock time goes with the selection.');

        $this->assertSame(1, DB::table('stage_presentation')->where('event', 2)->count());
        $this->assertSame(
            1,
            DB::table('stage_presentation_team')->where('stage_presentation', $otherStage)->count()
        );
    }

    public function test_reset_without_any_selection_does_nothing(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);

        $this->assertSame(0, $this->service()->reset($this->event()));
        $this->assertCount(3, $this->service()->state($this->event())['programs'][0]['slots']);
    }

    // --- endpoints ------------------------------------------------------

    public function test_endpoints_require_a_cockpit_token(): void
    {
        $this->getJson('/api/cockpit/day-event/stage-presentations/bootstrap')->assertUnauthorized();
        $this->putJson('/api/cockpit/day-event/stage-presentations/selection', [
            'program' => self::CHALLENGE,
            'teams' => [],
        ])->assertUnauthorized();
        $this->putJson('/api/cockpit/day-event/stage-presentations/lock', [
            'program' => self::CHALLENGE,
            'locked' => true,
        ])->assertUnauthorized();
    }

    public function test_endpoints_save_and_lock_by_program_name(): void
    {
        $this->attach(self::CHALLENGE, teams: 6);
        $this->setParam('c_presentations', 1);
        $a = $this->seedTeam(self::CHALLENGE, 'Alpha');
        $token = (string) $this->postJson('/api/cockpit/day-event/session', ['pin' => '654321'])->json('token');
        $this->assertNotSame('', $token);

        $this->withHeader('X-Cockpit-Token', $token)
            ->putJson('/api/cockpit/day-event/stage-presentations/selection', [
                'program' => self::CHALLENGE,
                'teams' => [$a],
            ])
            ->assertOk()
            ->assertJsonPath('programs.0.slots.0.team', $a);

        $this->withHeader('X-Cockpit-Token', $token)
            ->putJson('/api/cockpit/day-event/stage-presentations/lock', [
                'program' => self::CHALLENGE,
                'locked' => true,
            ])
            ->assertOk()
            ->assertJsonPath('programs.0.locked', true);

        $this->withHeader('X-Cockpit-Token', $token)
            ->putJson('/api/cockpit/day-event/stage-presentations/selection', [
                'program' => self::CHALLENGE,
                'teams' => [null, null, null],
            ])
            ->assertStatus(423);
    }

    // --- helpers --------------------------------------------------------

    private function service(): CockpitStagePresentationService
    {
        return app(CockpitStagePresentationService::class);
    }

    private function event(): Event
    {
        return Event::query()->findOrFail(1);
    }

    private function assertAborts(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected a {$status} abort.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function programId(string $name): int
    {
        $program = ProgramCatalog::resolve($name);
        $this->assertNotNull($program, "Program {$name} missing from the catalog.");

        return (int) $program->id;
    }

    /** Attach a program to the event and set its team count. */
    private function attach(string $name, int $teams): void
    {
        DB::table('event_program')->insert([
            'event' => 1,
            'first_program' => $this->programId($name),
        ]);

        $this->setParam($name === self::CHALLENGE ? 'c_teams' : 'f8_teams', $teams);
    }

    private function setParam(string $name, int $value): void
    {
        $parameterId = (int) DB::table('m_parameter')->where('name', $name)->value('id');

        DB::table('plan_param_value')->updateOrInsert(
            ['plan' => 1, 'parameter' => $parameterId],
            ['set_value' => (string) $value],
        );
    }

    private function seedTeam(string $program, string $name, int $eventId = 1): int
    {
        static $hot = 100;

        return (int) DB::table('team')->insertGetId([
            'name' => $name,
            'event' => $eventId,
            'first_program' => $this->programId($program),
            'team_number_hot' => $hot++,
        ]);
    }

    private function seedCatalog(): void
    {
        // Seeded from the enum so the test carries no program id literals.
        DB::table('m_first_program')->insert([
            [
                'id' => FirstProgram::EXPLORE->value,
                'name' => 'EXPLORE',
                'display_name' => 'Explore',
                'letter' => 'E',
                'sequence' => 1,
                'logo_stem' => 'fll_explore',
            ],
            [
                'id' => FirstProgram::CHALLENGE->value,
                'name' => 'CHALLENGE',
                'display_name' => 'Challenge',
                'letter' => 'C',
                'sequence' => 2,
                'logo_stem' => 'fll_challenge',
            ],
            [
                'id' => FirstProgram::FUTURE_8->value,
                'name' => 'FUTURE_8',
                'display_name' => 'Future 8+',
                'letter' => 'F8',
                'sequence' => 5,
                'logo_stem' => 'fll_future8',
            ],
        ]);

        $rows = [
            ['c_mode', 0, FirstProgram::CHALLENGE],
            ['c_teams', 0, FirstProgram::CHALLENGE],
            ['c_presentations', 3, FirstProgram::CHALLENGE],
            ['f8_mode', 0, FirstProgram::FUTURE_8],
            ['f8_teams', 0, FirstProgram::FUTURE_8],
            ['f8_presentations', 3, FirstProgram::FUTURE_8],
            ['e_mode', 0, FirstProgram::EXPLORE],
        ];

        $id = 1;
        foreach ($rows as [$name, $default, $program]) {
            DB::table('m_parameter')->insert([
                'id' => $id++,
                'name' => $name,
                'context' => 'input',
                'level' => 1,
                'type' => 'integer',
                'value' => (string) $default,
                'min' => '0',
                'max' => '30',
                'step' => '1',
                'first_program' => $program->value,
            ]);
        }
    }

    private function seedEvent(): void
    {
        $month = (int) date('n');
        $year = (int) date('Y');
        DB::table('m_season')->insert(['id' => 1, 'year' => $month <= 4 ? $year - 1 : $year]);

        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Day Event',
            'slug' => 'day-event',
            'level' => 1,
            'season' => 1,
            'date' => date('Y-m-d'),
            'days' => 1,
            'cockpit_enabled' => true,
            'cockpit_pin' => app(CockpitService::class)->encryptPin('654321'),
        ]);
        DB::table('event')->insert([
            'id' => 2,
            'name' => 'Other Event',
            'slug' => 'other-event',
            'level' => 1,
            'season' => 1,
            'date' => date('Y-m-d'),
            'days' => 1,
            'cockpit_enabled' => false,
        ]);

        DB::table('plan')->insert(['id' => 1, 'name' => 'Zeitplan', 'event' => 1]);
    }

    private function truncateData(): void
    {
        foreach ([
            'stage_presentation_team',
            'stage_presentation',
            'team_plan',
            'team',
            'plan_param_value',
            'plan',
            'event_program',
            'event',
            'm_parameter',
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
                $table->string('name', 50);
                $table->string('display_name')->nullable();
                $table->string('letter', 10)->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->string('color_hex', 10)->nullable();
                $table->string('logo_stem')->nullable();
                $table->string('logo_white')->nullable();
            });
        }

        if (! Schema::hasTable('m_parameter')) {
            Schema::create('m_parameter', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->string('name')->nullable()->unique();
                $table->string('context')->nullable();
                $table->unsignedInteger('level');
                $table->string('type')->nullable();
                $table->string('value')->nullable();
                $table->string('min')->nullable();
                $table->string('max')->nullable();
                $table->string('step')->nullable();
                $table->unsignedInteger('first_program')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->string('ui_label')->nullable();
            });
        }

        if (! Schema::hasTable('plan_param_value')) {
            Schema::create('plan_param_value', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('plan');
                $table->unsignedInteger('parameter');
                $table->string('set_value')->nullable();
                $table->unique(['plan', 'parameter']);
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

        if (! Schema::hasTable('event_program')) {
            Schema::create('event_program', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program')->nullable();
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

        if (! Schema::hasTable('team')) {
            Schema::create('team', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 100);
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program');
                $table->integer('team_number_hot');
            });
        }

        if (! Schema::hasTable('team_plan')) {
            Schema::create('team_plan', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('team');
                $table->unsignedInteger('plan');
                $table->integer('team_number_plan');
                $table->boolean('noshow')->default(false);
            });
        }

        if (! Schema::hasTable('stage_presentation')) {
            Schema::create('stage_presentation', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program');
                $table->boolean('locked')->default(false);
                $table->dateTime('locked_at')->nullable();
                $table->timestamps();
                $table->unique(['event', 'first_program']);
            });
        }

        if (! Schema::hasTable('stage_presentation_team')) {
            Schema::create('stage_presentation_team', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('stage_presentation');
                $table->unsignedTinyInteger('slot');
                $table->unsignedInteger('team')->nullable();
                $table->unique(['stage_presentation', 'slot']);
            });
        }
    }
}
