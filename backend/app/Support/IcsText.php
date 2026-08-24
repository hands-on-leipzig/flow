<?php

namespace App\Support;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * RFC 5545 text: fold, escape, one VEVENT, wrap VCALENDAR.
 * Callers pass already-resolved title, description, location, dates — no DB.
 */
final class IcsText
{
    public const PRODID = '-//HANDS on TECHNOLOGY//FLOW//DE';

    public const CALNAME_ALL = 'HANDS on TECHNOLOGY Veranstaltungen';

    /**
     * @param  ?string  $environmentLabel  DEV, TEST, LOCAL, or null/PROD for none
     */
    public static function withEnvironmentPrefix(?string $environmentLabel, string $text): string
    {
        $label = strtoupper(trim((string) $environmentLabel));
        if ($label === '' || $label === 'PROD' || $label === 'PRODUCTION') {
            return $text;
        }

        return '['.$label.'] '.$text;
    }

    public static function summary(string $title, bool $cancelled, ?string $environmentLabel): string
    {
        $text = $cancelled ? 'ABGESAGT: '.$title : $title;

        return self::withEnvironmentPrefix($environmentLabel, $text);
    }

    public static function description(string $body, bool $cancelled): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = trim($body);

        if (! $cancelled) {
            return $body;
        }

        if ($body === '') {
            return 'ABGESAGT';
        }

        if (str_starts_with($body, 'ABGESAGT')) {
            return $body;
        }

        return "ABGESAGT\n".$body;
    }

    public static function uid(int $eventId, string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];

        if ($host === '') {
            throw new InvalidArgumentException('ICS UID host must not be empty.');
        }

        return 'event-'.$eventId.'@'.$host;
    }

    public static function formatDate(DateTimeInterface $date): string
    {
        return $date->format('Ymd');
    }

    public static function formatUtc(DateTimeInterface $date): string
    {
        return $date->format('Ymd\THis\Z');
    }

    public static function escapeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);

        return $text;
    }

    public static function foldLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $chunks = [substr($line, 0, 75)];
        $rest = substr($line, 75);
        while ($rest !== '') {
            $chunks[] = ' '.substr($rest, 0, 74);
            $rest = substr($rest, 74);
        }

        return implode("\r\n", $chunks);
    }

    /**
     * @param  array{
     *     eventId: int,
     *     host: string,
     *     title: string,
     *     start: DateTimeInterface,
     *     days: int,
     *     stamp: DateTimeInterface,
     *     sequence: int,
     *     description: string,
     *     location: ?string,
     *     url: ?string,
     *     cancelled: bool,
     *     environmentLabel: ?string
     * }  $event
     */
    public static function vevent(array $event): string
    {
        $days = max(1, (int) $event['days']);
        $cancelled = (bool) $event['cancelled'];
        $env = $event['environmentLabel'] ?? null;
        $title = (string) $event['title'];
        $start = $event['start'];
        $end = \DateTimeImmutable::createFromInterface($start)->modify('+'.$days.' days');
        $stamp = $event['stamp'];

        $lines = [
            'BEGIN:VEVENT',
            'UID:'.self::uid((int) $event['eventId'], (string) $event['host']),
            'DTSTAMP:'.self::formatUtc($stamp),
            'LAST-MODIFIED:'.self::formatUtc($stamp),
            'SEQUENCE:'.(int) $event['sequence'],
            'DTSTART;VALUE=DATE:'.self::formatDate($start),
            'DTEND;VALUE=DATE:'.self::formatDate($end),
            'SUMMARY:'.self::escapeText(self::summary($title, $cancelled, $env)),
        ];

        if ($cancelled) {
            $lines[] = 'STATUS:CANCELLED';
        }

        $description = self::description((string) $event['description'], $cancelled);
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:'.self::escapeText($description);
        }

        if ($cancelled) {
            // LOCATION and URL omitted
        } elseif (array_key_exists('location', $event) && $event['location'] === null) {
            $lines[] = 'LOCATION:';
        } elseif (! empty($event['location'])) {
            $lines[] = 'LOCATION:'.self::escapeText((string) $event['location']);
        } else {
            $lines[] = 'LOCATION:';
        }

        if (! $cancelled && ! empty($event['url'])) {
            $lines[] = 'URL:'.self::escapeText((string) $event['url']);
        }

        $lines[] = 'END:VEVENT';

        $folded = array_map(self::foldLine(...), $lines);

        return implode("\r\n", $folded);
    }

    /**
     * @param  list<string>  $vevents  BEGIN:VEVENT … END:VEVENT blocks
     */
    public static function calendar(string $calName, array $vevents, ?string $environmentLabel = null): string
    {
        $name = self::withEnvironmentPrefix($environmentLabel, $calName);
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escapeText($name),
        ];

        $head = implode("\r\n", array_map(self::foldLine(...), $lines));
        $body = implode("\r\n", $vevents);
        $ics = $head;
        if ($body !== '') {
            $ics .= "\r\n".$body;
        }
        $ics .= "\r\nEND:VCALENDAR\r\n";

        return $ics;
    }
}
