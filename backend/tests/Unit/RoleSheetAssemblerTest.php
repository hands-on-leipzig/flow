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

    public function test_builds_sections_in_catalog_order_and_splits_presence(): void
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
                        $this->activity('12:00:00', '13:00:00', 'window', 'c_lunch', 'Mensa'),
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
                        $this->activity('10:00:00', '10:10:00', 'punctual', 'r_match', 'Halle'),
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
                        $this->activity('11:00:00', '11:10:00', 'info', 'r_match', 'Halle'),
                    ],
                ],
            ],
        ]);

        $document = (new RoleSheetAssembler($publicPlan))->assemble(1, [5, 4, 14]);

        $this->assertSame('Challenge Event Test', $document['title_short']);
        $this->assertSame('Challenge Event Test', $document['title_long']);
        $this->assertCount(2, $document['sections']);
        $this->assertSame('Jury-Gruppe 1', $document['sections'][0]['subject']);
        $this->assertFalse($document['sections'][0]['noshow']);
        $this->assertCount(1, $document['sections'][0]['ablauf']);
        $this->assertSame('09:00', $document['sections'][0]['ablauf'][0]['start']);
        $this->assertSame('09:15', $document['sections'][0]['ablauf'][0]['end']);
        $this->assertSame('Raum A', $document['sections'][0]['ablauf'][0]['room']);
        $this->assertArrayHasKey('zusaetzlich', $document['sections'][0]);
        $this->assertCount(1, $document['sections'][0]['zusaetzlich']);

        $this->assertSame('Team: Alpha', $document['sections'][1]['subject']);
        $this->assertArrayNotHasKey('zusaetzlich', $document['sections'][1]);
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
                        $this->activity('12:00:00', '13:00:00', 'window', 'c_lunch', 'Mensa'),
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
    private function activity(string $start, string $end, string $presence, string $code, string $room): array
    {
        return [
            'start_time' => '2026-03-15 '.$start,
            'end_time' => '2026-03-15 '.$end,
            'presence' => $presence,
            'activity_type_code' => $code,
            'room' => ['room_name' => $room],
            'team_name' => 'Alpha',
            'jury_team_number_hot' => 12,
        ];
    }
}
