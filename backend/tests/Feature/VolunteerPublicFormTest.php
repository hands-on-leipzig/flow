<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PublishController;
use App\Http\Controllers\Api\VolunteerPublicFormController;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VolunteerPublicFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Volunteer public form tests require sqlite.');
        }

        Carbon::setTestNow('2026-09-01');
        $this->createSchema();
        $this->truncateData();
        $this->seedSeason();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_publish_volunteer_data_entry_get_and_post(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => false]);
        $controller = app(PublishController::class);

        $get = $controller->getPublicVolunteerDataEntry(1);
        $this->assertSame(200, $get->getStatusCode());
        $this->assertFalse($get->getData(true)['public_volunteer_data_entry']);

        $post = $controller->setPublicVolunteerDataEntry(1, Request::create('/', 'POST', [
            'public_volunteer_data_entry' => true,
        ]));
        $this->assertSame(200, $post->getStatusCode());
        $this->assertTrue($post->getData(true)['public_volunteer_data_entry']);
    }

    public function test_schedule_information_includes_volunteer_data_entry_when_enabled_and_level_below_four(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => true]);
        DB::table('publication')->insert([
            'event' => 1,
            'level' => 1,
            'last_change' => now(),
        ]);

        $this->mock(\App\Http\Controllers\Api\DrahtController::class, function ($mock) {
            $mock->shouldReceive('show')->andReturn(response()->json([
                'address' => 'Test',
                'contact' => [],
                'programs' => [],
            ]));
        });

        $response = $this->getJson('/api/publish/public-information/1');

        $response->assertOk();
        $response->assertJsonPath('volunteer_data_entry.enabled', true);
    }

    public function test_schedule_information_omits_volunteer_data_entry_when_disabled(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => false]);
        DB::table('publication')->insert([
            'event' => 1,
            'level' => 1,
            'last_change' => now(),
        ]);

        $this->mock(\App\Http\Controllers\Api\DrahtController::class, function ($mock) {
            $mock->shouldReceive('show')->andReturn(response()->json([
                'address' => 'Test',
                'contact' => [],
                'programs' => [],
            ]));
        });

        $response = $this->getJson('/api/publish/public-information/1');

        $response->assertOk();
        $this->assertArrayNotHasKey('volunteer_data_entry', $response->json());
    }

    public function test_lookup_returns_404_when_email_not_on_roster(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => true]);
        $controller = app(VolunteerPublicFormController::class);

        try {
            $controller->lookup(
                Request::create('/api/public-volunteer-form/test-event/lookup', 'GET', ['email' => 'nobody@example.com']),
                'test-event'
            );
            $this->fail('Expected not found exception.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_lookup_returns_roster_payload_for_member(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => true]);
        DB::table('volunteer_person')->insert([
            'id' => 10,
            'regional_partner' => 1,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'email' => 'max@example.com',
            'mobile' => '+491234',
            'organization' => null,
            'updated_at' => now(),
        ]);
        DB::table('event_volunteer_roster')->insert([
            'id' => 100,
            'event' => 1,
            'volunteer_person' => 10,
            'created_at' => now(),
        ]);
        DB::table('event_volunteer_roster_detail')->insert([
            'event_volunteer_roster' => 100,
            't_shirt_cut' => 'maenner',
            't_shirt_size' => 'L',
            'meal' => 'standard',
            'photo_consent' => null,
            'notes' => 'Hinweis',
            'updated_at' => now(),
        ]);

        $controller = app(VolunteerPublicFormController::class);
        $response = $controller->lookup(
            Request::create('/api/public-volunteer-form/test-event/lookup', 'GET', ['email' => 'max@example.com']),
            'test-event'
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('Max', $payload['person']['first_name']);
        $this->assertSame('standard', $payload['detail']['meal']);
        $this->assertNull($payload['detail']['photo_consent']);
        $this->assertSame('Hinweis', $payload['detail']['notes']);
        $this->assertNotEmpty($payload['meal_options']);
        $this->assertNotEmpty($payload['fields']);
    }

    public function test_save_persists_person_detail_and_custom(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => true]);
        $this->seedRosterMember(['photo_consent' => null]);
        DB::table('event_volunteer_field')->insert([
            'id' => 1,
            'event' => 1,
            'field_key' => 'vegan',
            'label' => 'Vegan',
            'type' => 'boolean',
            'options' => null,
            'sequence' => 1,
        ]);

        $controller = app(VolunteerPublicFormController::class);
        $response = $controller->save(
            Request::create('/api/public-volunteer-form/test-event/save', 'POST', [
                'email' => 'max@example.com',
                'person' => [
                    'first_name' => 'Maximilian',
                    'last_name' => 'Muster',
                    'mobile' => '+491234567890',
                ],
                'detail' => [
                    't_shirt_cut' => 'maenner',
                    't_shirt_size' => 'M',
                    'meal' => 'vegetarisch',
                ],
                'custom' => [
                    'vegan' => true,
                ],
            ]),
            'test-event'
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('Maximilian', $payload['person']['first_name']);
        $this->assertSame('vegetarisch', $payload['detail']['meal']);
        $this->assertSame('Hinweis', $payload['detail']['notes']);
        $this->assertTrue($payload['custom']['vegan']);

        $this->assertSame('Maximilian', DB::table('volunteer_person')->where('id', 10)->value('first_name'));
        $this->assertSame('vegetarisch', DB::table('event_volunteer_roster_detail')->where('event_volunteer_roster', 100)->value('meal'));
        $this->assertSame('1', DB::table('event_volunteer_field_value')->where('event_volunteer_roster', 100)->value('value'));
    }

    public function test_save_returns_404_when_email_not_on_roster(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => true]);
        $controller = app(VolunteerPublicFormController::class);

        try {
            $controller->save(
                Request::create('/api/public-volunteer-form/test-event/save', 'POST', [
                    'email' => 'nobody@example.com',
                    'person' => ['first_name' => 'A', 'last_name' => 'B', 'mobile' => null],
                    'detail' => [],
                    'custom' => [],
                ]),
                'test-event'
            );
            $this->fail('Expected not found exception.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_save_returns_404_when_feature_disabled(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => false]);
        $this->seedRosterMember();
        $controller = app(VolunteerPublicFormController::class);

        try {
            $controller->save(
                Request::create('/api/public-volunteer-form/test-event/save', 'POST', [
                    'email' => 'max@example.com',
                    'person' => ['first_name' => 'Max', 'last_name' => 'Muster', 'mobile' => null],
                    'detail' => [],
                    'custom' => [],
                ]),
                'test-event'
            );
            $this->fail('Expected not found exception.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_save_does_not_update_photo_consent_from_request(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => true]);
        $this->seedRosterMember(['photo_consent' => null]);
        $controller = app(VolunteerPublicFormController::class);

        $response = $controller->save(
            Request::create('/api/public-volunteer-form/test-event/save', 'POST', [
                'email' => 'max@example.com',
                'person' => [
                    'first_name' => 'Max',
                    'last_name' => 'Muster',
                    'mobile' => '+491701234567',
                ],
                'detail' => [
                    't_shirt_cut' => 'maenner',
                    't_shirt_size' => 'L',
                    'meal' => 'standard',
                    'photo_consent' => true,
                    'notes' => 'Hinweis',
                ],
                'custom' => [],
            ]),
            'test-event'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($response->getData(true)['detail']['photo_consent']);
        $this->assertNull(
            DB::table('event_volunteer_roster_detail')->where('event_volunteer_roster', 100)->value('photo_consent')
        );
    }

    public function test_save_does_not_update_notes_from_request(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => true]);
        $this->seedRosterMember(['notes' => 'Bestehend']);
        $controller = app(VolunteerPublicFormController::class);

        $response = $controller->save(
            Request::create('/api/public-volunteer-form/test-event/save', 'POST', [
                'email' => 'max@example.com',
                'person' => [
                    'first_name' => 'Max',
                    'last_name' => 'Muster',
                    'mobile' => '+491701234567',
                ],
                'detail' => [
                    't_shirt_cut' => 'maenner',
                    't_shirt_size' => 'L',
                    'meal' => 'standard',
                    'notes' => 'Neue Notiz',
                ],
                'custom' => [],
            ]),
            'test-event'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Bestehend', $response->getData(true)['detail']['notes']);
        $this->assertSame(
            'Bestehend',
            DB::table('event_volunteer_roster_detail')->where('event_volunteer_roster', 100)->value('notes')
        );
    }

    public function test_save_returns_422_for_invalid_meal(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => true]);
        $this->seedRosterMember();
        $controller = app(VolunteerPublicFormController::class);

        $response = $controller->save(
            Request::create('/api/public-volunteer-form/test-event/save', 'POST', [
                'email' => 'max@example.com',
                'person' => [
                    'first_name' => 'Max',
                    'last_name' => 'Muster',
                    'mobile' => '+491701234567',
                ],
                'detail' => [
                    't_shirt_cut' => 'maenner',
                    't_shirt_size' => 'L',
                    'meal' => 'invalid-meal',
                    'notes' => null,
                ],
                'custom' => [],
            ]),
            'test-event'
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_save_returns_422_for_unknown_custom_field(): void
    {
        $this->seedEvent(['public_volunteer_data_entry' => true]);
        $this->seedRosterMember();
        $controller = app(VolunteerPublicFormController::class);

        $response = $controller->save(
            Request::create('/api/public-volunteer-form/test-event/save', 'POST', [
                'email' => 'max@example.com',
                'person' => [
                    'first_name' => 'Max',
                    'last_name' => 'Muster',
                    'mobile' => '+491701234567',
                ],
                'detail' => [
                    't_shirt_cut' => 'maenner',
                    't_shirt_size' => 'L',
                    'meal' => 'standard',
                    'notes' => null,
                ],
                'custom' => [
                    'unknown_field' => 'x',
                ],
            ]),
            'test-event'
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * @param  array<string, mixed>  $detailOverrides
     */
    private function seedRosterMember(array $detailOverrides = []): void
    {
        DB::table('volunteer_person')->insert([
            'id' => 10,
            'regional_partner' => 1,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'email' => 'max@example.com',
            'mobile' => '+491234',
            'organization' => null,
            'updated_at' => now(),
        ]);
        DB::table('event_volunteer_roster')->insert([
            'id' => 100,
            'event' => 1,
            'volunteer_person' => 10,
            'created_at' => now(),
        ]);
        DB::table('event_volunteer_meal_option')->insert([
            ['event' => 1, 'value' => 'standard', 'label' => 'Standard', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['event' => 1, 'value' => 'vegetarisch', 'label' => 'Vegetarisch', 'sequence' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('event_volunteer_roster_detail')->insert(array_merge([
            'event_volunteer_roster' => 100,
            't_shirt_cut' => 'maenner',
            't_shirt_size' => 'L',
            'meal' => 'standard',
            'photo_consent' => null,
            'notes' => 'Hinweis',
            'updated_at' => now(),
        ], $detailOverrides));
    }

    private function truncateData(): void
    {
        DB::table('event_volunteer_field_value')->delete();
        DB::table('event_volunteer_field')->delete();
        DB::table('event_volunteer_roster_detail')->delete();
        DB::table('event_volunteer_roster')->delete();
        DB::table('event_volunteer_meal_option')->delete();
        DB::table('volunteer_person')->delete();
        DB::table('publication')->delete();
        DB::table('event_program')->delete();
        DB::table('event')->delete();
    }

    private function seedSeason(): void
    {
        if (DB::table('m_season')->count() === 0) {
            DB::table('m_season')->insert([
                'id' => 1,
                'year' => 2026,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedEvent(array $overrides = []): void
    {
        DB::table('event')->insert(array_merge([
            'id' => 1,
            'name' => 'Test Event',
            'slug' => 'test-event',
            'regional_partner' => 1,
            'level' => 1,
            'season' => 1,
            'date' => '2026-09-01',
            'days' => 1,
            'link' => 'https://example.com/test-event',
            'public_helper_search' => false,
            'public_volunteer_data_entry' => false,
        ], $overrides));
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
                $table->string('display_name')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->string('color_hex', 6)->nullable();
            });
        }

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
                $table->unsignedInteger('regional_partner')->nullable();
                $table->unsignedTinyInteger('level')->default(1);
                $table->unsignedInteger('season')->nullable();
                $table->date('date')->nullable();
                $table->unsignedTinyInteger('days')->default(1);
                $table->string('link')->nullable();
                $table->boolean('public_helper_search')->default(false);
                $table->boolean('public_volunteer_data_entry')->default(false);
            });
        }

        if (! Schema::hasTable('publication')) {
            Schema::create('publication', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event');
                $table->unsignedTinyInteger('level');
                $table->timestamp('last_change')->nullable();
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
                $table->text('notes')->nullable();
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
    }
}
