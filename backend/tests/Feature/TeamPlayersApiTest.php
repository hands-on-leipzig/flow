<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EventTeamPlayersController;
use App\Models\Event;
use App\Models\Team;
use App\Support\TeamDataIndex;
use App\Support\TeamPlayers;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TeamPlayersApiTest extends TestCase
{
    private const CHALLENGE_RULE = ['minAge' => 9, 'maxAge' => 16, 'referenceDate' => '2026-01-01'];

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Team players API tests require sqlite.');
        }

        Carbon::setTestNow('2026-10-19');
        config(['services.draht_api.base_url' => 'http://draht.test', 'services.draht_api.key' => 'k']);
        $this->createSchema();
        $this->seedBase();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_age_helpers_count_on_reference_date(): void
    {
        $this->assertNull(TeamPlayers::ageOutOfRange('2017-01-01', self::CHALLENGE_RULE));
        $this->assertSame(8, TeamPlayers::ageOutOfRange('2017-01-02', self::CHALLENGE_RULE));
        $this->assertSame(17, TeamPlayers::ageOutOfRange('2009-01-01', self::CHALLENGE_RULE));
        $this->assertNull(TeamPlayers::ageOutOfRange(null, self::CHALLENGE_RULE));
        $this->assertNull(TeamPlayers::ageOutOfRange('2001-01-01', null));
    }

    public function test_draht_timestamps_are_read_as_berlin_calendar_days(): void
    {
        $justAfterMidnightBerlin = Carbon::create(2015, 3, 10, 0, 30, 0, 'Europe/Berlin')->getTimestamp();

        $this->assertSame('2015-03-10', TeamPlayers::birthdayYmd($justAfterMidnightBerlin));
        $this->assertNull(TeamPlayers::birthdayYmd('2015-02-30'));
    }

    public function test_show_returns_players_with_age_flags(): void
    {
        Http::fake([
            'http://draht.test/handson/flow/500/people' => Http::response($this->peoplePayload([
                ['firstname' => 'Ada', 'name' => 'A', 'gender' => 'f', 'birthday' => $this->ts('2014-05-05')],
                ['firstname' => 'Ben', 'name' => 'B', 'gender' => 'M', 'birthday' => $this->ts('2008-06-01')],
                ['firstname' => 'Cem', 'name' => 'C', 'gender' => '', 'birthday' => null],
            ])),
        ]);

        $response = app(EventTeamPlayersController::class)->show(Event::findOrFail(1), Team::findOrFail(1));

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame(10, $data['max_players']);
        $this->assertSame(self::CHALLENGE_RULE, $data['player_age_rule']);
        $this->assertSame(
            [
                ['firstname' => 'Ada', 'name' => 'A', 'gender' => 'F', 'birthday' => '2014-05-05', 'age_out_of_range' => null],
                ['firstname' => 'Ben', 'name' => 'B', 'gender' => 'M', 'birthday' => '2008-06-01', 'age_out_of_range' => 17],
                ['firstname' => 'Cem', 'name' => 'C', 'gender' => '', 'birthday' => null, 'age_out_of_range' => null],
            ],
            $data['players'],
        );

        $row = TeamDataIndex::teamPayload(Event::findOrFail(1), Team::findOrFail(1));
        $this->assertSame(3, $row['players_count']);
        $this->assertSame(1, $row['players_age_out_of_range']);
    }

    public function test_update_sends_roster_to_draht_and_returns_updated_team(): void
    {
        $saved = [['firstname' => 'Ada', 'name' => 'A', 'gender' => 'F', 'birthday' => $this->ts('2008-06-01')]];
        Http::fake([
            'http://draht.test/handson/flow/500/teams/10/players' => Http::response([
                'id' => 77,
                'max_players' => 10,
                'player_age_rule' => self::CHALLENGE_RULE,
                'players' => $saved,
            ]),
            'http://draht.test/handson/flow/500/people' => Http::response($this->peoplePayload($saved)),
        ]);

        $response = app(EventTeamPlayersController::class)->update(
            Request::create('/', 'PUT', ['players' => [
                ['firstname' => ' Ada ', 'name' => 'A', 'gender' => 'F', 'birthday' => '2008-06-01'],
                ['firstname' => '', 'name' => '', 'gender' => null, 'birthday' => null],
            ]]),
            Event::findOrFail(1),
            Team::findOrFail(1),
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame(17, $data['players'][0]['age_out_of_range']);
        $this->assertSame(1, $data['team']['players_age_out_of_range']);

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
            && $request->url() === 'http://draht.test/handson/flow/500/teams/10/players'
            && $request->data() === ['players' => [
                ['firstname' => 'Ada', 'name' => 'A', 'gender' => 'F', 'birthday' => '2008-06-01'],
            ]]);
    }

    public function test_update_passes_draht_rejection_as_validation_error(): void
    {
        Http::fake([
            'http://draht.test/handson/flow/500/teams/10/players' => Http::response(
                ['error' => ['code' => 400, 'message' => 'At most 10 team members allowed for this program']],
                400,
            ),
        ]);

        $response = app(EventTeamPlayersController::class)->update(
            Request::create('/', 'PUT', ['players' => []]),
            Event::findOrFail(1),
            Team::findOrFail(1),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('At most 10 team members allowed for this program', $response->getData(true)['error']);
    }

    public function test_update_rejects_invalid_birthday(): void
    {
        Http::fake();

        $this->expectException(ValidationException::class);
        app(EventTeamPlayersController::class)->update(
            Request::create('/', 'PUT', ['players' => [['firstname' => 'X', 'birthday' => '10.03.2015']]]),
            Event::findOrFail(1),
            Team::findOrFail(1),
        );
    }

    public function test_team_without_draht_link_is_not_found(): void
    {
        DB::table('event_program')->update(['draht_id' => null]);
        Http::fake();

        $response = app(EventTeamPlayersController::class)->show(Event::findOrFail(1), Team::findOrFail(1));

        $this->assertSame(404, $response->getStatusCode());
        Http::assertNothingSent();
    }

    private function ts(string $ymd): int
    {
        return Carbon::parse($ymd.' 12:00', 'Europe/Berlin')->getTimestamp();
    }

    /**
     * @param  list<array<string, mixed>>  $players
     * @return array<string, mixed>
     */
    private function peoplePayload(array $players): array
    {
        return [
            '10' => [
                'id' => 77,
                'name' => 'Team A',
                'program' => 2,
                'max_players' => 10,
                'player_age_rule' => self::CHALLENGE_RULE,
                'players' => $players,
                'coaches' => [['name' => 'Coach', 'firstname' => 'C']],
            ],
            'total_players' => count($players),
            'total_coaches' => 1,
        ];
    }

    private function seedBase(): void
    {
        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Test',
            'slug' => 'test',
            'regional_partner' => 1,
            'level' => 1,
            'season' => 1,
            'date' => '2026-11-14',
            'days' => 1,
            'collect_meal' => false,
        ]);
        DB::table('m_first_program')->insert(['id' => 3, 'name' => 'CHALLENGE', 'sequence' => 2]);
        DB::table('event_program')->insert(['event' => 1, 'first_program' => 3, 'draht_id' => 500]);
        DB::table('team')->insert([
            'id' => 1,
            'event' => 1,
            'first_program' => 3,
            'name' => 'Team A',
            'team_number_hot' => 10,
            'organization' => 'Test School',
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->unsignedInteger('regional_partner')->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->unsignedInteger('season')->nullable();
            $table->date('date')->nullable();
            $table->unsignedTinyInteger('days')->default(1);
            $table->boolean('collect_meal')->default(true);
        });
        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
        });
        Schema::create('event_program', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program')->nullable();
            $table->unsignedInteger('draht_id')->nullable();
            $table->unsignedInteger('contao_id')->nullable();
        });
        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program')->nullable();
            $table->string('name');
            $table->unsignedInteger('team_number_hot')->nullable();
            $table->string('organization')->nullable();
        });
        Schema::create('event_team_field', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->string('field_key', 64);
            $table->string('label', 120);
            $table->string('type', 20);
            $table->json('options')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('public_form')->default(false);
            $table->timestamps();
        });
        Schema::create('event_team_field_value', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team');
            $table->unsignedInteger('event_team_field');
            $table->text('value');
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('event_team_photo_count', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team');
            $table->string('bucket', 16);
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('event_team_meal_count', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team');
            $table->string('meal_value', 64);
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }
}
