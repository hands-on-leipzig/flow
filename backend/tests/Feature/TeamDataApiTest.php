<?php

namespace Tests\Feature;

use App\Export\Teams\TeamDataSpreadsheetSource;
use App\Http\Controllers\Api\EventTeamDataController;
use App\Http\Controllers\Api\EventVolunteerCollectController;
use App\Models\Event;
use App\Models\EventTeamField;
use App\Models\EventTeamFieldValue;
use App\Models\Team;
use App\Support\TeamDataColumns;
use App\Support\TeamDataCustomFields;
use App\Support\TeamMealCounts;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeamDataApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Team data API tests require sqlite.');
        }

        Carbon::setTestNow('2026-09-02');
        $this->createSchema();
        $this->truncateData();
        $this->seedBase();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_index_and_patch_meals_and_custom(): void
    {
        $event = Event::query()->findOrFail(1);
        $team = Team::query()->findOrFail(1);
        $controller = app(EventTeamDataController::class);

        $field = EventTeamField::create([
            'event' => 1,
            'field_key' => 'flag',
            'label' => 'Flag',
            'type' => 'boolean',
            'options' => null,
            'sequence' => 1,
            'public_form' => false,
        ]);

        $index = $controller->index($event);
        $this->assertSame(200, $index->getStatusCode());
        $indexPayload = $index->getData(true);
        $this->assertNotNull(collect($indexPayload['columns'])->firstWhere('key', 'photo_consent'));
        $this->assertNotNull(collect($indexPayload['columns'])->firstWhere('key', 'meal'));
        $flagColumn = collect($indexPayload['columns'])->firstWhere('key', 'custom:flag');
        $this->assertSame('boolean', $flagColumn['editor']);
        $this->assertCount(1, $indexPayload['teams']);
        $this->assertSame('Test School', $indexPayload['teams'][0]['organization']);

        $patch = $controller->update(
            Request::create('/', 'PATCH', [
                'photo_consent' => ['unknown' => 1, 'yes' => 2, 'no' => 0],
                'meals' => ['standard' => 2, 'vegetarisch' => 1, 'vegan' => 0, 'keine' => 0],
                'custom' => ['flag' => true],
            ]),
            $event,
            $team,
        );
        $this->assertSame(200, $patch->getStatusCode());
        $row = $patch->getData(true);
        $this->assertSame(2, $row['meals']['standard']);
        $this->assertTrue($row['touched']['photo']);
        $this->assertTrue($row['touched']['meal']);
        $this->assertTrue($row['touched']['custom']['flag']);
        $this->assertSame(['unknown' => 1, 'yes' => 2, 'no' => 0], $row['photo_consent']);
        $this->assertTrue($row['custom']['flag']);

        $this->assertSame(3, TeamMealCounts::mapForTeamWithCatalog(1, 1)['standard'] + TeamMealCounts::mapForTeamWithCatalog(1, 1)['vegetarisch']);
        $this->assertDatabaseHas('event_team_field_value', [
            'team' => 1,
            'event_team_field' => $field->id,
        ]);
    }

    public function test_meal_patch_rejected_when_collect_off(): void
    {
        DB::table('event')->where('id', 1)->update(['collect_meal' => false]);
        $event = Event::query()->findOrFail(1);
        $team = Team::query()->findOrFail(1);
        $controller = app(EventTeamDataController::class);

        $patch = $controller->update(
            Request::create('/', 'PATCH', ['meals' => ['standard' => 1, 'vegetarisch' => 0, 'vegan' => 0, 'keine' => 0]]),
            $event,
            $team,
        );
        $this->assertSame(422, $patch->getStatusCode());
        $this->assertNull(collect(TeamDataColumns::tablePayloadForEvent(1))->firstWhere('key', 'meal'));
    }

    public function test_collect_meal_off_clears_team_meal_rows(): void
    {
        TeamMealCounts::replaceForTeam(1, 1, [
            'standard' => 2,
            'vegetarisch' => 0,
            'vegan' => 0,
            'keine' => 0,
        ]);

        $event = Event::query()->findOrFail(1);
        $request = Request::create('/', 'PATCH');
        $request->merge(['meal' => false]);
        $response = app(EventVolunteerCollectController::class)->update($request, $event);
        $cleared = $response->getData(true)['cleared'];
        $this->assertGreaterThanOrEqual(1, $cleared['team_meal_rows_cleared']);
        $this->assertFalse(DB::table('event_team_meal_count')->where('team', 1)->exists());
    }

    public function test_export_includes_personen_and_meal_subcolumns(): void
    {
        TeamMealCounts::replaceForTeam(1, 1, [
            'standard' => 1,
            'vegetarisch' => 0,
            'vegan' => 0,
            'keine' => 0,
        ]);

        $event = Event::query()->findOrFail(1);
        $document = (new TeamDataSpreadsheetSource($event))->document();
        $sheet = $document->sheets[0];
        $labels = array_map(fn ($column) => $column->label, $sheet->columns);

        $this->assertContains('Personen', $labels);
        $this->assertContains('Organisation', $labels);
        $this->assertContains('Foto Erlaubnis: Ja', $labels);
        $this->assertContains('Essen: Standard', $labels);

        $rows = iterator_to_array($sheet->rows);
        $this->assertCount(1, $rows);
        $standardIdx = array_search('Essen: Standard', $labels, true);
        $this->assertNotFalse($standardIdx);
        $this->assertSame(1, $rows[0][$standardIdx]);
    }

    public function test_export_respects_team_ids_filter(): void
    {
        DB::table('team')->insert([
            'id' => 2,
            'event' => 1,
            'first_program' => 1,
            'name' => 'Team B',
            'team_number_hot' => 20,
            'organization' => 'Other School',
        ]);

        $event = Event::query()->findOrFail(1);
        $all = (new TeamDataSpreadsheetSource($event))->document();
        $this->assertCount(2, iterator_to_array($all->sheets[0]->rows));

        $filtered = (new TeamDataSpreadsheetSource($event, [1]))->document();
        $rows = iterator_to_array($filtered->sheets[0]->rows);
        $this->assertCount(1, $rows);
        $labels = array_map(fn ($column) => $column->label, $filtered->sheets[0]->columns);
        $nameIdx = array_search('Teamname', $labels, true);
        $this->assertSame('Team A', $rows[0][$nameIdx]);

        $none = (new TeamDataSpreadsheetSource($event, []))->document();
        $this->assertCount(0, iterator_to_array($none->sheets[0]->rows));
    }

    public function test_boolean_and_select_validation(): void
    {
        $booleanField = new EventTeamField([
            'type' => 'boolean',
            'field_key' => 'b',
            'label' => 'B',
        ]);
        $badBoolean = TeamDataCustomFields::validateValue($booleanField, ['yes' => 1]);
        $this->assertFalse($badBoolean['ok']);

        $goodBoolean = TeamDataCustomFields::validateValue($booleanField, true);
        $this->assertTrue($goodBoolean['ok']);
        $this->assertSame('1', $goodBoolean['stored']);
        $this->assertTrue($goodBoolean['api']);

        $selectField = new EventTeamField([
            'type' => 'select',
            'field_key' => 's',
            'label' => 'S',
            'options' => [['value' => 'a', 'label' => 'A']],
        ]);
        $goodSelect = TeamDataCustomFields::validateValue($selectField, 'a');
        $this->assertTrue($goodSelect['ok']);
        $this->assertSame('a', $goodSelect['stored']);
        $this->assertSame('a', $goodSelect['api']);
    }

    public function test_export_includes_scalar_custom_boolean(): void
    {
        $field = EventTeamField::create([
            'event' => 1,
            'field_key' => 'ankunft',
            'label' => 'Ankunft',
            'type' => 'boolean',
            'options' => null,
            'sequence' => 1,
            'public_form' => false,
        ]);

        EventTeamFieldValue::query()->create([
            'team' => 1,
            'event_team_field' => $field->id,
            'value' => '1',
            'updated_at' => now(),
        ]);

        $event = Event::query()->findOrFail(1);
        $document = (new TeamDataSpreadsheetSource($event))->document();
        $labels = array_map(fn ($column) => $column->label, $document->sheets[0]->columns);

        $this->assertContains('Ankunft', $labels);
        $this->assertNotContains('Ankunft: Ja', $labels);

        $rows = iterator_to_array($document->sheets[0]->rows);
        $idx = array_search('Ankunft', $labels, true);
        $this->assertSame('Ja', $rows[0][$idx]);
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
            'date' => '2026-09-02',
            'days' => 1,
            'collect_meal' => true,
        ]);
        DB::table('m_first_program')->insert([
            'id' => 1,
            'name' => 'EXPLORE',
            'sequence' => 1,
        ]);
        DB::table('event_program')->insert([
            'event' => 1,
            'first_program' => 1,
        ]);
        DB::table('team')->insert([
            'id' => 1,
            'event' => 1,
            'first_program' => 1,
            'name' => 'Team A',
            'team_number_hot' => 10,
            'organization' => 'Test School',
        ]);
        foreach ([
            ['standard', 'Standard', 1],
            ['vegetarisch', 'Vegetarisch', 2],
            ['vegan', 'Vegan', 3],
            ['keine', 'Keine', 4],
        ] as [$value, $label, $sequence]) {
            DB::table('event_volunteer_meal_option')->insert([
                'event' => 1,
                'value' => $value,
                'label' => $label,
                'sequence' => $sequence,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function truncateData(): void
    {
        foreach ([
            'event_team_photo_count',
            'event_team_meal_count',
            'event_team_field_value',
            'event_team_field',
            'event_volunteer_meal_option',
            'event_program',
            'm_first_program',
            'team',
            'event',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('event')) {
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
        }
        if (! Schema::hasTable('m_first_program')) {
            Schema::create('m_first_program', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->string('name')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
            });
        }
        if (! Schema::hasTable('event_program')) {
            Schema::create('event_program', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program')->nullable();
            });
        }
        if (! Schema::hasTable('team')) {
            Schema::create('team', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program')->nullable();
                $table->string('name');
                $table->unsignedInteger('team_number_hot')->nullable();
                $table->string('organization')->nullable();
            });
        }
        if (! Schema::hasTable('event_volunteer_meal_option')) {
            Schema::create('event_volunteer_meal_option', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->string('value', 64);
                $table->string('label', 120);
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('event_team_field')) {
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
        }
        if (! Schema::hasTable('event_team_field_value')) {
            Schema::create('event_team_field_value', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('team');
                $table->unsignedInteger('event_team_field');
                $table->text('value');
                $table->timestamp('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('event_team_meal_count')) {
            Schema::create('event_team_meal_count', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('team');
                $table->string('meal_value', 64);
                $table->unsignedInteger('count')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('event_team_photo_count')) {
            Schema::create('event_team_photo_count', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('team');
                $table->string('bucket', 16);
                $table->unsignedInteger('count')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('event_volunteer_roster')) {
            Schema::create('event_volunteer_roster', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('volunteer_person');
                $table->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasTable('event_volunteer_roster_detail')) {
            Schema::create('event_volunteer_roster_detail', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event_volunteer_roster');
                $table->string('t_shirt_cut')->nullable();
                $table->string('t_shirt_size')->nullable();
                $table->string('meal')->nullable();
                $table->boolean('photo_consent')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }
}
