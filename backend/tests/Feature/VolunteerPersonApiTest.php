<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\VolunteerPersonController;
use App\Models\Event;
use App\Models\VolunteerPerson;
use App\Services\VolunteerPersonImportService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VolunteerPersonApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Volunteer person API tests require sqlite.');
        }

        Carbon::setTestNow('2026-09-20');
        $this->createSchema();
        $this->truncateData();
        $this->seedBase();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_store_allows_shared_email_and_null_email(): void
    {
        $event = Event::query()->findOrFail(1);
        $controller = app(VolunteerPersonController::class);

        $first = $controller->store(
            Request::create('/', 'POST', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'shared@example.com',
            ]),
            $event,
        );
        $this->assertSame(201, $first->getStatusCode());

        $second = $controller->store(
            Request::create('/', 'POST', [
                'first_name' => 'Charles',
                'last_name' => 'Babbage',
                'email' => 'shared@example.com',
            ]),
            $event,
        );
        $this->assertSame(201, $second->getStatusCode());

        $without = $controller->store(
            Request::create('/', 'POST', [
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
                'email' => '',
            ]),
            $event,
        );
        $this->assertSame(201, $without->getStatusCode());
        $this->assertNull($without->getData(true)['person']['email']);

        $this->assertSame(3, VolunteerPerson::query()->count());
        $this->assertSame(
            2,
            VolunteerPerson::query()->where('email', 'shared@example.com')->count(),
        );
        $this->assertSame(1, VolunteerPerson::query()->whereNull('email')->count());
    }

    public function test_import_creates_shared_and_missing_emails(): void
    {
        $event = Event::query()->findOrFail(1);
        $controller = app(VolunteerPersonController::class);

        $response = $controller->import(
            Request::create('/', 'POST', [
                'dry_run' => false,
                'rows' => [
                    ['first_name' => 'Ada', 'last_name' => 'One', 'email' => 'dup@example.com'],
                    ['first_name' => 'Ada', 'last_name' => 'Two', 'email' => 'dup@example.com'],
                    ['first_name' => 'No', 'last_name' => 'Mail', 'email' => null],
                ],
            ]),
            $event,
            app(VolunteerPersonImportService::class),
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame(3, $payload['created']);
        $this->assertSame(0, $payload['skipped']);
        $this->assertSame(3, VolunteerPerson::query()->count());
        $this->assertSame(2, VolunteerPerson::query()->where('email', 'dup@example.com')->count());
        $this->assertSame(1, VolunteerPerson::query()->whereNull('email')->count());
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
            'date' => '2026-09-20',
            'days' => 1,
        ]);
    }

    private function truncateData(): void
    {
        foreach (['volunteer_person', 'event'] as $table) {
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
                $table->unsignedInteger('draht_id')->nullable();
                $table->string('first_name');
                $table->string('last_name');
                $table->string('email')->nullable();
                $table->string('mobile')->nullable();
                $table->string('organization')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('m_role')) {
            Schema::create('m_role', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name')->nullable();
            });
        }

        if (! Schema::hasTable('m_season')) {
            Schema::create('m_season', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name')->nullable();
                $table->string('year')->nullable();
            });
        }

        if (! Schema::hasTable('event_staffing_role')) {
            Schema::create('event_staffing_role', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedInteger('m_role')->nullable();
                $table->string('label')->nullable();
                $table->string('group_label')->nullable();
                $table->unsignedSmallInteger('min')->default(0);
                $table->unsignedSmallInteger('best')->default(0);
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->boolean('surplus')->default(false);
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
                $table->unsignedInteger('event_staffing_role');
                $table->unsignedInteger('event_staffing_group')->nullable();
                $table->unsignedInteger('volunteer_person');
                $table->timestamp('created_at')->nullable();
            });
        }
    }
}
