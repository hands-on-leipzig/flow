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
        $this->assertSame([], $overview['groups']['jury']);
        $this->assertSame([], $overview['groups']['referee']);
    }

    public function test_overview_shows_who_is_assigned_to_jury_and_referee_groups(): void
    {
        Schema::create('m_role', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('first_program')->nullable();
            $table->string('differentiation_parameter')->nullable();
            $table->string('group_label')->nullable();
        });
        Schema::create('m_activity_type_detail', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('code')->nullable();
            $table->unsignedInteger('first_program')->nullable();
        });
        Schema::create('activity_group', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('activity_type_detail');
            $table->unsignedInteger('plan');
        });
        Schema::create('activity', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('activity_group');
            $table->unsignedInteger('activity_type_detail');
            $table->dateTime('start')->nullable();
            $table->integer('table_1')->nullable();
            $table->integer('table_1_team')->nullable();
            $table->integer('table_2')->nullable();
            $table->integer('table_2_team')->nullable();
            $table->unsignedInteger('jury_team')->nullable();
            $table->unsignedTinyInteger('jury_lane')->nullable();
        });
        Schema::create('volunteer_person', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('first_name');
            $table->string('last_name');
        });
        Schema::create('event_staffing_role', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event');
            $table->unsignedInteger('m_role')->nullable();
            $table->string('label')->nullable();
            $table->string('group_label')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('surplus')->default(false);
        });
        Schema::create('event_staffing_group', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event_staffing_role');
            $table->unsignedSmallInteger('group_index');
            $table->boolean('surplus')->default(false);
        });
        Schema::create('event_staffing_assignment', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('event_staffing_group');
            $table->unsignedInteger('volunteer_person');
        });

        DB::table('m_role')->insert([
            ['id' => 22, 'name' => 'Juror:in', 'first_program' => FirstProgram::FUTURE_8->value, 'differentiation_parameter' => 'lane', 'group_label' => 'Jury-Gruppe'],
            ['id' => 23, 'name' => 'Schiedsrichter:in', 'first_program' => FirstProgram::FUTURE_8->value, 'differentiation_parameter' => 'table', 'group_label' => 'Spiel-Matte'],
        ]);
        DB::table('event_staffing_role')->insert([
            ['id' => 1, 'event' => 1, 'm_role' => 22, 'label' => 'Juror:in', 'group_label' => 'Jury-Gruppe', 'sequence' => 11, 'surplus' => false],
            ['id' => 2, 'event' => 1, 'm_role' => 23, 'label' => 'Schiedsrichter:in', 'group_label' => 'Spiel-Matte', 'sequence' => 21, 'surplus' => false],
        ]);
        DB::table('event_staffing_group')->insert([
            ['id' => 1, 'event_staffing_role' => 1, 'group_index' => 1, 'surplus' => false],
            ['id' => 2, 'event_staffing_role' => 1, 'group_index' => 2, 'surplus' => false],
            ['id' => 3, 'event_staffing_role' => 2, 'group_index' => 1, 'surplus' => false],
            ['id' => 4, 'event_staffing_role' => 2, 'group_index' => 2, 'surplus' => false],
        ]);
        DB::table('volunteer_person')->insert([
            ['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Kern'],
            ['id' => 2, 'first_name' => 'Bea', 'last_name' => 'Holm'],
            ['id' => 3, 'first_name' => 'Cem', 'last_name' => 'Roth'],
            ['id' => 4, 'first_name' => 'Dina', 'last_name' => 'Falk'],
        ]);
        DB::table('event_staffing_assignment')->insert([
            ['id' => 1, 'event_staffing_group' => 1, 'volunteer_person' => 1],
            ['id' => 2, 'event_staffing_group' => 2, 'volunteer_person' => 2],
            ['id' => 3, 'event_staffing_group' => 3, 'volunteer_person' => 3],
            ['id' => 4, 'event_staffing_group' => 4, 'volunteer_person' => 4],
        ]);
        DB::table('m_activity_type_detail')->insert([
            'id' => 10,
            'first_program' => FirstProgram::FUTURE_8->value,
        ]);
        DB::table('activity_group')->insert([
            'id' => 1,
            'activity_type_detail' => 10,
            'plan' => 1,
        ]);
        DB::table('activity')->insert([
            ['id' => 1, 'activity_group' => 1, 'activity_type_detail' => 10, 'jury_team' => 1, 'jury_lane' => 2],
            ['id' => 2, 'activity_group' => 1, 'activity_type_detail' => 10, 'jury_team' => 2, 'jury_lane' => 1],
        ]);

        $overview = $this->sheets->overview($this->user(), 1);
        $teams = collect($overview['teams'])->keyBy('id');

        $this->assertSame(2, $teams[1]['jury_lane']);
        $this->assertSame(1, $teams[2]['jury_lane']);
        $this->assertSame('Jury-Gruppe 1', $overview['groups']['jury'][0]['label']);
        $this->assertSame(['Ada Kern'], $overview['groups']['jury'][0]['people']);
        $this->assertSame(['Bea Holm'], $overview['groups']['jury'][1]['people']);
        $this->assertSame('Spiel-Matte 1', $overview['groups']['referee'][0]['label']);
        $this->assertSame(['Cem Roth'], $overview['groups']['referee'][0]['people']);
        $this->assertSame(['Cem Roth'], $overview['matches'][0]['team_1']['staff']);
        $this->assertSame(['Dina Falk'], $overview['matches'][0]['team_2']['staff']);
    }

    public function test_one_trophy_rule_rejects_two_judged_awards_and_keeps_three_with_extras(): void
    {
        $this->createAwardTable();

        try {
            $this->sheets->saveAwards($this->user(), 1, [
                'trophies' => [
                    ['team' => 1, 'category' => 'champion'],
                    ['team' => 1, 'category' => 'research'],
                ],
                'nominations' => [],
            ]);
            $this->fail('Zwei Wertungspokale für ein Team müssen scheitern.');
        } catch (\App\Scor\ScorAccessException $e) {
            $this->assertSame(422, $e->status);
        }

        $saved = $this->sheets->saveAwards($this->user(), 1, [
            'trophies' => [
                ['team' => 1, 'category' => 'champion'],
                ['team' => 1, 'category' => 'robot'],
            ],
            'nominations' => [
                ['team' => 1, 'category' => 'research'],
                ['team' => 2, 'category' => 'research'],
            ],
            'coaching' => 1,
        ]);

        $this->assertCount(2, $saved['awards']['trophies']);
        $this->assertSame(1, $saved['awards']['coaching']);
        $this->assertCount(2, $saved['awards']['nominations']);
    }

    public function test_noteworthy_teams_can_be_sent_on_stage_only_up_to_the_flow_count(): void
    {
        Schema::create('scor_noteworthy', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('team');
            $table->unique(['event', 'team']);
        });
        Schema::create('scor_stage_pick', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedSmallInteger('slot');
            $table->unsignedInteger('team');
        });

        $tagged = $this->sheets->tagNoteworthy($this->user(), 1, 1, true);
        $this->assertSame([1], $tagged['noteworthy']);

        $this->expectException(\App\Scor\ScorAccessException::class);
        $this->expectExceptionMessage('FLOW hat für diese Veranstaltung keine Bühnenplätze.');
        $this->sheets->pickStage($this->user(), 1, [1]);
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

    public function test_future_rules_block_the_queen_bonus_zero_the_third_penalty_and_share_the_retreat(): void
    {
        $sheet = $this->sheets->refereeSheet($this->user(), 1, 1, 1);
        $young = null;
        $bonus = null;
        $penalty = null;
        $retreat = null;
        foreach ($sheet['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['label'] === 'Schlüsselarten im jungen Wald') {
                    $young = $item['id'];
                }
                if ($item['label'] === 'Bonus invasive Königin') {
                    $bonus = $item['id'];
                }
                if ($item['label'] === 'Beeinträchtigungen in diesem Match') {
                    $penalty = $item['id'];
                }
                if ($item['label'] === 'Schlüsselarten oder Ressourcen vollständig im Rückzugsort') {
                    $retreat = $item['id'];
                }
            }
        }

        try {
            $this->sheets->saveReferee($this->user(), 1, 1, 1, [
                'values' => [$young => 2, $bonus => 'yes'],
            ]);
            $this->fail('Der Königinnen-Bonus ohne drei Schlüsselarten muss scheitern.');
        } catch (\App\Scor\ScorAccessException $e) {
            $this->assertSame(422, $e->status);
        }

        $penaltyItem = null;
        foreach ($sheet['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['id'] === $penalty) {
                    $penaltyItem = $item;
                }
            }
        }
        $this->assertSame('Dritte', $penaltyItem['config']['options'][3]['label'] ?? null);

        $zeroed = $this->sheets->saveReferee($this->user(), 1, 1, 1, [
            'values' => [$young => 3, $penalty => 3],
        ]);
        $this->assertSame(0, $zeroed['points']);

        $shared = $this->sheets->saveReferee($this->user(), 1, 1, 1, [
            'values' => [$retreat => 4],
        ]);
        $this->assertSame(20, $shared['points']);
        $opponent = $this->sheets->refereeSheet($this->user(), 1, 1, 2);
        $opponentRetreat = null;
        foreach ($opponent['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['label'] === 'Schlüsselarten oder Ressourcen vollständig im Rückzugsort') {
                    $opponentRetreat = $item['value'];
                }
            }
        }
        $this->assertSame(4, $opponentRetreat);
    }

    public function test_untouched_count_is_zero_and_does_not_block_confirm(): void
    {
        $formId = app(ScorFormStore::class)->formId(1, 'referee');
        app(ScorFormStore::class)->replace($formId, [[
            'title' => 'Baum',
            'kind' => 'mission',
            'note' => '',
            'items' => [[
                'item_type' => 'count',
                'label' => 'Wie viele Dinge sind im Baum',
                'config' => ['max' => 3, 'points_each' => 20],
            ]],
        ]]);

        $saved = $this->sheets->saveReferee($this->user(), 1, 1, 1, ['values' => []]);
        $this->assertSame(0, $saved['points']);

        $confirmed = $this->sheets->confirmReferee($this->user(), 1, 1, 1, 'referee');
        $this->assertTrue($confirmed['confirmed_referee']);
        $this->assertSame(0, $confirmed['points']);
    }

    public function test_no_show_can_stay_off_and_a_missing_row_still_blocks_confirm(): void
    {
        $sheet = $this->sheets->refereeSheet($this->user(), 1, 1, 1);
        $values = [];
        $penaltyId = null;
        foreach ($sheet['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $options = $item['config']['options'] ?? [];
                if ($item['item_type'] === 'choice' && count($options) === 1) {
                    continue;
                }
                if ($item['item_type'] === 'choice') {
                    $penaltyId = $item['id'];
                    $values[$item['id']] = 0;
                    continue;
                }
                if ($item['item_type'] === 'yes_no') {
                    $values[$item['id']] = 'no';
                }
                if ($item['item_type'] === 'count') {
                    $values[$item['id']] = 0;
                }
            }
        }

        $this->sheets->saveReferee($this->user(), 1, 1, 1, ['values' => $values]);
        $confirmed = $this->sheets->confirmReferee($this->user(), 1, 1, 1, 'referee');
        $this->assertTrue($confirmed['confirmed_referee']);

        unset($values[$penaltyId]);
        $this->sheets->saveReferee($this->user(), 1, 1, 1, ['values' => $values]);

        $this->expectException(\App\Scor\ScorAccessException::class);
        $this->expectExceptionMessage('Bitte jede Zeile ausfüllen.');
        $this->sheets->confirmReferee($this->user(), 1, 1, 1, 'team');
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
        foreach ($sheet['sections'] as $section) {
            if ($section['kind'] !== 'feedback') {
                continue;
            }
            foreach ($section['items'] as $item) {
                $texts[$item['id']] = 'Konstruktives Feedback für das Team.';
            }
        }
        $this->assertCount(6, $texts);

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

    public function test_results_follow_the_contao_ranking(): void
    {
        DB::table('team')->insert([
            'id' => 3,
            'name' => 'Gamma',
            'event' => 1,
            'first_program' => FirstProgram::FUTURE_8->value,
            'team_number_hot' => 103,
        ]);
        DB::table('team_plan')->insert(['team' => 3, 'plan' => 1, 'team_number_plan' => 3]);
        DB::table('match')->insert([
            [
                'id' => 2,
                'plan' => 1,
                'first_program' => FirstProgram::FUTURE_8->value,
                'round' => 1,
                'match_no' => 2,
                'table_1' => 1,
                'table_2' => 2,
                'table_1_team' => 3,
                'table_2_team' => 0,
            ],
            [
                'id' => 3,
                'plan' => 1,
                'first_program' => FirstProgram::FUTURE_8->value,
                'round' => 2,
                'match_no' => 1,
                'table_1' => 1,
                'table_2' => 2,
                'table_1_team' => 1,
                'table_2_team' => 2,
            ],
            [
                'id' => 4,
                'plan' => 1,
                'first_program' => FirstProgram::FUTURE_8->value,
                'round' => 0,
                'match_no' => 1,
                'table_1' => 1,
                'table_2' => 2,
                'table_1_team' => 1,
                'table_2_team' => 2,
            ],
        ]);
        DB::table('scor_sheet')->insert([
            $this->refereeRow(1, 1, 10, true),
            $this->refereeRow(1, 3, 30, false),
            $this->refereeRow(1, 4, 99, true),
            $this->refereeRow(2, 1, 30, true),
            $this->refereeRow(3, 2, 20, true),
        ]);

        $formId = app(ScorFormStore::class)->formId(1, 'jury');
        app(ScorFormStore::class)->replace($formId, [
            [
                'title' => 'Forschung',
                'kind' => 'rubric',
                'note' => '',
                'items' => [
                    $this->rubric(false),
                    $this->rubric(true),
                ],
            ],
            [
                'title' => 'Roboterdesign',
                'kind' => 'rubric',
                'note' => '',
                'items' => [
                    $this->rubric(false),
                    $this->rubric(true),
                ],
            ],
        ]);

        $sheet = $this->sheets->jurySheet($this->user(), 1, 1);
        $research = $sheet['sections'][0]['items'];
        $design = $sheet['sections'][1]['items'];
        $this->saveJuryScores(1, $research, $design, 4, 3, 4, 2);
        $this->saveJuryScores(2, $research, $design, 4, 4, 4, 1);
        $this->saveJuryScores(3, $research, $design, 2, 2, 3, 2);

        $results = $this->sheets->results($this->user(), 1);
        $byId = collect($results['teams'])->keyBy('id');

        $this->assertSame([1, 2], array_column($results['rounds'], 'round'));
        $this->assertSame([1, 2, 3], array_column($results['teams'], 'id'));

        $this->assertEquals(20, $byId[1]['robot']);
        $this->assertEquals(15, $byId[2]['robot']);
        $this->assertEquals(10, $byId[3]['robot']);
        $this->assertSame(10, $byId[1]['rounds'][0]['points']);
        $this->assertTrue($byId[1]['rounds'][0]['confirmed']);
        $this->assertSame(30, $byId[1]['rounds'][1]['points']);
        $this->assertFalse($byId[1]['rounds'][1]['confirmed']);
        $this->assertNull($byId[3]['rounds'][1]);

        $this->assertSame(1, $byId[1]['robot_rank']);
        $this->assertSame(2, $byId[2]['robot_rank']);
        $this->assertSame(3, $byId[3]['robot_rank']);

        $this->assertSame(7, $byId[1]['research']);
        $this->assertSame(2, $byId[1]['research_rank']);
        $this->assertSame(8, $byId[2]['research']);
        $this->assertSame(1, $byId[2]['research_rank']);
        $this->assertSame(3, $byId[3]['research_rank']);

        $this->assertSame(6, $byId[1]['robotdesign']);
        $this->assertSame(1, $byId[1]['robotdesign_rank']);
        $this->assertSame(2, $byId[2]['robotdesign_rank']);
        $this->assertSame(2, $byId[3]['robotdesign_rank']);

        $this->assertSame(5, $byId[1]['core_values']);
        $this->assertSame(1, $byId[1]['core_values_rank']);
        $this->assertSame(1, $byId[2]['core_values_rank']);
        $this->assertSame(3, $byId[3]['core_values_rank']);

        $this->assertSame(5, $byId[1]['champion']);
        $this->assertSame(6, $byId[2]['champion']);
        $this->assertSame(10, $byId[3]['champion']);
        $this->assertSame(1, $byId[1]['champion_rank']);
        $this->assertSame(2, $byId[2]['champion_rank']);
        $this->assertSame(3, $byId[3]['champion_rank']);
    }

    private function createAwardTable(): void
    {
        Schema::create('scor_award', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('event');
            $table->unsignedInteger('team')->nullable();
            $table->string('category', 32);
            $table->string('kind', 16);
        });
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 1;

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function refereeRow(int $teamId, int $matchId, int $points, bool $confirmed): array
    {
        return [
            'event' => 1,
            'kind' => 'referee',
            'team' => $teamId,
            'match' => $matchId,
            'points' => $points,
            'core_points' => 0,
            'confirmed_referee' => $confirmed,
            'confirmed_team' => $confirmed,
            'completed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rubric(bool $coreValue): array
    {
        return [
            'item_type' => 'rubric',
            'label' => 'Kriterium',
            'core_value' => $coreValue,
            'config' => ['group' => 'Benennen', 'cells' => ['wenig', 'etwas', 'klar']],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $research
     * @param  list<array<string, mixed>>  $design
     */
    private function saveJuryScores(int $teamId, array $research, array $design, int $a, int $b, int $c, int $d): void
    {
        $this->sheets->saveJury($this->user(), 1, $teamId, [
            'values' => [
                $research[0]['id'] => $a,
                $research[1]['id'] => $b,
                $design[0]['id'] => $c,
                $design[1]['id'] => $d,
            ],
        ]);
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
        foreach (['scor_stage_pick', 'scor_noteworthy', 'scor_award', 'event_staffing_assignment', 'event_staffing_group', 'event_staffing_role', 'volunteer_person', 'activity', 'activity_group', 'm_activity_type_detail', 'm_role', 'scor_sheet', 'scor_item', 'scor_section', 'scor_form', 'match', 'team_plan', 'team', 'plan', 'event_program', 'event_user_access', 'user_regional_partner', 'event', 'm_season'] as $table) {
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
