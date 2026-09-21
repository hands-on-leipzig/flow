<?php

namespace Tests\Unit;

use App\Support\IcsDescription;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IcsDescriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('m_first_program');
        Schema::create('m_first_program', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 50);
            $table->string('display_name')->nullable();
            $table->string('official_name')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
        });

        DB::table('m_first_program')->insert([
            [
                'id' => 2,
                'name' => 'EXPLORE',
                'display_name' => 'Explore',
                'official_name' => '<i>FIRST</i> LEGO League Explore',
                'sequence' => 1,
            ],
            [
                'id' => 3,
                'name' => 'CHALLENGE',
                'display_name' => 'Challenge',
                'official_name' => '<i>FIRST</i> LEGO League Challenge',
                'sequence' => 2,
            ],
            [
                'id' => 8,
                'name' => 'FUTURE_8',
                'display_name' => 'Future 8+',
                'official_name' => '<i>FIRST</i> LEGO League Future 8+',
                'sequence' => 5,
            ],
        ]);
    }

    public function test_contact_only(): void
    {
        $text = IcsDescription::fromPublicPayload([
            'level' => 1,
            'contact' => [
                [
                    'contact' => 'Ada Lovelace',
                    'contact_email' => 'ada@example.org',
                    'contact_infos' => 'RP West',
                ],
            ],
            'teams' => ['explore' => ['list' => []], 'challenge' => ['list' => []]],
        ]);

        $this->assertSame(
            'Kontakt: Ada Lovelace, ada@example.org, RP West',
            $text
        );
    }

    public function test_first_contact_only_on_one_line(): void
    {
        $text = IcsDescription::fromPublicPayload([
            'contact' => [
                [
                    'contact' => "Ada\nLovelace",
                    'contact_email' => 'ada@example.org',
                    'contact_infos' => 'RP  West',
                ],
                [
                    'contact' => 'Grace Hopper',
                    'contact_email' => 'grace@example.org',
                ],
            ],
        ]);

        $this->assertSame('Kontakt: Ada Lovelace, ada@example.org, RP West', $text);
        $this->assertStringNotContainsString('Grace', $text);
        $this->assertStringNotContainsString("\nAda", $text);
    }

    public function test_programmes_then_contact_then_zeitplan_url(): void
    {
        $text = IcsDescription::fromPublicPayload(
            [
                'level' => 4,
                'contact' => [['contact' => 'Ada']],
                'plan' => [
                    'challenge' => [
                        ['label' => 'Eröffnung', 'value' => '2026-03-15 09:00:00'],
                    ],
                ],
            ],
            'https://flow.hands-on-technology.org/aachen',
            ['Explore', 'Challenge']
        );

        $this->assertSame(
            "Programme: FIRST LEGO League Explore, FIRST LEGO League Challenge\nKontakt: Ada\n\nZeitplan: https://flow.hands-on-technology.org/aachen",
            $text
        );
        $this->assertStringNotContainsString('Eröffnung', $text);
        $this->assertStringNotContainsString('Vollständiger Zeitplan', $text);
    }

    public function test_teams_lanes_format(): void
    {
        $text = IcsDescription::fromPublicPayload([
            'teams' => [
                'lanes' => [
                    [
                        'name' => 'Explore',
                        'official_name' => '<i>FIRST</i> LEGO League Explore',
                        'teams' => [
                            ['ref' => '1234', 'name' => 'Robo', 'organization' => 'Schule A', 'location' => 'Köln'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString("FIRST LEGO League Explore\n1234 · Robo · Schule A · Köln", $text);
    }

    public function test_teams_follow_payload_keys_including_unknown_programs(): void
    {
        $text = IcsDescription::fromPublicPayload([
            'level' => 1,
            'contact' => [],
            'teams' => [
                'explore' => [
                    'list' => [[
                        'team_number_hot' => '1234',
                        'name' => 'Robo',
                        'organization' => 'Schule A',
                        'location' => 'Köln',
                    ]],
                ],
                'future_8' => [
                    'list' => [[
                        'team_number_hot' => '9',
                        'name' => 'Glow',
                    ]],
                ],
            ],
        ]);

        $this->assertStringContainsString("FIRST LEGO League Explore\n1234 · Robo · Schule A · Köln", $text);
        $this->assertStringContainsString("FIRST LEGO League Future 8+\n9 · Glow", $text);
        $this->assertStringStartsWith('Angemeldete Teams', $text);
    }

    public function test_plan_in_payload_is_ignored(): void
    {
        $text = IcsDescription::fromPublicPayload([
            'level' => 3,
            'contact' => [['contact' => 'Ada']],
            'plan' => [
                'explore' => [
                    ['label' => 'Eröffnung', 'value' => '2026-03-15 09:00:00'],
                ],
            ],
        ]);

        $this->assertSame('Kontakt: Ada', $text);
    }

    public function test_empty_url_omits_zeitplan_line(): void
    {
        $text = IcsDescription::fromPublicPayload(
            ['level' => 4, 'contact' => [['contact' => 'Ada']]],
            ''
        );

        $this->assertSame('Kontakt: Ada', $text);
        $this->assertStringNotContainsString('Zeitplan', $text);
    }
}
