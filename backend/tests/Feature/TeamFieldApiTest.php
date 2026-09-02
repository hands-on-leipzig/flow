<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EventTeamFieldController;
use App\Models\Event;
use App\Models\EventTeamField;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeamFieldApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Team field API tests require sqlite.');
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

    public function test_create_list_and_usage_count(): void
    {
        $event = Event::query()->findOrFail(1);
        $controller = app(EventTeamFieldController::class);

        $created = $controller->store(
            Request::create('/', 'POST', ['label' => 'Anmerkung', 'type' => 'text']),
            $event,
        );
        $this->assertSame(201, $created->getStatusCode());
        $fieldId = $created->getData(true)['field']['id'];

        DB::table('team')->insert(['id' => 1, 'event' => 1, 'first_program' => 1, 'name' => 'Team A', 'team_number_hot' => 1]);
        DB::table('event_team_field_value')->insert([
            'team' => 1,
            'event_team_field' => $fieldId,
            'value' => 'Hinweis',
            'updated_at' => now(),
        ]);

        $index = $controller->index($event);
        $this->assertSame(200, $index->getStatusCode());
        $payload = $index->getData(true);
        $this->assertTrue($payload['collect']['meal']);
        $field = collect($payload['fields'])->firstWhere('field_key', 'anmerkung');
        $this->assertNotNull($field);
        $this->assertSame(1, $field['usage_count']);
    }

    public function test_type_change_rejected_and_delete_cascades(): void
    {
        $event = Event::query()->findOrFail(1);
        $controller = app(EventTeamFieldController::class);

        $created = $controller->store(
            Request::create('/', 'POST', ['label' => 'Flag', 'type' => 'boolean']),
            $event,
        );
        $fieldId = $created->getData(true)['field']['id'];
        $field = EventTeamField::query()->findOrFail($fieldId);

        $typeChange = $controller->update(
            Request::create('/', 'PATCH', ['label' => 'Flag', 'type' => 'text']),
            $event,
            $field,
        );
        $this->assertSame(422, $typeChange->getStatusCode());

        DB::table('team')->insert(['id' => 1, 'event' => 1, 'first_program' => 1, 'name' => 'Team A', 'team_number_hot' => 1]);
        DB::table('event_team_field_value')->insert([
            'team' => 1,
            'event_team_field' => $fieldId,
            'value' => '{"unknown":0,"yes":1,"no":0}',
            'updated_at' => now(),
        ]);

        $destroy = $controller->destroy($event, $field);
        $this->assertSame(200, $destroy->getStatusCode());
        $this->assertFalse(DB::table('event_team_field')->where('id', $fieldId)->exists());
        $this->assertFalse(DB::table('event_team_field_value')->where('event_team_field', $fieldId)->exists());
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
    }

    private function truncateData(): void
    {
        foreach ([
            'event_team_field_value',
            'event_team_field',
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
        if (! Schema::hasTable('team')) {
            Schema::create('team', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->unsignedInteger('event');
                $table->unsignedInteger('first_program')->nullable();
                $table->string('name');
                $table->unsignedInteger('team_number_hot')->nullable();
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
    }
}
