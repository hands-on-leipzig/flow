<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Support\PublicSchedulePayload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicSchedulePayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    public function test_builds_team_lanes_from_draht_programs(): void
    {
        $event = new Event([
            'id' => 42,
            'date' => '2026-03-15',
            'days' => 1,
            'enddate' => null,
        ]);
        $event->setRelation('programs', collect([
            (object) ['first_program' => 1, 'name' => 'EXPLORE', 'sequence' => 1],
            (object) ['first_program' => 2, 'name' => 'CHALLENGE', 'sequence' => 2],
        ]));

        Schema::getConnection()->table('m_first_program')->insert([
            [
                'id' => 1,
                'name' => 'EXPLORE',
                'display_name' => 'Explore',
                'sequence' => 1,
                'color_hex' => '00A651',
            ],
            [
                'id' => 2,
                'name' => 'CHALLENGE',
                'display_name' => 'Challenge',
                'sequence' => 2,
                'color_hex' => 'ED1C24',
            ],
        ]);

        $payload = PublicSchedulePayload::from($event, [
            'address' => 'Musterstraße 1',
            'contact' => [],
            'programs' => [
                [
                    'first_program' => 1,
                    'name' => 'EXPLORE',
                    'teams' => [
                        ['ref' => '1234', 'name' => 'Robo Kids'],
                    ],
                ],
                [
                    'first_program' => 2,
                    'name' => 'CHALLENGE',
                    'teams' => [
                        ['ref' => '5678', 'name' => 'Brick Bots'],
                    ],
                ],
            ],
        ], 1);

        $this->assertSame([
            [
                'program_id' => 1,
                'name' => 'Explore',
                'sequence' => 1,
                'color_hex' => '00A651',
                'teams' => [
                    ['ref' => '1234', 'name' => 'Robo Kids'],
                ],
            ],
            [
                'program_id' => 2,
                'name' => 'Challenge',
                'sequence' => 2,
                'color_hex' => 'ED1C24',
                'teams' => [
                    ['ref' => '5678', 'name' => 'Brick Bots'],
                ],
            ],
        ], $payload['teams']['lanes']);
    }

    public function test_omits_programs_without_teams(): void
    {
        $event = new Event([
            'id' => 43,
            'date' => '2026-03-15',
            'days' => 1,
            'enddate' => null,
        ]);
        $event->setRelation('programs', collect([
            (object) ['first_program' => 1, 'name' => 'EXPLORE', 'sequence' => 1],
            (object) ['first_program' => 8, 'name' => 'FUTURE_8', 'sequence' => 3],
        ]));

        Schema::getConnection()->table('m_first_program')->insert([
            ['id' => 1, 'name' => 'EXPLORE', 'display_name' => 'Explore', 'sequence' => 1, 'color_hex' => '00A651'],
            ['id' => 8, 'name' => 'FUTURE_8', 'display_name' => 'Future 8+', 'sequence' => 3, 'color_hex' => '111111'],
        ]);

        $payload = PublicSchedulePayload::from($event, [
            'programs' => [
                [
                    'first_program' => 1,
                    'teams' => [['ref' => '42', 'name' => 'Only Explore']],
                ],
                [
                    'first_program' => 8,
                    'teams' => [],
                ],
            ],
        ], 1);

        $this->assertCount(1, $payload['teams']['lanes']);
        $this->assertSame('Explore', $payload['teams']['lanes'][0]['name']);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('m_first_program');
        Schema::create('m_first_program', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->integer('sequence');
            $table->string('color_hex')->nullable();
        });
    }
}
