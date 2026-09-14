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
            'title_long' => 'FIRST LEGO League Ausstellung Köln',
            'title_short' => 'Ausstellung Köln',
            'title_type' => 'Ausstellung',
            'title_type_short' => 'Ausstellung',
            'title_place' => 'Köln',
        ], $this->titles->titles($event));
    }

    public function test_level_1_challenge_only(): void
    {
        $event = $this->event(1, 'Aachen', [['name' => 'CHALLENGE']]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Regionalwettbewerb', $titles['title_type']);
        $this->assertSame('Regio', $titles['title_type_short']);
        $this->assertSame('Regio Aachen', $titles['title_short']);
        $this->assertSame('FIRST LEGO League Regionalwettbewerb Aachen', $titles['title_long']);
    }

    public function test_level_1_explore_and_challenge(): void
    {
        $event = $this->event(1, 'Aachen', [
            ['name' => 'EXPLORE'],
            ['name' => 'CHALLENGE'],
        ]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Ausstellung und Regionalwettbewerb', $titles['title_type']);
        $this->assertSame('Ausstellung und Regio', $titles['title_type_short']);
        $this->assertSame('Ausstellung und Regio Aachen', $titles['title_short']);
    }

    public function test_future_only(): void
    {
        $event = $this->event(1, 'Leipzig', [['name' => 'FUTURE_8']]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Future Wettbewerb', $titles['title_type']);
        $this->assertSame('Future Wettbewerb Leipzig', $titles['title_short']);
    }

    public function test_mixed_future_and_challenge(): void
    {
        $event = $this->event(1, 'Leipzig', [
            ['name' => 'FUTURE_8'],
            ['name' => 'CHALLENGE'],
        ]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Mixed Wettbewerb', $titles['title_type']);
        $this->assertSame('Mixed Wettbewerb Leipzig', $titles['title_short']);
    }

    public function test_quali_strips_prefix_and_overrides_programs(): void
    {
        $event = $this->event(2, 'Qualifikation Berlin', [['name' => 'CHALLENGE']]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Qualifikationswettbewerb', $titles['title_type']);
        $this->assertSame('Quali', $titles['title_type_short']);
        $this->assertSame('Berlin', $titles['title_place']);
        $this->assertSame('Quali Berlin', $titles['title_short']);
        $this->assertSame('FIRST LEGO League Qualifikationswettbewerb Berlin', $titles['title_long']);
    }

    public function test_finale_strips_prefix(): void
    {
        $event = $this->event(3, 'Finale Paderborn', [['name' => 'EXPLORE'], ['name' => 'CHALLENGE']]);
        $titles = $this->titles->titles($event);

        $this->assertSame('Finale', $titles['title_type']);
        $this->assertSame('Paderborn', $titles['title_place']);
        $this->assertSame('Finale Paderborn', $titles['title_short']);
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
