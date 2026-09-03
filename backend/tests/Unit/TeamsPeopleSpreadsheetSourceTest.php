<?php

namespace Tests\Unit;

use App\Export\Teams\TeamsPeopleSpreadsheetSource;
use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use App\Support\SpreadsheetExportVariant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TeamsPeopleSpreadsheetSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('TeamsPeopleSpreadsheetSource tests require sqlite.');
        }

        Schema::dropAllTables();

        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 50);
            $table->unsignedInteger('sequence')->default(0);
        });

        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100)->nullable();
            $table->date('date')->nullable();
        });

        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('draht_id')->nullable();
        });

        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->integer('team_number_hot');
            $table->string('name', 100);
            $table->string('organization', 255)->nullable();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_export_uses_flow_organization_over_empty_draht_people_payload(): void
    {
        DB::table('event')->insert(['id' => 1, 'name' => 'Test', 'date' => '2027-02-27']);
        DB::table('m_first_program')->insert([
            'id' => 3,
            'name' => 'Challenge',
            'sequence' => 1,
        ]);
        DB::table('event_program')->insert([
            'id' => 1,
            'event' => 1,
            'first_program' => 3,
            'draht_id' => 665,
        ]);
        DB::table('team')->insert([
            'id' => 10,
            'event' => 1,
            'first_program' => 3,
            'team_number_hot' => 1046,
            'name' => 'GGI',
            'organization' => 'Gymnasium Gross Ilsede',
        ]);

        $draht = Mockery::mock(DrahtController::class);
        $draht->shouldReceive('getPeople')
            ->once()
            ->with(665)
            ->andReturn(new JsonResponse([
                1046 => [
                    'name' => 'GGI',
                    'coaches' => [
                        ['firstname' => 'Anna', 'name' => 'Lehrer', 'email' => 'a@example.com'],
                    ],
                    'players' => [],
                ],
            ]));

        $event = Event::query()->find(1);
        $document = (new TeamsPeopleSpreadsheetSource($event, $draht))->document();
        $rows = $document->sheets[0]->rows;

        $this->assertCount(1, $rows);
        $this->assertSame('Gymnasium Gross Ilsede', $rows[0][10]);
    }

    public function test_email_export_respects_team_numbers_filter(): void
    {
        DB::table('event')->insert(['id' => 1, 'name' => 'Test', 'date' => '2027-02-27']);
        DB::table('m_first_program')->insert([
            'id' => 3,
            'name' => 'Challenge',
            'sequence' => 1,
        ]);
        DB::table('event_program')->insert([
            'id' => 1,
            'event' => 1,
            'first_program' => 3,
            'draht_id' => 665,
        ]);

        DB::table('team')->insert([
            'id' => 10,
            'event' => 1,
            'first_program' => 3,
            'team_number_hot' => 1046,
            'name' => 'Team A',
            'organization' => 'Org A',
        ]);
        DB::table('team')->insert([
            'id' => 11,
            'event' => 1,
            'first_program' => 3,
            'team_number_hot' => 1047,
            'name' => 'Team B',
            'organization' => 'Org B',
        ]);

        $draht = Mockery::mock(DrahtController::class);
        $draht->shouldReceive('getPeople')
            ->once()
            ->with(665)
            ->andReturn(new JsonResponse([
                1046 => [
                    'name' => 'Team A',
                    'coaches' => [
                        ['firstname' => 'Anna', 'name' => 'Lehrer', 'email' => 'a@example.com'],
                    ],
                    'players' => [],
                ],
                1047 => [
                    'name' => 'Team B',
                    'coaches' => [
                        ['firstname' => 'Ben', 'name' => 'Maier', 'email' => 'b@example.com'],
                    ],
                    'players' => [],
                ],
            ]));

        $event = Event::query()->find(1);
        $document = (new TeamsPeopleSpreadsheetSource(
            $event,
            $draht,
            SpreadsheetExportVariant::EMAIL,
            null,
            [1047],
        ))->document();

        $rows = iterator_to_array($document->sheets[0]->rows);
        $this->assertCount(1, $rows);
        // Columns: Vorname, Nachname, E-Mail
        $this->assertSame('b@example.com', $rows[0][2]);
    }
}
