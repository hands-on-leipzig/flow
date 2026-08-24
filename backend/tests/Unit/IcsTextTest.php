<?php

namespace Tests\Unit;

use App\Support\IcsText;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class IcsTextTest extends TestCase
{
    public function test_uid_includes_event_id_and_host(): void
    {
        $this->assertSame(
            'event-42@dev.flow.hands-on-technology.org',
            IcsText::uid(42, 'dev.flow.hands-on-technology.org')
        );
        $this->assertSame(
            'event-42@dev.flow.hands-on-technology.org',
            IcsText::uid(42, 'https://dev.flow.hands-on-technology.org/plan')
        );
    }

    public function test_escape_text_backslash_comma_semicolon_newline(): void
    {
        $this->assertSame(
            'A\\, B\\; C\\\\ D\\nE',
            IcsText::escapeText("A, B; C\\ D\nE")
        );
    }

    public function test_fold_line_uses_crlf_and_75_octets(): void
    {
        $line = 'SUMMARY:'.str_repeat('a', 80);
        $folded = IcsText::foldLine($line);
        $parts = explode("\r\n", $folded);
        $this->assertSame(75, strlen($parts[0]));
        $this->assertSame(' ', $parts[1][0]);
        $this->assertLessThanOrEqual(75, strlen($parts[1]));
    }

    public function test_environment_prefix_skips_prod(): void
    {
        $this->assertSame('Hello', IcsText::withEnvironmentPrefix(null, 'Hello'));
        $this->assertSame('Hello', IcsText::withEnvironmentPrefix('PROD', 'Hello'));
        $this->assertSame('[DEV] Hello', IcsText::withEnvironmentPrefix('DEV', 'Hello'));
        $this->assertSame('[LOCAL] Hello', IcsText::withEnvironmentPrefix('local', 'Hello'));
    }

    public function test_summary_order_is_env_then_abgesagt(): void
    {
        $this->assertSame(
            '[DEV] ABGESAGT: FIRST LEGO League Regio Aachen',
            IcsText::summary('FIRST LEGO League Regio Aachen', true, 'DEV')
        );
        $this->assertSame(
            'FIRST LEGO League Regio Aachen',
            IcsText::summary('FIRST LEGO League Regio Aachen', false, null)
        );
    }

    public function test_vevent_all_day_exclusive_end_and_empty_location(): void
    {
        $start = new DateTimeImmutable('2026-03-15', new DateTimeZone('UTC'));
        $stamp = new DateTimeImmutable('2026-03-01 12:00:00', new DateTimeZone('UTC'));
        $vevent = IcsText::vevent([
            'eventId' => 7,
            'host' => 'flow.hands-on-technology.org',
            'title' => 'FIRST LEGO League Ausstellung Aachen',
            'start' => $start,
            'days' => 1,
            'stamp' => $stamp,
            'sequence' => 3,
            'description' => "Kontakt: Ada\nTeams: Alpha",
            'location' => null,
            'url' => 'https://flow.hands-on-technology.org/aachen',
            'cancelled' => false,
            'environmentLabel' => null,
        ]);

        $this->assertStringContainsString("DTSTART;VALUE=DATE:20260315\r\n", $vevent);
        $this->assertStringContainsString("DTEND;VALUE=DATE:20260316\r\n", $vevent);
        $this->assertStringContainsString("SEQUENCE:3\r\n", $vevent);
        $this->assertStringContainsString("LOCATION:\r\n", $vevent);
        $this->assertStringContainsString('URL:https://flow.hands-on-technology.org/aachen', $vevent);
        $this->assertStringNotContainsString('STATUS:CANCELLED', $vevent);
        $this->assertStringStartsWith("BEGIN:VEVENT\r\n", $vevent);
        $this->assertStringEndsWith("\r\nEND:VEVENT", $vevent);
    }

    public function test_vevent_finale_two_days(): void
    {
        $start = new DateTimeImmutable('2026-06-06', new DateTimeZone('UTC'));
        $stamp = new DateTimeImmutable('2026-06-01 08:00:00', new DateTimeZone('UTC'));
        $vevent = IcsText::vevent([
            'eventId' => 1,
            'host' => 'flow.hands-on-technology.org',
            'title' => 'FIRST LEGO League Finale',
            'start' => $start,
            'days' => 2,
            'stamp' => $stamp,
            'sequence' => 0,
            'description' => '',
            'location' => 'Messe, 50667 Köln',
            'url' => null,
            'cancelled' => false,
            'environmentLabel' => null,
        ]);

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260606', $vevent);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260608', $vevent);
        $this->assertStringContainsString('LOCATION:Messe\\, 50667 Köln', $vevent);
    }

    public function test_cancelled_omits_location_and_url_and_sets_status(): void
    {
        $start = new DateTimeImmutable('2026-03-15', new DateTimeZone('UTC'));
        $stamp = new DateTimeImmutable('2026-03-01 12:00:00', new DateTimeZone('UTC'));
        $vevent = IcsText::vevent([
            'eventId' => 9,
            'host' => 'test.flow.hands-on-technology.org',
            'title' => 'FIRST LEGO League Regio Aachen',
            'start' => $start,
            'days' => 1,
            'stamp' => $stamp,
            'sequence' => 1,
            'description' => 'Ada ada@example.org',
            'location' => 'Should not appear',
            'url' => 'https://example.org/no',
            'cancelled' => true,
            'environmentLabel' => 'TEST',
        ]);

        $this->assertStringContainsString('STATUS:CANCELLED', $vevent);
        $this->assertStringContainsString('[TEST] ABGESAGT: FIRST LEGO League Regio Aachen', $vevent);
        $this->assertStringContainsString('DESCRIPTION:ABGESAGT\\nAda ada@example.org', $vevent);
        $this->assertStringNotContainsString('LOCATION', $vevent);
        $this->assertStringNotContainsString('URL:', $vevent);
    }

    public function test_calendar_wraps_vevents_and_prefixes_calname(): void
    {
        $vevent = "BEGIN:VEVENT\r\nEND:VEVENT";
        $ics = IcsText::calendar(IcsText::CALNAME_ALL, [$vevent], 'DEV');

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
        $this->assertStringContainsString('PRODID:'.IcsText::PRODID, $ics);
        $this->assertStringContainsString('METHOD:PUBLISH', $ics);
        $this->assertStringContainsString('X-WR-CALNAME:[DEV] HANDS on TECHNOLOGY Veranstaltungen', $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("\r\n", $ics);
        $this->assertStringNotContainsString("\n\n", str_replace("\r\n", '', $ics));
    }
}
