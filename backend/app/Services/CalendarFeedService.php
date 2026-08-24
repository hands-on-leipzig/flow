<?php

namespace App\Services;

use App\Http\Controllers\Api\DrahtController;
use App\Http\Controllers\Api\PublishController;
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

    public function __construct(
        private DrahtController $draht,
        private EventTitleService $titles,
        private PublishController $publish,
    ) {}

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
        $level = $this->publicationLevel($eventId);
        $plan = null;
        if ($level >= 3) {
            try {
                $plan = $this->publish->importantTimesPayload($eventId);
            } catch (\Throwable $e) {
                Log::warning('ICS rebuild: importantTimes failed', [
                    'event_id' => $eventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $payload = PublicSchedulePayload::from($event, $drahtData, $level, $plan);
        $description = IcsDescription::fromPublicPayload($payload, $this->string($event->link));
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
