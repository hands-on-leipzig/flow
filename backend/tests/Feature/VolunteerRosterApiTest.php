<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EventVolunteerCollectController;
use App\Http\Controllers\Api\EventVolunteerFieldController;
use App\Http\Controllers\Api\EventVolunteerRosterController;
use App\Models\Event;
use App\Models\EventVolunteerField;
use App\Models\VolunteerPerson;
use App\Support\VolunteerRosterColumns;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VolunteerRosterApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Volunteer roster API tests require sqlite.');
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

    public function test_roster_store_and_destroy(): void
    {
        $event = Event::query()->findOrFail(1);
        $controller = app(EventVolunteerRosterController::class);

        $store = $controller->store(
            Request::create('/', 'POST', ['volunteer_person' => 10]),
            $event,
        );
        $this->assertSame(201, $store->getStatusCode());
        $this->assertTrue(
            DB::table('event_volunteer_roster')->where('event', 1)->where('volunteer_person', 10)->exists()
        );

        DB::table('event_staffing_role')->insert([
            'id' => 1,
            'event' => 1,
            'm_role' => null,
            'label' => 'Laufhilfe',
            'min' => 1,
            'best' => 1,
            'max' => 2,
            'sequence' => 1,
        ]);
        DB::table('event_staffing_group')->insert([
            'id' => 1,
            'event_staffing_role' => 1,
            'group_index' => 1,
            'surplus' => false,
        ]);
        DB::table('event_staffing_assignment')->insert([
            'event_staffing_group' => 1,
            'volunteer_person' => 10,
            'created_at' => now(),
        ]);

        $destroy = $controller->destroy($event, VolunteerPerson::query()->findOrFail(10));
        $this->assertSame(200, $destroy->getStatusCode());
        $this->assertFalse(
            DB::table('event_volunteer_roster')->where('event', 1)->where('volunteer_person', 10)->exists()
        );
        $this->assertFalse(
            DB::table('event_staffing_assignment')->where('volunteer_person', 10)->exists()
        );
    }

    public function test_update_detail_validates_shirt_pair(): void
    {
        $this->seedRoster();
        $event = Event::query()->findOrFail(1);
        $person = VolunteerPerson::query()->findOrFail(10);
        $controller = app(EventVolunteerRosterController::class);

        $bad = $controller->updateDetail(
            Request::create('/', 'PATCH', ['t_shirt_cut' => 'maenner', 't_shirt_size' => null]),
            $event,
            $person,
        );
        $this->assertSame(422, $bad->getStatusCode());

        $ok = $controller->updateDetail(
            Request::create('/', 'PATCH', [
                't_shirt_cut' => 'maenner',
                't_shirt_size' => 'L',
                'meal' => 'standard',
            ]),
            $event,
            $person,
        );
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('L', $ok->getData(true)['detail']['t_shirt_size']);
    }

    public function test_update_custom_batch_and_unknown_key(): void
    {
        $this->seedRoster();
        DB::table('event_volunteer_field')->insert([
            'id' => 1,
            'event' => 1,
            'field_key' => 'parkplatz',
            'label' => 'Parkplatz',
            'type' => 'text',
            'options' => null,
            'sequence' => 1,
            'public_form' => false,
        ]);

        $event = Event::query()->findOrFail(1);
        $person = VolunteerPerson::query()->findOrFail(10);
        $controller = app(EventVolunteerRosterController::class);

        $unknown = $controller->updateCustom(
            Request::create('/', 'PATCH', ['fields' => ['missing' => 'x']]),
            $event,
            $person,
        );
        $this->assertSame(422, $unknown->getStatusCode());

        $ok = $controller->updateCustom(
            Request::create('/', 'PATCH', ['fields' => ['parkplatz' => 'P1']]),
            $event,
            $person,
        );
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('P1', $ok->getData(true)['custom']['parkplatz']);

        $clear = $controller->updateCustom(
            Request::create('/', 'PATCH', ['fields' => ['parkplatz' => null]]),
            $event,
            $person,
        );
        $this->assertSame(200, $clear->getStatusCode());
        $this->assertNull($clear->getData(true)['custom']['parkplatz']);
        $this->assertFalse(
            DB::table('event_volunteer_field_value')->where('event_volunteer_field', 1)->exists()
        );
    }

    public function test_collect_meal_off_clears_and_omits_column(): void
    {
        $this->seedRoster();
        DB::table('event_volunteer_roster_detail')->insert([
            'event_volunteer_roster' => 100,
            't_shirt_cut' => 'maenner',
            't_shirt_size' => 'M',
            'meal' => 'standard',
            'photo_consent' => null,
            'updated_at' => now(),
        ]);

        $event = Event::query()->findOrFail(1);
        // JSON/body false must be accepted (Laravel has() treats false as missing).
        $request = Request::create('/', 'PATCH');
        $request->merge(['meal' => false]);
        $cleared = app(EventVolunteerCollectController::class)->update($request, $event);
        $this->assertSame(200, $cleared->getStatusCode());
        $this->assertFalse($cleared->getData(true)['collect']['meal']);
        $this->assertGreaterThanOrEqual(1, $cleared->getData(true)['cleared']['meal_cleared']);
        $this->assertNull(
            DB::table('event_volunteer_roster_detail')->where('event_volunteer_roster', 100)->value('meal')
        );

        $columns = VolunteerRosterColumns::tablePayloadForEvent(1);
        $this->assertNull(collect($columns)->firstWhere('key', 'meal'));
        $this->assertNotNull(collect($columns)->firstWhere('key', 't_shirt'));
    }

    public function test_field_public_form_checklist_and_type_immutable(): void
    {
        $event = Event::query()->findOrFail(1);
        $fieldController = app(EventVolunteerFieldController::class);

        $created = $fieldController->store(
            Request::create('/', 'POST', ['label' => 'Interne Notiz', 'type' => 'text']),
            $event,
        );
        $this->assertSame(201, $created->getStatusCode());
        $fieldId = $created->getData(true)['field']['id'];
        $this->assertFalse($created->getData(true)['field']['public_form']);

        $typeChange = $fieldController->update(
            Request::create('/', 'PATCH', ['label' => 'Interne Notiz', 'type' => 'boolean']),
            $event,
            EventVolunteerField::query()->findOrFail($fieldId),
        );
        $this->assertSame(422, $typeChange->getStatusCode());

        $checklist = $fieldController->replacePublicForm(
            Request::create('/', 'PUT', ['field_keys' => ['interne_notiz']]),
            $event,
        );
        $this->assertSame(200, $checklist->getStatusCode());
        $this->assertTrue(
            collect($checklist->getData(true)['fields'])->firstWhere('field_key', 'interne_notiz')['public_form']
        );

        $indexed = $fieldController->index($event);
        $this->assertSame(200, $indexed->getStatusCode());
        $indexPayload = $indexed->getData(true);
        $this->assertTrue($indexPayload['collect']['t_shirt']);
        $this->assertTrue($indexPayload['collect']['meal']);
        $indexedField = collect($indexPayload['fields'])->firstWhere('field_key', 'interne_notiz');
        $this->assertNotNull($indexedField);
        $this->assertSame(0, $indexedField['usage_count']);
        $this->assertTrue($indexedField['public_form']);
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
            'public_volunteer_data_entry' => true,
            'volunteer_collect_t_shirt' => true,
            'collect_meal' => true,
        ]);
        DB::table('volunteer_person')->insert([
            'id' => 10,
            'regional_partner' => 1,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'email' => 'max@example.com',
            'mobile' => null,
            'organization' => null,
            'updated_at' => now(),
        ]);
        DB::table('event_volunteer_meal_option')->insert([
            ['event' => 1, 'value' => 'standard', 'label' => 'Standard', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['event' => 1, 'value' => 'vegetarisch', 'label' => 'Vegetarisch', 'sequence' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function seedRoster(): void
    {
        DB::table('event_volunteer_roster')->insert([
            'id' => 100,
            'event' => 1,
            'volunteer_person' => 10,
            'created_at' => now(),
        ]);
    }

    private function truncateData(): void
    {
        foreach ([
            'event_staffing_assignment',
            'event_staffing_group',
            'event_staffing_role',
            'event_volunteer_field_value',
            'event_volunteer_field',
            'event_volunteer_roster_detail',
            'event_volunteer_roster',
            'event_volunteer_meal_option',
            'volunteer_person',
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
                $table->boolean('public_volunteer_data_entry')->default(false);
                $table->boolean('volunteer_collect_t_shirt')->default(true);
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
        if (! Schema::hasTable('volunteer_person')) {
            Schema::create('volunteer_person', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('regional_partner');
                $table->string('first_name');
                $table->string('last_name');
                $table->string('email');
                $table->string('mobile')->nullable();
                $table->string('organization')->nullable();
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
                $table->string('t_shirt_cut', 20)->nullable();
                $table->string('t_shirt_size', 10)->nullable();
                $table->string('meal', 30)->nullable();
                $table->boolean('photo_consent')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique('event_volunteer_roster');
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
        if (! Schema::hasTable('event_volunteer_field')) {
            Schema::create('event_volunteer_field', function (Blueprint $table) {
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
        if (! Schema::hasTable('event_volunteer_field_value')) {
            Schema::create('event_volunteer_field_value', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event_volunteer_roster');
                $table->unsignedInteger('event_volunteer_field');
                $table->text('value')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('event_staffing_role')) {
            Schema::create('event_staffing_role', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('m_role')->nullable();
                $table->string('label')->nullable();
                $table->unsignedSmallInteger('min')->default(0);
                $table->unsignedSmallInteger('best')->default(0);
                $table->unsignedSmallInteger('max')->default(0);
                $table->unsignedSmallInteger('sequence')->default(0);
            });
        }
        if (! Schema::hasTable('event_staffing_group')) {
            Schema::create('event_staffing_group', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event_staffing_role');
                $table->unsignedSmallInteger('group_index')->default(1);
                $table->boolean('surplus')->default(false);
            });
        }
        if (! Schema::hasTable('event_staffing_assignment')) {
            Schema::create('event_staffing_assignment', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event_staffing_group');
                $table->unsignedInteger('volunteer_person');
                $table->timestamp('created_at')->nullable();
            });
        }
    }
}
