<?php

namespace Tests\Unit;

use App\Support\IcsDescription;
use PHPUnit\Framework\TestCase;

class IcsDescriptionTest extends TestCase
{
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
            "Kontakt\nAda Lovelace, ada@example.org, RP West",
            $text
        );
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

        $this->assertStringContainsString("Explore\n1234 · Robo · Schule A · Köln", $text);
        $this->assertStringContainsString("Future 8\n9 · Glow", $text);
        $this->assertStringStartsWith('Angemeldete Teams', $text);
    }

    public function test_plan_times_when_present_without_rechecking_level(): void
    {
        $text = IcsDescription::fromPublicPayload([
            'level' => 3,
            'contact' => [],
            'plan' => [
                'explore' => [
                    ['label' => 'Eröffnung', 'value' => '2026-03-15 09:00:00'],
                    ['label' => 'Ende ca.', 'value' => '2026-03-15 12:30:00'],
                ],
                'challenge' => [
                    ['label' => 'Eröffnung', 'value' => '2026-03-15 09:30:00'],
                ],
            ],
        ]);

        $this->assertStringContainsString("Zeitplan\nExplore\n09:00 Eröffnung\n12:30 Ende ca.", $text);
        $this->assertStringContainsString("Challenge\n09:30 Eröffnung", $text);
        $this->assertStringNotContainsString('Mo,', $text);
    }

    public function test_plan_shows_weekday_when_times_span_days(): void
    {
        $text = IcsDescription::fromPublicPayload([
            'level' => 3,
            'plan' => [
                'challenge' => [
                    ['label' => 'Tag 1', 'value' => '2026-06-06 10:00:00'],
                    ['label' => 'Tag 2', 'value' => '2026-06-07 10:00:00'],
                ],
            ],
        ]);

        $this->assertStringContainsString('Sa, 10:00 Tag 1', $text);
        $this->assertStringContainsString('So, 10:00 Tag 2', $text);
    }

    public function test_level_4_appends_plan_url(): void
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
            'https://flow.hands-on-technology.org/aachen'
        );

        $this->assertStringContainsString('Kontakt', $text);
        $this->assertStringContainsString('Zeitplan', $text);
        $this->assertStringEndsWith(
            'Vollständiger Zeitplan: https://flow.hands-on-technology.org/aachen',
            $text
        );
    }

    public function test_level_below_4_does_not_append_url(): void
    {
        $text = IcsDescription::fromPublicPayload(
            ['level' => 3, 'contact' => [['contact' => 'Ada']]],
            'https://flow.hands-on-technology.org/aachen'
        );

        $this->assertStringNotContainsString('Vollständiger Zeitplan', $text);
    }

    public function test_plan_error_payload_is_ignored(): void
    {
        $text = IcsDescription::fromPublicPayload([
            'level' => 3,
            'contact' => [['contact' => 'Ada']],
            'plan' => ['error' => 'Kein Plan für dieses Event gefunden'],
        ]);

        $this->assertSame("Kontakt\nAda", $text);
    }
}
