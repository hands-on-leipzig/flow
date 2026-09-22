<?php

namespace Tests\Feature;

use App\Http\Middleware\KeycloakJwtMiddleware;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AdminCockpitApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Admin cockpit tests require sqlite.');
        }

        $this->withoutMiddleware(KeycloakJwtMiddleware::class);
        $this->createSchema();
        $this->seedBase();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_season_scoping_one_row_per_event_and_qplan_rp_excluded(): void
    {
        $payload = $this->getJson('/api/admin/cockpit?season=1')->assertOk()->json();

        $this->assertSame(1, $payload['season_id']);
        $ids = collect($payload['events'])->pluck('event_id')->all();
        $this->assertSame([1, 2], $ids);
        $this->assertCount(2, $payload['events']);
    }

    public function test_invalid_season_falls_back_to_current(): void
    {
        $payload = $this->getJson('/api/admin/cockpit?season=999')->assertOk()->json();

        $this->assertSame(1, $payload['season_id']);
    }

    public function test_unattached_program_team_cell_is_null(): void
    {
        $row = $this->eventRow(1);

        $this->assertSame(2, $row['teams']['2']);
        $this->assertSame(0, $row['teams']['3']);
        $this->assertNull($row['teams']['8']);
        $this->assertSame([2, 3], $row['programs']);
    }

    public function test_no_plan_nulls_plan_fields_and_lights_plan_dot(): void
    {
        $row = $this->eventRow(2);

        $this->assertNull($row['plan_id']);
        $this->assertNull($row['generator_last_end']);
        $this->assertNull($row['generator_count']);
        $this->assertNull($row['param_changes']);
        $this->assertNull($row['extra_blocks']);
        $this->assertNull($row['publication_level']);
        $this->assertSame(0, $row['access_count']);
        $this->assertTrue($row['dots']['plan']);
        $this->assertTrue($row['dots']['rooms']);
        $this->assertFalse($row['dots']['staffing']);
        $this->assertNull($row['dots']['team']);
        $this->assertSame(0, $row['helferliste_count']);
        $this->assertFalse($row['public_helper_search']);
    }

    public function test_plan_row_counts_helferliste_params_blocks_and_generator(): void
    {
        $row = $this->eventRow(1);

        $this->assertSame(10, $row['plan_id']);
        $this->assertSame(3, $row['helferliste_count']);
        $this->assertTrue($row['public_helper_search']);
        $this->assertSame(['input' => 1, 'expert' => 1], $row['param_changes']);
        $this->assertSame(['free' => 2, 'slot' => 1], $row['extra_blocks']);
        $this->assertSame(4, $row['publication_level']);
        $this->assertNotNull($row['generator_last_end']);
        $this->assertSame(2, $row['generator_count']);
        $this->assertSame(3, $row['access_count']);
        $this->assertNull($row['dots']['team']);
        $this->assertTrue($row['dots']['plan']);
        $this->assertFalse($row['dots']['rooms']);
        $this->assertFalse($row['dots']['staffing']);
    }

    public function test_xlsx_upcoming_keeps_null_dates_drops_past_and_program_or(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20', config('app.timezone')));
        DB::table('event')->insert([
            ['id' => 5, 'name' => 'Undated', 'date' => null, 'season' => 1, 'regional_partner' => 42, 'level' => 1],
        ]);
        DB::table('event_program')->insert([
            ['event' => 5, 'first_program' => 8],
        ]);

        $response = $this->get('/api/admin/cockpit.xlsx?season=1&upcoming=1&programs=8');
        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('Content-Type'),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'cockpit-xlsx-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $response->getContent());

        try {
            $sheet = IOFactory::load($tmp)->getSheet(0);
            $this->assertSame('Räume', $sheet->getCell('H1')->getValue());
            $this->assertSame('—', $sheet->getCell('G2')->getValue());
            $this->assertNotSame('', (string) $sheet->getCell('C2')->getValue());
            $this->assertSame('', (string) $sheet->getCell('C3')->getValue());
        } finally {
            @unlink($tmp);
        }
    }

    public function test_xlsx_without_plan_keeps_never_generated_and_ands_with_programs(): void
    {
        $response = $this->get('/api/admin/cockpit.xlsx?season=1&upcoming=0&programs=2,3,8&without_plan=1');
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'cockpit-xlsx-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $response->getContent());

        try {
            $sheet = IOFactory::load($tmp)->getSheet(0);
            $this->assertStringContainsString('Hamburg', (string) $sheet->getCell('C2')->getValue());
            $this->assertStringNotContainsString('München', (string) $sheet->getCell('C2')->getValue());
            $this->assertSame('', (string) $sheet->getCell('C3')->getValue());
        } finally {
            @unlink($tmp);
        }
    }

    public function test_xlsx_helferliste_empty_and_filled_and_with_programs(): void
    {
        $empty = $this->get('/api/admin/cockpit.xlsx?season=1&upcoming=0&programs=2,3,8&helferliste=empty');
        $empty->assertOk();
        $emptyTmp = tempnam(sys_get_temp_dir(), 'cockpit-xlsx-');
        $this->assertNotFalse($emptyTmp);
        file_put_contents($emptyTmp, $empty->getContent());

        try {
            $sheet = IOFactory::load($emptyTmp)->getSheet(0);
            $this->assertStringContainsString('Hamburg', (string) $sheet->getCell('C2')->getValue());
            $this->assertSame('', (string) $sheet->getCell('C3')->getValue());
        } finally {
            @unlink($emptyTmp);
        }

        $filled = $this->get('/api/admin/cockpit.xlsx?season=1&upcoming=0&programs=2,3,8&helferliste=filled');
        $filled->assertOk();
        $filledTmp = tempnam(sys_get_temp_dir(), 'cockpit-xlsx-');
        $this->assertNotFalse($filledTmp);
        file_put_contents($filledTmp, $filled->getContent());

        try {
            $sheet = IOFactory::load($filledTmp)->getSheet(0);
            $this->assertStringContainsString('München', (string) $sheet->getCell('C2')->getValue());
            $this->assertSame('', (string) $sheet->getCell('C3')->getValue());
        } finally {
            @unlink($filledTmp);
        }
    }

    public function test_xlsx_empty_programs_returns_no_event_rows(): void
    {
        $response = $this->get('/api/admin/cockpit.xlsx?season=1&upcoming=0');
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'cockpit-xlsx-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $response->getContent());

        try {
            $sheet = IOFactory::load($tmp)->getSheet(0);
            $this->assertSame('RP', $sheet->getCell('A1')->getValue());
            $this->assertSame('', (string) $sheet->getCell('A2')->getValue());
        } finally {
            @unlink($tmp);
        }
    }

    public function test_new_php_files_do_not_reference_draht(): void
    {
        $service = file_get_contents(app_path('Services/AdminCockpitService.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/AdminCockpitController.php'));
        $xlsx = file_get_contents(app_path('Export/Admin/AdminCockpitSpreadsheetSource.php'));

        $this->assertStringNotContainsString('DrahtController', $service);
        $this->assertStringNotContainsString('DrahtController', $controller);
        $this->assertStringNotContainsString('DrahtController', $xlsx);
        $this->assertStringNotContainsString('/draht/', $service);
        $this->assertStringNotContainsString('/draht/', $controller);
    }

    private function eventRow(int $eventId): array
    {
        $payload = $this->getJson('/api/admin/cockpit?season=1')->assertOk()->json();
        $row = collect($payload['events'])->firstWhere('event_id', $eventId);
        $this->assertIsArray($row);

        return $row;
    }

    private function seedBase(): void
    {
        DB::table('m_season')->insert([
            ['id' => 1, 'name' => 'Saison', 'year' => 2026],
            ['id' => 2, 'name' => 'Alt', 'year' => 2025],
        ]);
        DB::table('regional_partner')->insert([
            ['id' => 42, 'name' => 'München'],
            ['id' => 43, 'name' => 'Hamburg'],
            ['id' => 99, 'name' => 'QPlan RP Test'],
        ]);
        DB::table('m_first_program')->insert([
            ['id' => 2, 'name' => 'EXPLORE', 'display_name' => 'Explore', 'sequence' => 1],
            ['id' => 3, 'name' => 'CHALLENGE', 'display_name' => 'Challenge', 'sequence' => 2],
            ['id' => 8, 'name' => 'FUTURE_8', 'display_name' => 'Future 8+', 'sequence' => 3],
        ]);
        DB::table('event')->insert([
            ['id' => 1, 'name' => 'München', 'date' => '2026-11-14', 'season' => 1, 'regional_partner' => 42, 'level' => 1, 'public_helper_search' => 1],
            ['id' => 2, 'name' => 'Hamburg', 'date' => '2026-12-01', 'season' => 1, 'regional_partner' => 43, 'level' => 1, 'public_helper_search' => 0],
            ['id' => 3, 'name' => 'QPlan Event', 'date' => '2026-11-20', 'season' => 1, 'regional_partner' => 99, 'level' => 1, 'public_helper_search' => 0],
            ['id' => 4, 'name' => 'Old', 'date' => '2025-11-01', 'season' => 2, 'regional_partner' => 42, 'level' => 1, 'public_helper_search' => 0],
        ]);
        DB::table('plan')->insert([
            ['id' => 10, 'event' => 1, 'name' => 'Plan 1'],
        ]);
        DB::table('event_program')->insert([
            ['event' => 1, 'first_program' => 2],
            ['event' => 1, 'first_program' => 3],
            ['event' => 2, 'first_program' => 2],
        ]);
        DB::table('team')->insert([
            ['id' => 1, 'name' => 'A', 'event' => 1, 'first_program' => 2, 'team_number_hot' => 1],
            ['id' => 2, 'name' => 'B', 'event' => 1, 'first_program' => 2, 'team_number_hot' => 2],
        ]);
        DB::table('team_plan')->insert([
            ['team' => 1, 'plan' => 10, 'team_number_plan' => 1, 'room' => 1],
            ['team' => 2, 'plan' => 10, 'team_number_plan' => 2, 'room' => 1],
        ]);
        DB::table('event_volunteer_roster')->insert([
            ['event' => 1, 'volunteer_person' => 1],
            ['event' => 1, 'volunteer_person' => 2],
            ['event' => 1, 'volunteer_person' => 3],
        ]);
        DB::table('m_parameter')->insert([
            ['id' => 1, 'name' => 'e_teams', 'context' => 'input', 'value' => '2'],
            ['id' => 2, 'name' => 'c_teams', 'context' => 'input', 'value' => '0'],
            ['id' => 3, 'name' => 'f8_teams', 'context' => 'input', 'value' => '0'],
            ['id' => 4, 'name' => 'c_duration_opening', 'context' => 'input', 'value' => '10'],
            ['id' => 5, 'name' => 'c_lanes', 'context' => 'expert', 'value' => '4'],
        ]);
        DB::table('plan_param_value')->insert([
            ['plan' => 10, 'parameter' => 1, 'set_value' => '5'],
            ['plan' => 10, 'parameter' => 2, 'set_value' => '0'],
            ['plan' => 10, 'parameter' => 3, 'set_value' => '0'],
            ['plan' => 10, 'parameter' => 4, 'set_value' => '20'],
            ['plan' => 10, 'parameter' => 5, 'set_value' => '8'],
        ]);
        DB::table('s_generator')->insert([
            ['plan' => 10, 'start' => '2026-09-01 10:00:00', 'end' => '2026-09-01 10:05:00'],
            ['plan' => 10, 'start' => '2026-09-02 10:00:00', 'end' => '2026-09-02 10:08:00'],
        ]);
        DB::table('s_one_link_access')->insert([
            ['event' => 1, 'access_date' => '2026-08-01'],
            ['event' => 1, 'access_date' => '2026-08-02'],
            ['event' => 1, 'access_date' => '2026-08-03'],
        ]);
        DB::table('extra_block')->insert([
            ['plan' => 10, 'name' => 'Free 1', 'active' => 1, 'type' => 'free', 'start' => '2026-11-14 09:00:00', 'room' => null],
            ['plan' => 10, 'name' => 'Free 2', 'active' => 1, 'type' => 'free', 'start' => '2026-11-14 10:00:00', 'room' => null],
            ['plan' => 10, 'name' => 'Slot', 'active' => 1, 'type' => 'slot', 'start' => '2026-11-14 11:00:00', 'room' => null],
            ['plan' => 10, 'name' => 'Inactive', 'active' => 0, 'type' => 'free', 'start' => '2026-11-14 12:00:00', 'room' => null],
        ]);
        DB::table('publication')->insert([
            ['event' => 1, 'level' => 3, 'last_change' => '2026-08-01 00:00:00'],
            ['event' => 1, 'level' => 4, 'last_change' => '2026-08-10 00:00:00'],
        ]);
        DB::table('room')->insert([
            ['id' => 1, 'event' => 1, 'name' => 'Aula'],
        ]);
    }

    private function createSchema(): void
    {
        $this->table('m_season', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('year');
        });
        $this->table('regional_partner', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        $this->table('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('display_name')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
        });
        $this->table('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->date('date')->nullable();
            $table->unsignedInteger('season')->nullable();
            $table->unsignedInteger('regional_partner')->nullable();
            $table->unsignedInteger('level')->nullable();
            $table->boolean('public_helper_search')->default(0);
        });
        $this->table('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->string('name')->nullable();
        });
        $this->table('event_program', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program')->nullable();
        });
        $this->table('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->integer('team_number_hot')->nullable();
        });
        $this->table('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('team');
            $table->unsignedInteger('plan');
            $table->unsignedInteger('team_number_plan')->nullable();
            $table->unsignedInteger('room')->nullable();
        });
        $this->table('event_volunteer_roster', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->unsignedInteger('volunteer_person');
        });
        $this->table('m_parameter', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('context')->nullable();
            $table->string('value')->nullable();
        });
        $this->table('plan_param_value', function (Blueprint $table) {
            $table->unsignedInteger('plan');
            $table->unsignedInteger('parameter');
            $table->string('set_value')->nullable();
        });
        $this->table('s_generator', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('plan');
            $table->dateTime('start')->nullable();
            $table->dateTime('end')->nullable();
        });
        $this->table('s_one_link_access', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->date('access_date');
        });
        $this->table('extra_block', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('plan');
            $table->string('name')->nullable();
            $table->boolean('active')->default(1);
            $table->string('type')->nullable();
            $table->dateTime('start')->nullable();
            $table->unsignedInteger('room')->nullable();
            $table->unsignedInteger('first_program')->nullable();
        });
        $this->table('publication', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->unsignedInteger('level')->nullable();
            $table->dateTime('last_change')->nullable();
        });
        $this->table('room', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->string('name');
        });
        $this->table('activity_group', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('plan');
        });
        $this->table('activity', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('activity_group')->nullable();
            $table->unsignedInteger('room_type')->nullable();
            $table->unsignedInteger('extra_block')->nullable();
        });
        $this->table('m_room_type_group', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('sequence')->nullable();
        });
        $this->table('m_room_type', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->unsignedInteger('room_type_group')->nullable();
            $table->unsignedInteger('first_program')->nullable();
        });
        $this->table('room_type_room', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->unsignedInteger('room_type');
        });
        $this->table('event_staffing_role', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event');
            $table->unsignedInteger('m_role')->nullable();
            $table->string('label')->nullable();
            $table->string('group_label')->nullable();
            $table->unsignedSmallInteger('min')->default(0);
            $table->unsignedSmallInteger('best')->default(0);
            $table->boolean('surplus')->default(false);
            $table->unsignedSmallInteger('sequence')->default(0);
        });
        $this->table('event_staffing_group', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event_staffing_role');
            $table->boolean('surplus')->default(false);
        });
        $this->table('event_staffing_assignment', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event_staffing_role')->nullable();
            $table->unsignedInteger('event_staffing_group')->nullable();
            $table->unsignedInteger('volunteer_person')->nullable();
        });
        $this->table('m_role', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('first_program')->nullable();
        });
    }

    private function table(string $name, callable $callback): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $callback);
        } else {
            DB::table($name)->delete();
        }
    }
}
