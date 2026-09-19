<?php

namespace Tests\Unit;

use App\Print\RoleSheetAssembler;
use App\Services\PublicPlanService;
use Mockery;
use Tests\TestCase;

class RoleSheetAssemblerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_builds_sections_in_catalog_order_and_splits_extra_blocks(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->with(1)->andReturn([
            'title_short' => 'Challenge Event Test',
            'roles' => [
                $this->role(14, 'Publikum', 3, []),
                $this->role(4, 'Juror:in', 3, [
                    ['value' => 1, 'label' => 'Jury-Gruppe 1', 'parameter' => 'lane', 'noshow' => false],
                ], 'Jury-Gruppe'),
                $this->role(5, 'Team', 3, [
                    ['value' => 1, 'label' => 'Alpha', 'parameter' => 'team', 'noshow' => false],
                    ['value' => 2, 'label' => 'Beta', 'parameter' => 'team', 'noshow' => true],
                ]),
            ],
        ]);

        $publicPlan->shouldReceive('getSchedule')->once()->with(1, Mockery::on(function (array $query): bool {
            return $query === ['expired' => 'yes', 'role' => 4, 'lane' => 1];
        }))->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('09:00:00', '09:15:00', 'punctual', 'j_with_team', 'Raum A'),
                        $this->activity('12:00:00', '13:00:00', 'window', 'c_lunch', 'Mensa', 9, 'free'),
                    ],
                ],
            ],
        ]);

        $publicPlan->shouldReceive('getSchedule')->once()->with(1, Mockery::on(function (array $query): bool {
            return $query === ['expired' => 'yes', 'role' => 5, 'team' => 1];
        }))->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('10:00:00', '10:10:00', 'punctual', 'r_match', 'Halle', table1Team: 1, table2Team: 2),
                    ],
                ],
            ],
        ]);

        $publicPlan->shouldReceive('getSchedule')->once()->with(1, Mockery::on(function (array $query): bool {
            return $query === ['expired' => 'yes', 'role' => 5, 'team' => 2];
        }))->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('11:00:00', '11:10:00', 'info', 'r_match', 'Halle', table1Team: 2, table2Team: 1),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [5, 4, 14]);

        $this->assertSame('Challenge Event Test', $document['title_short']);
        $this->assertSame('Challenge Event Test', $document['title_long']);
        $this->assertCount(3, $document['sections']);
        $this->assertSame('Jury-Gruppe 1', $document['sections'][0]['subject']);
        $this->assertSame('ed1c24', $document['sections'][0]['color_hex']);
        $this->assertFalse($document['sections'][0]['noshow']);
        $this->assertCount(1, $document['sections'][0]['ablauf']);
        $this->assertSame('09:00', $document['sections'][0]['ablauf'][0]['start']);
        $this->assertSame('09:15', $document['sections'][0]['ablauf'][0]['end']);
        $this->assertSame('Raum A', $document['sections'][0]['ablauf'][0]['room']);
        $this->assertArrayHasKey('zusaetzlich', $document['sections'][0]);
        $this->assertCount(1, $document['sections'][0]['zusaetzlich']);
        $this->assertArrayNotHasKey('hinweise', $document['sections'][0]);
        $this->assertArrayNotHasKey('hinweise', $document['sections'][1]);
        $this->assertArrayNotHasKey('hinweise', $document['sections'][2]);

        $this->assertSame('Team: Alpha', $document['sections'][1]['subject']);
        $this->assertSame(['Beta'], $document['sections'][1]['ablauf'][0]['italic']);
        $this->assertArrayNotHasKey('zusaetzlich', $document['sections'][1]);
        $this->assertSame('Team: Beta', $document['sections'][2]['subject']);
        $this->assertTrue($document['sections'][2]['noshow']);
        $this->assertArrayNotHasKey('zusaetzlich', $document['sections'][2]);
    }

    public function test_joint_role_uses_hot_orange_bar(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(2, 'Moderation', null, [
                    ['value' => null, 'label' => '', 'parameter' => null, 'noshow' => false],
                ]),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('09:00:00', '09:15:00', 'punctual', 'g_opening', 'Bühne'),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [2]);

        $this->assertSame('F78B1F', $document['sections'][0]['color_hex']);
        $this->assertSame('Moderation', $document['sections'][0]['subject']);
    }

    public function test_skips_section_when_ablauf_empty(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(4, 'Juror:in', 3, [
                    ['value' => 1, 'label' => 'Jury-Gruppe 1', 'parameter' => 'lane', 'noshow' => false],
                ], 'Jury-Gruppe'),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('12:00:00', '13:00:00', 'window', 'c_lunch', 'Mensa', 9, 'free'),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [4]);

        $this->assertSame([], $document['sections']);
    }

    public function test_omits_null_query_keys(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(2, 'Moderator:in', null, [
                    ['value' => null, 'label' => 'Moderator:in', 'parameter' => null, 'noshow' => false],
                ]),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->with(1, ['expired' => 'yes', 'role' => 2])->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('09:00:00', '09:05:00', 'punctual', 'c_opening', 'Bühne'),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [2]);

        $this->assertCount(1, $document['sections']);
        $this->assertSame('Moderator:in: Moderator:in', $document['sections'][0]['subject']);
    }

    public function test_subject_omits_role_name_when_group_label_is_set(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(3, 'Schiedsrichter:in', 3, [
                    ['value' => 1, 'label' => 'Tisch 1', 'parameter' => 'table', 'noshow' => false],
                ], 'Game Tisch'),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('09:00:00', '09:10:00', 'punctual', 'r_match', 'Halle'),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [3]);

        $this->assertSame('Tisch 1', $document['sections'][0]['subject']);
    }

    public function test_robot_check_subject_prefixes_table_name(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(11, 'Robot-Checker:in', 3, [
                    ['value' => 1, 'label' => 'Tisch 1', 'parameter' => 'table', 'noshow' => false],
                ], 'Robot-Check'),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('09:00:00', '09:05:00', 'punctual', 'r_check', 'Halle', table1Team: 1, table2Team: 2),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [11]);

        $this->assertSame('Robot-Check für Tisch 1', $document['sections'][0]['subject']);
    }

    public function test_slot_blocks_stay_in_ablauf(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(5, 'Team', 3, [
                    ['value' => 1, 'label' => 'Alpha', 'parameter' => 'team', 'noshow' => false],
                ]),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('09:00:00', '09:10:00', 'punctual', 'r_match', 'Halle', table1Team: 1, table2Team: 2),
                        $this->activity('11:00:00', '11:20:00', 'punctual', 'c_slot_block', 'Werkstatt', 4, 'slot'),
                        $this->activity('12:00:00', '13:00:00', 'window', 'c_free_block', 'Hof', 9, 'free'),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [5]);

        $this->assertCount(2, $document['sections'][0]['ablauf']);
        $this->assertSame('09:00', $document['sections'][0]['ablauf'][0]['start']);
        $this->assertSame('11:00', $document['sections'][0]['ablauf'][1]['start']);
        $this->assertCount(1, $document['sections'][0]['zusaetzlich']);
        $this->assertSame('12:00', $document['sections'][0]['zusaetzlich'][0]['start']);
    }

    public function test_omits_team_match_when_both_sides_unset(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(5, 'Team', 3, [
                    ['value' => 1, 'label' => 'Alpha', 'parameter' => 'team', 'noshow' => false],
                ]),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'group_meta' => ['name' => 'Robot-Game Halbfinale'],
                    'activities' => [
                        $this->activity('09:00:00', '09:10:00', 'punctual', 'c_opening', 'Bühne'),
                        $this->activity('15:00:00', '15:10:00', 'punctual', 'r_match', 'Halle'),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [5]);

        $this->assertCount(1, $document['sections'][0]['ablauf']);
        $this->assertSame('09:00', $document['sections'][0]['ablauf'][0]['start']);
    }

    public function test_collects_unique_room_hints_from_printed_schedule(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(4, 'Juror:in', 3, [
                    ['value' => 1, 'label' => 'Jury-Gruppe 1', 'parameter' => 'lane', 'noshow' => false],
                ], 'Jury-Gruppe'),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('09:00:00', '09:15:00', 'punctual', 'j_with_team', 'Raum A', navigation: '2. Etage'),
                        $this->activity('09:20:00', '09:35:00', 'punctual', 'j_with_team', 'Raum A', navigation: '2. Etage'),
                        $this->activity('10:00:00', '10:15:00', 'punctual', 'j_with_team', 'Bühne', navigation: 'Hauptgebäude'),
                        $this->activity('10:20:00', '10:35:00', 'punctual', 'j_with_team', 'Hof'),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [4]);

        $this->assertSame([
            ['room' => 'Bühne', 'hint' => 'Hauptgebäude'],
            ['room' => 'Raum A', 'hint' => '2. Etage'],
        ], $document['sections'][0]['hinweise']);
    }

    public function test_room_hint_on_free_block_still_appears(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(5, 'Team', 3, [
                    ['value' => 1, 'label' => 'Alpha', 'parameter' => 'team', 'noshow' => false],
                ]),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('09:00:00', '09:10:00', 'punctual', 'r_match', 'Halle', table1Team: 1, table2Team: 2),
                        $this->activity('12:00:00', '13:00:00', 'window', 'c_free_block', 'Hof', 9, 'free', navigation: 'Hinterhof links'),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [5]);

        $this->assertSame([
            ['room' => 'Hof', 'hint' => 'Hinterhof links'],
        ], $document['sections'][0]['hinweise']);
    }

    public function test_omitted_match_room_hint_is_ignored(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(5, 'Team', 3, [
                    ['value' => 1, 'label' => 'Alpha', 'parameter' => 'team', 'noshow' => false],
                ]),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'group_meta' => ['name' => 'Robot-Game Halbfinale'],
                    'activities' => [
                        $this->activity('09:00:00', '09:10:00', 'punctual', 'c_opening', 'Bühne'),
                        $this->activity('15:00:00', '15:10:00', 'punctual', 'r_match', 'Halle', navigation: 'Halle hinten'),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [5]);

        $this->assertCount(1, $document['sections'][0]['ablauf']);
        $this->assertArrayNotHasKey('hinweise', $document['sections'][0]);
    }

    public function test_inaccessible_room_without_navigation_gets_warning(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(4, 'Juror:in', 3, [
                    ['value' => 1, 'label' => 'Jury-Gruppe 1', 'parameter' => 'lane', 'noshow' => false],
                ], 'Jury-Gruppe'),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('09:00:00', '09:15:00', 'punctual', 'j_with_team', 'Raum A', accessible: false),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [4]);

        $this->assertSame([
            ['room' => 'Raum A', 'hint' => 'Nicht barrierefrei'],
        ], $document['sections'][0]['hinweise']);
    }

    public function test_appends_accessibility_warning_after_navigation_hint(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(4, 'Juror:in', 3, [
                    ['value' => 1, 'label' => 'Jury-Gruppe 1', 'parameter' => 'lane', 'noshow' => false],
                ], 'Jury-Gruppe'),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'activities' => [
                        $this->activity('09:00:00', '09:15:00', 'punctual', 'j_with_team', 'Raum A', navigation: '2. Etage', accessible: false),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [4]);

        $this->assertSame([
            ['room' => 'Raum A', 'hint' => "2. Etage\nNicht barrierefrei"],
        ], $document['sections'][0]['hinweise']);
    }

    public function test_omitted_inaccessible_match_room_is_ignored(): void
    {
        $publicPlan = Mockery::mock(PublicPlanService::class);
        $publicPlan->shouldReceive('getRoles')->once()->andReturn([
            'title_short' => 'Event',
            'roles' => [
                $this->role(5, 'Team', 3, [
                    ['value' => 1, 'label' => 'Alpha', 'parameter' => 'team', 'noshow' => false],
                ]),
            ],
        ]);
        $publicPlan->shouldReceive('getSchedule')->once()->andReturn([
            'groups' => [
                [
                    'group_meta' => ['name' => 'Robot-Game Halbfinale'],
                    'activities' => [
                        $this->activity('09:00:00', '09:10:00', 'punctual', 'c_opening', 'Bühne'),
                        $this->activity('15:00:00', '15:10:00', 'punctual', 'r_match', 'Halle', accessible: false),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [5]);

        $this->assertCount(1, $document['sections'][0]['ablauf']);
        $this->assertArrayNotHasKey('hinweise', $document['sections'][0]);
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>
     */
    private function role(int $id, string $name, ?int $firstProgram, array $options, ?string $groupLabel = null): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'first_program' => $firstProgram,
            'group_label' => $groupLabel,
            'differentiation_parameter' => $options[0]['parameter'] ?? null,
            'color_hex' => 'ed1c24',
            'logo_stem' => 'fll_challenge',
            'options' => $options,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activity(
        string $start,
        string $end,
        string $presence,
        string $code,
        string $room,
        ?int $extraBlockId = null,
        ?string $extraBlockType = null,
        ?int $table1Team = null,
        ?int $table2Team = null,
        ?string $navigation = null,
        bool $accessible = true,
    ): array {
        return [
            'start_time' => '2026-03-15 '.$start,
            'end_time' => '2026-03-15 '.$end,
            'presence' => $presence,
            'activity_type_code' => $code,
            'activity_name' => $code === 'r_match' ? 'Robot-Game Match' : '',
            'extra_block_id' => $extraBlockId,
            'extra_block_type' => $extraBlockType,
            'room' => ['room_name' => $room, 'navigation' => $navigation, 'accessible' => $accessible],
            'team_name' => 'Alpha',
            'jury_team_number_hot' => 12,
            'table_1_team' => $table1Team,
            'table_2_team' => $table2Team,
            'table_1_team_name' => $table1Team ? 'Alpha' : null,
            'table_2_team_name' => $table2Team ? 'Beta' : null,
        ];
    }
}
