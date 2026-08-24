<?php

namespace App\Services;

use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use App\Models\EventCalendar;
use App\Support\IcsDescription;
use App\Support\IcsText;
use App\Support\PublicSchedulePayload;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalendarFeedService
{
    public const RESULT_SKIPPED = 'skipped';

    public const RESULT_KEPT = 'kept';

    public const RESULT_BUILT = 'built';

    public const WINDOW_DAYS = 90;

    /** @var array<string, string> */
    public const COUNTRY_POSTFIXES = [
        'de' => 'DE',
        'at' => 'AT',
        'ch' => 'CH',
    ];

    public function __construct(
        private DrahtController $draht,
        private EventTitleService $titles,
    ) {}

    /**
     * Rebuild for a write-path hook. Never throws — ICS must not fail slug, plan, or DRAHT sync.
     */
    public function rebuildSafely(int $eventId): void
    {
        try {
            $this->rebuild($eventId);
        } catch (\Throwable $e) {
            Log::error('ICS rebuild failed', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rebuild one event_calendar row. Does not register routes.
     * Open point 7: cancelled is always false until FLOW has a cancel flag.
     */
    public function rebuild(int $eventId): string
    {
        $event = Event::find($eventId);
        if (! $event || $this->slug($event) === '') {
            return self::RESULT_SKIPPED;
        }

        $existing = EventCalendar::query()->where('event', $eventId)->first();

        try {
            $fetched = $this->draht->fetchScheduleData($event);
        } catch (\Throwable $e) {
            Log::warning('ICS rebuild: DRAHT threw', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            $fetched = ['ok' => false, 'data' => self::emptyDrahtData()];
        }

        if (! ($fetched['ok'] ?? false) && $existing) {
            Log::warning('ICS rebuild: keeping previous vevent after DRAHT failure', [
                'event_id' => $eventId,
            ]);

            return self::RESULT_KEPT;
        }

        $drahtData = is_array($fetched['data'] ?? null) ? $fetched['data'] : self::emptyDrahtData();
        $level = min(2, $this->publicationLevel($eventId));
        $payload = PublicSchedulePayload::from($event, $drahtData, $level, null);
        $description = IcsDescription::fromPublicPayload(
            $payload,
            $this->string($event->link),
            $this->programDisplayNames($event)
        );
        $cancelled = false;
        $sequence = $existing ? ((int) $existing->sequence + 1) : 0;
        $stamp = Carbon::now('UTC');
        $start = Carbon::parse((string) $event->date)->startOfDay();
        $host = self::uidHost();

        $vevent = IcsText::vevent([
            'eventId' => (int) $event->id,
            'host' => $host,
            'title' => $this->titles->getEventTitleLong($event),
            'start' => $start,
            'days' => max(1, (int) $event->days),
            'stamp' => $stamp,
            'sequence' => $sequence,
            'description' => $description,
            'location' => self::locationFromDraht($drahtData['address'] ?? null),
            'url' => $this->string($event->link) !== '' ? $this->string($event->link) : null,
            'cancelled' => $cancelled,
            'environmentLabel' => self::environmentLabel(),
        ]);

        EventCalendar::query()->updateOrInsert(
            ['event' => $eventId],
            [
                'date' => $start->toDateString(),
                'uid' => IcsText::uid((int) $event->id, $host),
                'sequence' => $sequence,
                'vevent' => $vevent,
                'built_at' => $stamp,
            ]
        );

        return self::RESULT_BUILT;
    }

    /**
     * Rebuild every published event in the ICS window. Drops stored rows outside it.
     *
     * @return array{
     *     success: bool,
     *     rebuilt: int,
     *     kept: int,
     *     skipped: int,
     *     failed: int,
     *     removed: int,
     *     total: int,
     *     errors: list<string>
     * }
     */
    public function rebuildWindow(): array
    {
        $ids = DB::table('event')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->where('date', '>=', $this->windowStartDate())
            ->orderBy('date')
            ->orderBy('id')
            ->pluck('id');

        $idList = $ids->map(fn ($id) => (int) $id)->all();
        $eventCount = count($idList);
        $estimatedTime = max(60, min(600, $eventCount * 10));
        set_time_limit($estimatedTime);
        ini_set('max_execution_time', (string) $estimatedTime);

        $removedQuery = DB::table('event_calendar');
        if ($idList === []) {
            $removed = $removedQuery->delete();
        } else {
            $removed = $removedQuery->whereNotIn('event', $idList)->delete();
        }

        $rebuilt = 0;
        $kept = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($idList as $eventId) {
            try {
                $result = $this->rebuild($eventId);
                if ($result === self::RESULT_BUILT) {
                    $rebuilt++;
                } elseif ($result === self::RESULT_KEPT) {
                    $kept++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = 'Event '.$eventId.': '.$e->getMessage();
                Log::error('ICS window rebuild failed', [
                    'event_id' => $eventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => true,
            'rebuilt' => $rebuilt,
            'kept' => $kept,
            'skipped' => $skipped,
            'failed' => $failed,
            'removed' => (int) $removed,
            'total' => $eventCount,
            'errors' => $errors,
        ];
    }

    /**
     * Admin dropdown: all + each program postfix + de/at/ch. Read catalog only.
     *
     * @return list<array{key: string, postfix: ?string, label: string, url: string}>
     */
    public function listFeeds(string $publicBaseUrl): array
    {
        $base = rtrim($publicBaseUrl, '/');
        $env = self::environmentLabel();
        $feeds = [[
            'key' => 'all',
            'postfix' => null,
            'label' => IcsText::withEnvironmentPrefix($env, IcsText::CALNAME_ALL),
            'url' => $base.'/api/calendar.ics',
        ]];

        $programs = DB::table('m_first_program')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['ics_postfix', 'display_name', 'name']);

        foreach ($programs as $program) {
            $postfix = strtolower(trim((string) ($program->ics_postfix ?? '')));
            if ($postfix === '' || isset(self::COUNTRY_POSTFIXES[$postfix])) {
                continue;
            }
            $display = trim((string) ($program->display_name ?: $program->name));
            $calName = IcsText::CALNAME_ALL.' — '.$display;
            $feeds[] = [
                'key' => $postfix,
                'postfix' => $postfix,
                'label' => IcsText::withEnvironmentPrefix($env, $calName),
                'url' => $base.'/api/calendar/'.$postfix.'.ics',
            ];
        }

        foreach (self::COUNTRY_POSTFIXES as $postfix => $code) {
            $calName = IcsText::CALNAME_ALL.' — '.$code;
            $feeds[] = [
                'key' => $postfix,
                'postfix' => $postfix,
                'label' => IcsText::withEnvironmentPrefix($env, $calName),
                'url' => $base.'/api/calendar/'.$postfix.'.ics',
            ];
        }

        return $feeds;
    }

    /**
     * Admin preview JSON from stored vevent rows. Does not call DRAHT or rebuild.
     *
     * @return array{key: string, postfix: ?string, label: string, url: string, events: list<array<string, mixed>>}|null
     */
    public function previewFeed(string $key, string $publicBaseUrl): ?array
    {
        $key = strtolower(trim($key));
        $feeds = $this->listFeeds($publicBaseUrl);
        $meta = null;
        foreach ($feeds as $feed) {
            if ($feed['key'] === $key) {
                $meta = $feed;
                break;
            }
        }
        if ($meta === null) {
            return null;
        }

        $constrain = null;
        if ($key !== 'all') {
            $resolved = $this->constrainForPostfix($key);
            if ($resolved === null) {
                return null;
            }
            $constrain = $resolved;
        }

        $events = [];
        foreach ($this->windowRows($constrain) as $row) {
            $parsed = IcsText::parseVevent((string) $row->vevent);
            $builtAt = $row->built_at ? Carbon::parse($row->built_at)->utc()->toIso8601String() : null;
            $events[] = [
                'event_id' => (int) $row->event,
                'uid' => $parsed['uid'] ?? $row->uid,
                'summary' => $parsed['summary'],
                'dtstart' => $parsed['dtstart'] ?? (string) $row->date,
                'dtend' => $parsed['dtend'],
                'location' => $parsed['location'],
                'description' => $parsed['description'],
                'url' => $parsed['url'],
                'status' => $parsed['status'],
                'sequence' => $row->sequence !== null ? (int) $row->sequence : $parsed['sequence'],
                'built_at' => $builtAt,
            ];
        }

        return [
            'key' => $meta['key'],
            'postfix' => $meta['postfix'],
            'label' => $meta['label'],
            'url' => $meta['url'],
            'events' => $events,
        ];
    }

    /**
     * @return callable(\Illuminate\Database\Query\Builder): mixed|null
     */
    private function constrainForPostfix(string $postfix): ?callable
    {
        $program = DB::table('m_first_program')->where('ics_postfix', $postfix)->first();
        if ($program) {
            return function ($query) use ($program) {
                $query->join('event_program', 'event_program.event', '=', 'event.id')
                    ->where('event_program.first_program', $program->id);
            };
        }
        if (isset(self::COUNTRY_POSTFIXES[$postfix])) {
            return function ($query) {
                $query->whereRaw('0 = 1');
            };
        }

        return null;
    }

    /**
     * Public all-events ICS from stored rows. Does not call DRAHT or rebuild.
     *
     * @return array{body: string, lastModified: Carbon|null}
     */
    public function feedAll(): array
    {
        return $this->feedFromQuery(IcsText::CALNAME_ALL);
    }

    /**
     * Program postfix (m_first_program.ics_postfix) wins; else de/at/ch (empty until country exists).
     * Unknown postfix → null (HTTP 404).
     *
     * @return array{body: string, lastModified: Carbon|null}|null
     */
    public function feedByPostfix(string $postfix): ?array
    {
        $postfix = strtolower(trim($postfix));
        if ($postfix === '') {
            return null;
        }

        $constrain = $this->constrainForPostfix($postfix);
        if ($constrain === null) {
            return null;
        }

        $program = DB::table('m_first_program')->where('ics_postfix', $postfix)->first();
        if ($program) {
            $display = trim((string) ($program->display_name ?: $program->name));
            $calName = IcsText::CALNAME_ALL.' — '.$display;
        } else {
            $calName = IcsText::CALNAME_ALL.' — '.self::COUNTRY_POSTFIXES[$postfix];
        }

        return $this->feedFromQuery($calName, $constrain);
    }

    /**
     * @param  callable(\Illuminate\Database\Query\Builder): mixed|null  $constrain
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function windowRows(?callable $constrain = null)
    {
        $query = DB::table('event_calendar')
            ->join('event', 'event.id', '=', 'event_calendar.event')
            ->whereNotNull('event.slug')
            ->where('event.slug', '!=', '')
            ->where('event_calendar.date', '>=', $this->windowStartDate());

        if ($constrain !== null) {
            $constrain($query);
        }

        $rows = $query
            ->orderBy('event_calendar.date')
            ->orderBy('event_calendar.event')
            ->get([
                'event_calendar.vevent',
                'event_calendar.built_at',
                'event_calendar.event',
                'event_calendar.date',
                'event_calendar.uid',
                'event_calendar.sequence',
            ]);

        $seen = [];
        $unique = collect();
        foreach ($rows as $row) {
            $eventId = (int) $row->event;
            if (isset($seen[$eventId])) {
                continue;
            }
            $seen[$eventId] = true;
            $unique->push($row);
        }

        return $unique;
    }

    /**
     * @param  callable(\Illuminate\Database\Query\Builder): mixed|null  $constrain
     * @return array{body: string, lastModified: Carbon|null}
     */
    private function feedFromQuery(string $calName, ?callable $constrain = null): array
    {
        $vevents = [];
        $lastModified = null;
        foreach ($this->windowRows($constrain) as $row) {
            $vevent = trim((string) $row->vevent);
            if ($vevent !== '') {
                $vevents[] = $vevent;
            }
            if ($row->built_at) {
                $at = Carbon::parse($row->built_at);
                if ($lastModified === null || $at->gt($lastModified)) {
                    $lastModified = $at;
                }
            }
        }

        return [
            'body' => IcsText::calendar($calName, $vevents, self::environmentLabel()),
            'lastModified' => $lastModified,
        ];
    }

    public static function environmentLabel(): ?string
    {
        $env = strtolower((string) config('app.env'));
        if ($env === 'production' || $env === 'prod') {
            return null;
        }
        if ($env === 'testing' || $env === 'test') {
            return 'TEST';
        }

        $host = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?? ''));
        if (str_contains($host, 'dev.flow.') || $host === 'dev.flow.hands-on-technology.org') {
            return 'DEV';
        }
        if (str_contains($host, 'test.flow.') || $host === 'test.flow.hands-on-technology.org') {
            return 'TEST';
        }
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return 'LOCAL';
        }

        return 'DEV';
    }

    public static function uidHost(): string
    {
        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?? '');
        $host = strtolower(trim($host));

        return $host !== '' ? $host : 'localhost';
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyDrahtData(): array
    {
        return [
            'programs' => [],
            'address' => null,
            'contact' => [],
            'information' => null,
        ];
    }

    private function windowStartDate(): string
    {
        return Carbon::today()->subDays(self::WINDOW_DAYS)->toDateString();
    }

    /**
     * @return list<string>
     */
    private function programDisplayNames(Event $event): array
    {
        $event->loadMissing('programs.firstProgram');
        $names = [];
        foreach ($event->programs as $program) {
            $label = trim((string) ($program->display_name ?: $program->name));
            if ($label !== '') {
                $names[] = $label;
            }
        }

        return $names;
    }

    private function publicationLevel(int $eventId): int
    {
        $publication = DB::table('publication')
            ->where('event', $eventId)
            ->orderBy('last_change', 'desc')
            ->orderBy('id', 'desc')
            ->select('level')
            ->first();

        return (int) ($publication?->level ?? 1);
    }

    private function slug(Event $event): string
    {
        return $this->string($event->slug);
    }

    private function string(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        return trim((string) $value);
    }

    private static function locationFromDraht(mixed $address): ?string
    {
        if ($address === null || $address === '' || $address === []) {
            return null;
        }
        if (is_array($address)) {
            $parts = [];
            foreach (['name', 'street', 'line1', 'zip', 'city'] as $key) {
                if (! empty($address[$key]) && is_scalar($address[$key])) {
                    $parts[] = trim((string) $address[$key]);
                }
            }
            if ($parts === []) {
                foreach ($address as $value) {
                    if (is_string($value) && trim($value) !== '') {
                        $parts[] = trim($value);
                    }
                }
            }
            $text = implode(', ', $parts);

            return $text !== '' ? $text : null;
        }

        $text = trim((string) $address);

        return $text !== '' ? $text : null;
    }
}
