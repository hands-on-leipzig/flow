<?php

namespace Tests\Unit;

use App\Services\DrahtEventPlacementService;
use PHPUnit\Framework\TestCase;

class DrahtEventPlacementServiceTest extends TestCase
{
    private DrahtEventPlacementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DrahtEventPlacementService;
    }

    public function test_split_keeps_both_ids_and_moves_the_program_that_changed_date(): void
    {
        $placement = $this->service->place(1, [
            $this->row(1, 2, 1, '2026-01-01', 'A'),
            $this->row(2, 3, 2, '2026-03-01', 'B'),
            $this->row(3, 8, 3, '2026-03-01', 'C'),
        ], [
            $this->event(10, '2026-01-01', [
                $this->program(1, 2, 1),
                $this->program(2, 3, 2),
            ]),
            $this->event(20, '2026-02-01', [
                $this->program(3, 8, 3),
            ]),
        ]);

        $this->assertTrue($placement->regroup);
        $this->assertSame([1 => 10, 2 => 20, 3 => 20], $placement->targets);
        $this->assertSame([10, 20], $placement->touchedIds);
        $this->assertSame([], $placement->deleteIds);
        $this->assertSame([], $placement->creates);
        $this->assertSame([
            [
                'id' => 20,
                'date' => '2026-03-01',
                'name' => 'C',
                'level' => 1,
                'regional_partner' => 5,
            ],
        ], $placement->updates);
    }

    public function test_unchanged_dates_do_not_regroup(): void
    {
        $placement = $this->service->place(1, [
            $this->row(1, 2, 1, '2026-01-01 00:00:00', 'A'),
            $this->row(2, 3, 2, '2026-01-01', 'B'),
            $this->row(3, 8, 3, '2026-02-01', 'C'),
        ], [
            $this->event(10, '2026-01-01', [
                $this->program(1, 2, 1),
                $this->program(2, 3, 2),
            ]),
            $this->event(20, '2026-02-01', [
                $this->program(3, 8, 3),
            ]),
        ]);

        $this->assertFalse($placement->regroup);
        $this->assertSame([], $placement->targets);
        $this->assertSame([], $placement->touchedIds);
        $this->assertSame([], $placement->deleteIds);
        $this->assertSame([], $placement->creates);
        $this->assertSame([], $placement->updates);
    }

    public function test_program_leaving_an_unchanged_sibling_creates_an_event_only_when_the_date_is_empty(): void
    {
        $placement = $this->service->place(1, [
            $this->row(1, 2, 1, '2026-01-01', 'A'),
            $this->row(2, 3, 2, '2026-03-01', 'B'),
            $this->row(3, 8, 3, '2026-02-01', 'C'),
        ], [
            $this->event(10, '2026-01-01', [
                $this->program(1, 2, 1),
                $this->program(2, 3, 2),
            ]),
            $this->event(20, '2026-02-01', [
                $this->program(3, 8, 3),
            ]),
        ]);

        $this->assertTrue($placement->regroup);
        $this->assertSame(10, $placement->targets[1]);
        $this->assertSame(20, $placement->targets[3]);
        $this->assertSame('new-1', $placement->targets[2]);
        $this->assertSame([10], $placement->touchedIds);
        $this->assertSame([], $placement->updates);
        $this->assertSame([], $placement->deleteIds);
        $this->assertSame('2026-03-01', $placement->creates[0]['date']);
        $this->assertSame([2], $placement->creates[0]['draht_ids']);
    }

    public function test_whole_event_moving_to_an_empty_date_keeps_its_id(): void
    {
        $placement = $this->service->place(1, [
            $this->row(2, 3, 2, '2026-03-01', 'B'),
        ], [
            $this->event(10, '2026-01-01', [
                $this->program(2, 3, 2),
            ]),
        ]);

        $this->assertSame([2 => 10], $placement->targets);
        $this->assertSame([10], $placement->touchedIds);
        $this->assertSame([], $placement->creates);
        $this->assertSame([], $placement->deleteIds);
        $this->assertSame('2026-03-01', $placement->updates[0]['date']);
        $this->assertSame(10, $placement->updates[0]['id']);
    }

    public function test_whole_event_moving_onto_an_occupied_date_reuses_the_destination_and_deletes_the_source(): void
    {
        $placement = $this->service->place(1, [
            $this->row(1, 2, 1, '2026-02-01', 'A'),
            $this->row(2, 3, 2, '2026-02-01', 'B'),
            $this->row(3, 8, 3, '2026-02-01', 'C'),
        ], [
            $this->event(10, '2026-01-01', [
                $this->program(1, 2, 1),
                $this->program(2, 3, 2),
            ]),
            $this->event(20, '2026-02-01', [
                $this->program(3, 8, 3),
            ]),
        ]);

        $this->assertSame([1 => 20, 2 => 20, 3 => 20], $placement->targets);
        $this->assertSame([20], $placement->touchedIds);
        $this->assertSame([10], $placement->deleteIds);
        $this->assertSame([], $placement->creates);
        $this->assertSame([], $placement->updates);
    }

    public function test_same_first_program_on_the_destination_does_not_merge(): void
    {
        $placement = $this->service->place(1, [
            $this->row(1, 3, 1, '2026-02-01', 'A'),
            $this->row(3, 3, 2, '2026-02-01', 'C'),
        ], [
            $this->event(10, '2026-01-01', [
                $this->program(1, 3, 1),
            ]),
            $this->event(20, '2026-02-01', [
                $this->program(3, 3, 2),
            ]),
        ]);

        $this->assertSame(10, $placement->targets[1]);
        $this->assertSame(20, $placement->targets[3]);
        $this->assertSame([10], $placement->touchedIds);
        $this->assertNotContains(20, $placement->touchedIds);
        $this->assertSame([], $placement->creates);
        $this->assertSame([], $placement->deleteIds);
        $this->assertSame('2026-02-01', $placement->updates[0]['date']);
        $this->assertSame(10, $placement->updates[0]['id']);
    }

    public function test_programs_leaving_for_different_dates_keep_the_lowest_sequence_on_the_existing_id(): void
    {
        $placement = $this->service->place(1, [
            $this->row(1, 2, 1, '2026-03-01', 'A'),
            $this->row(2, 3, 2, '2026-04-01', 'B'),
        ], [
            $this->event(10, '2026-01-01', [
                $this->program(1, 2, 1),
                $this->program(2, 3, 2),
            ]),
        ]);

        $this->assertSame(10, $placement->targets[1]);
        $this->assertSame('new-1', $placement->targets[2]);
        $this->assertSame([10], $placement->touchedIds);
        $this->assertSame('2026-03-01', $placement->updates[0]['date']);
        $this->assertSame([2], $placement->creates[0]['draht_ids']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $drahtId, int $firstProgram, int $sequence, string $date, string $name): array
    {
        return [
            'draht_id' => $drahtId,
            'first_program' => $firstProgram,
            'sequence' => $sequence,
            'date' => $date,
            'name' => $name,
            'level' => 1,
            'regional_partner' => 5,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $programs
     * @return array<string, mixed>
     */
    private function event(int $id, string $date, array $programs): array
    {
        return [
            'id' => $id,
            'date' => $date,
            'season' => 1,
            'regional_partner' => 5,
            'programs' => $programs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function program(int $drahtId, int $firstProgram, int $sequence): array
    {
        return [
            'draht_id' => $drahtId,
            'first_program' => $firstProgram,
            'sequence' => $sequence,
        ];
    }
}
