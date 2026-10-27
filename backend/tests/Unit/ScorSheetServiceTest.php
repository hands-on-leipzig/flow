<?php

namespace Tests\Unit;

use App\Enums\FirstProgram;
use App\Models\User;
use App\Scor\ScorFormStore;
use App\Scor\ScorSheetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScorSheetServiceTest extends TestCase
{
    private ScorSheetService $sheets;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Scor tests require sqlite.');
        }

        $this->createSchema();
        $this->seedEvent();
        $this->sheets = app(ScorSheetService::class);
    }

    public function test_overview_lists_future_matches_for_the_event(): void
    {
        $overview = $this->sheets->overview($this->user(), 1);

        $this->assertCount(1, $overview['matches']);
        $this->assertSame('Runde 1', $overview['matches'][0]['round_label']);
        $this->assertSame('Alpha', $overview['matches'][0]['team_1']['name']);
        $this->assertSame('Beta', $overview['matches'][0]['team_2']['name']);
        $this->assertSame('open', $overview['matches'][0]['team_1']['referee_status']);
        $this->assertCount(2, $overview['teams']);
    }

    public function test_bioglow_referee_sheet_scores_the_sample(): void
    {
        $sheet = $this->sheets->refereeSheet($this->user(), 1, 1, 1);
        $youngForest = $sheet['sections'][0]['items'][0]['id'];
        $bonus = $sheet['sections'][0]['items'][3]['id'];
        $counter = $sheet['sections'][2]['items'][0]['id'];
        $penalty = $sheet['sections'][6]['items'][0]['id'];

        $saved = $this->sheets->saveReferee($this->user(), 1, 1, 1, [
            'values' => [
                $youngForest => 3,
                $bonus => 'yes',
                $counter => 10,
                $penalty => 1,
            ],
        ]);

        $this->assertSame(140, $saved['points']);
        $this->assertSame('01 Lebensgemeinschaften', $sheet['sections'][0]['title']);
        $this->assertSame('Freundliches Miteinander', $sheet['sections'][7]['title']);
    }

    public function test_referee_points_and_lock_after_both_confirmations(): void
    {
        $formId = app(ScorFormStore::class)->formId(1, 'referee');
        app(ScorFormStore::class)->replace($formId, [[
            'title' => 'Mission',
            'kind' => 'mission',
            'note' => '',
            'items' => [
                [
                    'item_type' => 'yes_no',
                    'label' => 'Geschafft',
                    'config' => ['yes_label' => 'Ja', 'yes_points' => 10, 'no_label' => 'Nein', 'no_points' => 0],
                ],
                [
                    'item_type' => 'count',
                    'label' => 'Token',
                    'config' => ['max' => 4, 'points_each' => 5],
                ],
            ],
        ]]);

        $sheet = $this->sheets->refereeSheet($this->user(), 1, 1, 1);
        $yes = $sheet['sections'][0]['items'][0]['id'];
        $count = $sheet['sections'][0]['items'][1]['id'];

        $saved = $this->sheets->saveReferee($this->user(), 1, 1, 1, [
            'values' => [$yes => 'yes', $count => 2],
        ]);
        $this->assertSame(20, $saved['points']);
        $this->assertFalse($saved['locked']);

        $once = $this->sheets->confirmReferee($this->user(), 1, 1, 1, 'referee');
        $this->assertTrue($once['confirmed_referee']);
        $this->assertFalse($once['locked']);

        $both = $this->sheets->confirmReferee($this->user(), 1, 1, 1, 'team');
        $this->assertTrue($both['locked']);

        $this->expectException(\App\Scor\ScorAccessException::class);
        $this->sheets->saveReferee($this->user(), 1, 1, 1, [
            'values' => [$yes => 'no', $count => 0],
        ]);
    }

    public function test_jury_sums_rubric_and_requires_feedback_to_finish(): void
    {
        $sheet = $this->sheets->jurySheet($this->user(), 1, 1);
        $research = $sheet['sections'][0];
        $first = $research['items'][0];
        $second = $research['items'][1];
        $feedback = $sheet['sections'][2]['items'];

        $values = [];
        $comments = [];
        foreach ($sheet['sections'] as $section) {
            if ($section['kind'] !== 'rubric') {
                continue;
            }
            foreach ($section['items'] as $item) {
                $values[$item['id']] = 2;
            }
        }
        $values[$first['id']] = 4;
        $comments[$first['id']] = 'Das Team hat die Erwartung klar übertroffen.';

        $this->expectException(\App\Scor\ScorAccessException::class);
        $this->sheets->saveJury($this->user(), 1, 1, [
            'values' => $values,
            'comments' => $comments,
            'texts' => [],
        ]);
        $this->sheets->completeJury($this->user(), 1, 1);
    }

    public function test_jury_complete_stores_points_and_core_values(): void
    {
        $sheet = $this->sheets->jurySheet($this->user(), 1, 1);
        $values = [];
        $core = 0;
        $total = 0;
        foreach ($sheet['sections'] as $section) {
            if ($section['kind'] !== 'rubric') {
                continue;
            }
            foreach ($section['items'] as $item) {
                $values[$item['id']] = 2;
                $total += 2;
                if ($item['core_value']) {
                    $core += 2;
                }
            }
        }
        $texts = [];
        foreach ($sheet['sections'][2]['items'] as $item) {
            $texts[$item['id']] = 'Konstruktives Feedback für das Team.';
        }

        $this->sheets->saveJury($this->user(), 1, 1, [
            'values' => $values,
            'comments' => [],
            'texts' => $texts,
        ]);
        $done = $this->sheets->completeJury($this->user(), 1, 1);

        $this->assertTrue($done['completed']);
        $this->assertSame($total, $done['points']);
        $this->assertSame($core, $done['core_points']);
        $this->assertGreaterThan(0, $core);
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 1;

        return $user;
    }

    private function seedEvent(): void
    {
        DB::table('m_season')->insert(['id' => 1, 'name' => '2026', 'year' => 2026]);
        DB::table('event')->insert([
            'id' => 1,
            'name' => 'Future Cup',
            'season' => 1,
            'regional_partner' => 1,
        ]);
        DB::table('event_program')->insert(['event' => 1, 'first_program' => FirstProgram::FUTURE_8->value]);
        DB::table('plan')->insert(['id' => 1, 'event' => 1]);
        DB::table('team')->insert([
            ['id' => 1, 'name' => 'Alpha', 'event' => 1, 'first_program' => FirstProgram::FUTURE_8->value, 'team_number_hot' => 101],
            ['id' => 2, 'name' => 'Beta', 'event' => 1, 'first_program' => FirstProgram::FUTURE_8->value, 'team_number_hot' => 102],
        ]);
        DB::table('team_plan')->insert([
            ['team' => 1, 'plan' => 1, 'team_number_plan' => 1],
            ['team' => 2, 'plan' => 1, 'team_number_plan' => 2],
        ]);
        DB::table('match')->insert([
            'id' => 1,
            'plan' => 1,
            'first_program' => FirstProgram::FUTURE_8->value,
            'round' => 1,
            'match_no' => 1,
            'table_1' => 1,
            'table_2' => 2,
            'table_1_team' => 1,
            'table_2_team' => 2,
        ]);
        DB::table('event_user_access')->insert([
            'user' => 1,
            'event' => 1,
            'can_flow' => false,
            'can_scor' => true,
            'can_scor_referee' => true,
            'can_scor_jury' => true,
            'can_scor_admin' => true,
        ]);
    }

    private function createSchema(): void
    {
        foreach (['scor_sheet', 'scor_item', 'scor_section', 'scor_form', 'match', 'team_plan', 'team', 'plan', 'event_program', 'event_user_access', 'user_regional_partner', 'event', 'm_season'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('m_season', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedInteger('year')->nullable();
        });
        Schema::create('event', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->date('date')->nullable();
            $table->unsignedInteger('season')->nullable();
            $table->unsignedInteger('regional_partner')->nullable();
        });
        Schema::create('event_program', function (Blueprint $table) {
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
        });
        Schema::create('user_regional_partner', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('user');
            $table->unsignedInteger('regional_partner');
            $table->string('source', 16)->default('draht');
            $table->boolean('can_scor')->default(false);
            $table->boolean('can_scor_referee')->default(false);
            $table->boolean('can_scor_jury')->default(false);
            $table->boolean('can_scor_admin')->default(false);
        });
        Schema::create('event_user_access', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('user');
            $table->unsignedInteger('event');
            $table->boolean('can_flow')->default(false);
            $table->boolean('can_scor')->default(false);
            $table->boolean('can_scor_referee')->default(false);
            $table->boolean('can_scor_jury')->default(false);
            $table->boolean('can_scor_admin')->default(false);
        });
        Schema::create('plan', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
        });
        Schema::create('team', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('event');
            $table->unsignedInteger('first_program');
            $table->integer('team_number_hot')->nullable();
        });
        Schema::create('team_plan', function (Blueprint $table) {
            $table->unsignedInteger('team');
            $table->unsignedInteger('plan');
            $table->integer('team_number_plan');
        });
        Schema::create('match', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('plan');
            $table->unsignedInteger('first_program');
            $table->integer('round');
            $table->integer('match_no');
            $table->integer('table_1');
            $table->integer('table_2');
            $table->integer('table_1_team');
            $table->integer('table_2_team');
        });
        Schema::create('scor_form', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('season');
            $table->unsignedInteger('first_program');
            $table->string('kind', 16);
        });
        Schema::create('scor_section', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('form');
            $table->unsignedInteger('sequence')->default(0);
            $table->string('title');
            $table->string('kind', 16);
            $table->text('note')->nullable();
        });
        Schema::create('scor_item', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('section');
            $table->unsignedInteger('sequence')->default(0);
            $table->string('item_type', 16);
            $table->string('label')->nullable();
            $table->text('hint')->nullable();
            $table->boolean('core_value')->default(false);
            $table->json('config')->nullable();
        });
        Schema::create('scor_sheet', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->string('kind', 16);
            $table->unsignedInteger('team');
            $table->unsignedInteger('match')->default(0);
            $table->json('answers')->nullable();
            $table->integer('points')->default(0);
            $table->integer('core_points')->default(0);
            $table->boolean('confirmed_referee')->default(false);
            $table->boolean('confirmed_team')->default(false);
            $table->boolean('completed')->default(false);
            $table->timestamp('updated_at')->nullable();
        });
    }
}
