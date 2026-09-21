<?php

namespace Tests\Unit;

use App\Services\EventTitleService;
use PHPUnit\Framework\TestCase;

class EventTitleServiceTest extends TestCase
{
    private EventTitleService $titles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->titles = new EventTitleService;
    }

    public function test_level_1_explore_only(): void
    {
        $event = $this->event(1, 'Köln', [['name' => 'EXPLORE']]);
        $this->assertSame([
            'title_long' => 'Explore Event Köln',
            'title_short' => 'Explore Event Köln',
            'title_type' => 'Explore Event',
            'title_place' => 'Köln',
        ], $this->titles->titles($event));
    }

    public function test_level_1_challenge_only(): void
    {
        $event = $this->event(1, 'Aachen', [['name' => 'CHALLENGE']]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Challenge Event', $titles['title_type']);
        $this->assertSame('Challenge Event Aachen', $titles['title_short']);
        $this->assertSame($titles['title_short'], $titles['title_long']);
        $this->assertArrayNotHasKey('title_type_short', $titles);
    }

    public function test_level_1_future_only(): void
    {
        $event = $this->event(1, 'Leipzig', [['name' => 'FUTURE_8']]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Future Edition 8+ Event', $titles['title_type']);
        $this->assertSame('Future Edition 8+ Event Leipzig', $titles['title_short']);
        $this->assertSame($titles['title_short'], $titles['title_long']);
    }

    public function test_level_1_explore_and_challenge(): void
    {
        $event = $this->event(1, 'Aachen', [
            ['name' => 'EXPLORE'],
            ['name' => 'CHALLENGE'],
        ]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Explore und Challenge Event', $titles['title_type']);
        $this->assertSame('Explore und Challenge Event Aachen', $titles['title_short']);
    }

    public function test_level_1_all_three_programs(): void
    {
        $event = $this->event(1, 'Leipzig', [
            ['name' => 'EXPLORE'],
            ['name' => 'CHALLENGE'],
            ['name' => 'FUTURE_8'],
        ]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Explore, Challenge und Future Edition 8+ Event', $titles['title_type']);
        $this->assertSame('Explore, Challenge und Future Edition 8+ Event Leipzig', $titles['title_short']);
        $this->assertSame($titles['title_short'], $titles['title_long']);
    }

    public function test_level_1_challenge_and_future(): void
    {
        $event = $this->event(1, 'Leipzig', [
            ['name' => 'FUTURE_8'],
            ['name' => 'CHALLENGE'],
        ]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Challenge und Future Edition 8+ Event', $titles['title_type']);
        $this->assertSame('Challenge und Future Edition 8+ Event Leipzig', $titles['title_short']);
    }

    public function test_level_2_challenge_qualifikation(): void
    {
        $event = $this->event(2, 'Qualifikation Berlin', [['name' => 'CHALLENGE']]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Challenge (Qualifikation) Event', $titles['title_type']);
        $this->assertSame('Berlin', $titles['title_place']);
        $this->assertSame('Challenge (Qualifikation) Event Berlin', $titles['title_short']);
        $this->assertSame($titles['title_short'], $titles['title_long']);
    }

    public function test_level_2_regensburg_explore_and_challenge(): void
    {
        $event = $this->event(2, 'Regensburg', [
            ['name' => 'EXPLORE'],
            ['name' => 'CHALLENGE'],
        ]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Explore und Challenge (Qualifikation) Event', $titles['title_type']);
        $this->assertSame('Explore und Challenge (Qualifikation) Event Regensburg', $titles['title_short']);
    }

    public function test_finale_is_hardcoded_and_strips_prefix(): void
    {
        $event = $this->event(3, 'Finale Paderborn', [['name' => 'EXPLORE'], ['name' => 'CHALLENGE']]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Future Edition 8+ und Challenge Finale', $titles['title_type']);
        $this->assertSame('Paderborn', $titles['title_place']);
        $this->assertSame('Future Edition 8+ und Challenge Finale Paderborn', $titles['title_short']);
        $this->assertSame($titles['title_short'], $titles['title_long']);
    }

    public function test_empty_programs_is_event(): void
    {
        $event = $this->event(1, 'Köln', []);
        $this->assertSame('Event', $this->titles->titles($event)['title_type']);
        $this->assertSame('Event Köln', $this->titles->titles($event)['title_short']);
        $this->assertSame('Event Köln', $this->titles->titles($event)['title_long']);
    }

    public function test_with_titles_omits_type_fields(): void
    {
        $event = $this->event(1, 'Köln', [['name' => 'EXPLORE']]);
        $payload = $this->titles->withTitles(['id' => 7], $event);

        $this->assertSame(7, $payload['id']);
        $this->assertSame('Explore Event Köln', $payload['title_short']);
        $this->assertSame('Explore Event Köln', $payload['title_long']);
        $this->assertSame('Köln', $payload['title_place']);
        $this->assertArrayNotHasKey('title_type', $payload);
        $this->assertArrayNotHasKey('title_type_short', $payload);
    }

    /**
     * @param  list<array{name: string}>  $programs
     */
    private function event(int $level, string $name, array $programs): object
    {
        return (object) [
            'level' => $level,
            'name' => $name,
            'programs' => $programs,
        ];
    }
}
