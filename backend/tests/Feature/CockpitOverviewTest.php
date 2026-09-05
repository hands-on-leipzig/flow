<?php

namespace Tests\Feature;

use App\Enums\FirstProgram;
use App\Models\CheckIn;
use App\Models\Event;
use App\Services\CheckInService;
use App\Services\CockpitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CockpitOverviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Cockpit overview tests require sqlite.');
        }

        $this->createSchema();
        $this->truncateData();
        $this->seedCatalog();
        $this->seedEvent();
    }

    public function test_overview_places_teams_and_helpers_with_status(): void
    {
        $challengeId = FirstProgram::CHALLENGE->value;
        $teamPresent = $this->seedTeam($challengeId, 'Alpha', 'Acme', 1001);
        $teamAbsent = $this->seedTeam($challengeId, 'Beta', null, 1002);
        $helperId = $this->seedHelper('Greta', 'Guide', $challengeId, 'Juror:in');

        DB::table('check_in')->insert([
            'event' => 1,
            'subject_type' => CheckIn::SUBJECT_TEAM,
            'subject_id' => $teamPresent,
            'status' => CheckIn::STATUS_CHECKED_IN,
            'checked_in_at' => now(),
        ]);
        DB::table('check_in')->insert([
            'event' => 1,
            'subject_type' => CheckIn::SUBJECT_TEAM,
            'subject_id' => $teamAbsent,
            'status' => CheckIn::STATUS_NO_SHOW,
        ]);

        $state = app(CheckInService::class)->overviewAttendance($this->event());

        $this->assertCount(1, $state['scopes']);
        $scope = $state['scopes'][0];
        $this->assertSame('program', $scope['kind']);
        $this->assertSame($challengeId, $scope['program_id']);
        $this->assertSame('Challenge', $scope['label']);

        $this->assertSame(['Alpha', 'Beta'], array_column($scope['teams'], 'label'));
        $this->assertSame('Acme · (1001)', $scope['teams'][0]['subtitle']);
        $this->assertSame(CheckIn::STATUS_CHECKED_IN, $scope['teams'][0]['status']);
        $this->assertSame(CheckIn::STATUS_NO_SHOW, $scope['teams'][1]['status']);

        $this->assertCount(1, $scope['helper_buckets']);
        $this->assertSame('Juror:in', $scope['helper_buckets'][0]['label']);
        $this->assertSame($helperId, $scope['helper_buckets'][0]['people'][0]['id']);
        $this->assertNull($scope['helper_buckets'][0]['people'][0]['status']);
    }

    public function test_overview_endpoint_requires_cockpit_token(): void
    {
        $this->getJson('/api/cockpit/day-event/overview')->assertUnauthorized();
    }

    public function test_overview_endpoint_returns_scopes(): void
    {
        $this->seedTeam(FirstProgram::CHALLENGE->value, 'Alpha', null, 42);
        $token = (string) $this->postJson('/api/cockpit/day-event/session', ['pin' => '654321'])->json('token');

        $this->withHeader('X-Cockpit-Token', $token)
            ->getJson('/api/cockpit/day-event/overview')
            ->assertOk()
            ->assertJsonPath('scopes.0.teams.0.label', 'Alpha');
    }

    private function event(): Event
    {
        return Event::query()->findOrFail(1);
    }

    private function seedTeam(int $programId, string $name, ?string $org, int $hot): int
    {
        return (int) DB::table('team')->insertGetId([
            'name' => $name,
            'event' => 1,
            'first_program' => $programId,
            'organization' => $org,
            'team_number_hot' => $hot,
        ]);
    }

    private function seedHelper(string $first, string $last, int $programId, string $roleLabel): int
    {
        $personId = (int) DB::table('volunteer_person')->insertGetId([
            'first_name' => $first,
            'last_name' => $last,
            'regional_partner' => 1,
        ]);

        $mRoleId = (int) DB::table('m_role')->insertGetId([
            'name' => $roleLabel,
            'first_program' => $programId,
            'sequence' => 1,
        ]);

        $roleId = (int) DB::table('event_staffing_role')->insertGetId([
            'event' => 1,
            'm_role' => $mRoleId,
            'label' => $roleLabel,
        ]);

        DB::table('event_staffing_assignment')->insert([
            'event_staffing_role' => $roleId,
            'volunteer_person' => $personId,
        ]);

        return $personId;
    }

    private function seedCatalog(): void
    {
        DB::table('m_first_program')->insert([
            [
                'id' => FirstProgram::CHALLENGE->value,
                'name' => 'CHALLENGE',
                'display_name' => 'Challenge',
                'letter' => 'C',
                'sequence' => 2,
                'logo_stem' => 'fll_challenge',
            ],
        ]);
    }

    private function seedEvent(): void
    {
        $month = (int) date('n');
        $year = (int) date('Y');
        DB::table('m_season')->insert(['id' => 1, 'year' => $month <= 4 ? $year - 1 : $year]);
        DB::table('regional_partner')->insert(['id' => 1, 'name' => 'RP']);

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
    }

    private function truncateData(): void
    {
        foreach ([
            'check_in',
            'event_staffing_assignment',
            'event_staffing_group',
            'event_staffing_role',
            'volunteer_person',
            'm_role',
            'team',
            'event_program',
            'event',
            'm_first_program',
            'm_season',
            'regional_partner',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('check_in');
        Schema::dropIfExists('event_staffing_assignment');
        Schema::dropIfExists('event_staffing_group');
        Schema::dropIfExists('event_staffing_role');
        Schema::dropIfExists('volunteer_person');
        Schema::dropIfExists('m_role');
        Schema::dropIfExists('team');
        Schema::dropIfExists('event_program');
        Schema::dropIfExists('event');
        Schema::dropIfExists('m_first_program');
        Schema::dropIfExists('m_season');
        Schema::dropIfExists('regional_partner');

        Schema::create('m_season', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('year');
        });

        Schema::create('regional_partner', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 50);
            $table->string('display_name')->nullable();
            $table->string('letter', 10)->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->string('logo_stem')->nullable();
        });

        Schema::create('event', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->unsignedInteger('level')->nullable();
            $table->unsignedInteger('season')->nullable();
            $table->date('date')->nullable();
            $table->unsignedInteger('days')->nullable();
            $table->boolean('cockpit_enabled')->default(false);
            $table->text('cockpit_pin')->nullable();
            $table->string('link')->nullable();
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('draht_id')->nullable();
        });

        Schema::create('team', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program')->nullable();
            $table->string('organization')->nullable();
            $table->integer('team_number_hot')->nullable();
        });

        Schema::create('m_role', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('sequence')->nullable();
        });

        Schema::create('volunteer_person', function (Blueprint $table) {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('organization')->nullable();
            $table->unsignedInteger('regional_partner')->nullable();
        });

        Schema::create('event_staffing_role', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->unsignedInteger('m_role')->nullable();
            $table->string('label')->nullable();
            $table->string('group_label')->nullable();
        });

        Schema::create('event_staffing_group', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event_staffing_role');
            $table->unsignedInteger('group_index')->nullable();
        });

        Schema::create('event_staffing_assignment', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event_staffing_role');
            $table->unsignedInteger('event_staffing_group')->nullable();
            $table->unsignedInteger('volunteer_person');
        });

        Schema::create('check_in', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->string('subject_type');
            $table->unsignedInteger('subject_id');
            $table->string('status')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->text('reception_note')->nullable();
            $table->string('no_show_reason')->nullable();
            $table->string('no_show_source')->nullable();
        });
    }
}
