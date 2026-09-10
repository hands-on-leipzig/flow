<?php

namespace App\Services;

use App\Models\Event;
use App\Models\MSeason;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One slug per event and season, shared by every system that links to a plan.
 *
 * The slug lives on `event.slug`; `event_slug_history` keeps replaced slugs so old
 * QR codes can still be redirected. FLOW owns the value, DRAHT and JOIN resolve it
 * through the external API instead of storing a URL of their own.
 */
class EventSlugService
{
    public const SYSTEM_FLOW = 'flow';

    public const SYSTEM_DRAHT = 'draht';

    /**
     * Paths the SPA and Laravel own. A one-link sits at the URL root, so a slug that
     * matched one of these would shadow an app route.
     */
    private const RESERVED = [
        'admin', 'api', 'build', 'carousel', 'check-in', 'cockpit', 'editslide', 'event',
        'event-day', 'events', 'legacy', 'live', 'login', 'logos', 'logout', 'output',
        'overview', 'password', 'plan', 'presentation', 'preview', 'profile', 'public-schedule',
        'publish', 'rooms', 'sanctum', 'schedule', 'scores', 'slots', 'storage', 'teams',
        'unauthorized', 'volunteers',
    ];

    /**
     * The vanity host resolves its own short links before it falls back to FLOW, so
     * those names have to stay free as well. Keep in sync with urls.json and the
     * routes of the handson.tools shortener. `dev` and `test` address the non-production
     * FLOW instances, which is why they are prefixes and not event slugs.
     */
    private const RESERVED_SHORTENER = [
        'auth', 'dev', 'flow', 'jury', 'pfand', 'rg', 's', 'test',
    ];

    /** @var array<int, int|null> */
    private array $seasonYears = [];

    private ?int $currentSeasonId = null;

    private bool $currentSeasonResolved = false;

    /**
     * Map a caller to the id space it addresses events in. JOIN has no backend of its
     * own — it goes through DRAHT and therefore uses DRAHT ids.
     */
    public function normalizeSystem(string $system): ?string
    {
        return match (strtolower(trim($system))) {
            'flow' => self::SYSTEM_FLOW,
            'draht', 'join' => self::SYSTEM_DRAHT,
            default => null,
        };
    }

    /**
     * Find the FLOW event a caller means. Explore and Challenge are separate events in
     * DRAHT but one event in FLOW, so both DRAHT ids land on the same slug.
     */
    public function resolve(string $system, int $externalId): ?Event
    {
        return match ($this->normalizeSystem($system)) {
            self::SYSTEM_FLOW => Event::find($externalId),
            self::SYSTEM_DRAHT => Event::whereHas(
                'programs',
                fn ($query) => $query->where('draht_id', $externalId)
            )->first(),
            default => null,
        };
    }

    /**
     * Reverse lookup for the public one-link. Without a year the current season wins;
     * a year addresses that season's archive. A hit in the history reports the slug
     * that replaced it, so callers can redirect instead of answering 404.
     *
     * @return array{event: Event, redirect_to: string|null}|null
     */
    public function find(string $slug, ?int $year = null): ?array
    {
        $slug = $this->sanitize($slug);
        if ($slug === '') {
            return null;
        }

        $seasonId = $year === null ? $this->currentSeasonId() : $this->seasonIdForYear($year);
        if ($seasonId === null) {
            return null;
        }

        $event = Event::where('slug', $slug)->where('season', $seasonId)->first();
        if ($event) {
            return ['event' => $event, 'redirect_to' => null];
        }

        $eventId = DB::table('event_slug_history')
            ->where('slug', $slug)
            ->where('season', $seasonId)
            ->value('event');

        $event = $eventId === null ? null : Event::find($eventId);

        return $event === null ? null : ['event' => $event, 'redirect_to' => $this->path($event)];
    }

    /**
     * Slug for an event, generated from name and level if it has none yet.
     */
    public function ensure(Event $event): string
    {
        $slug = (string) ($event->slug ?? '');

        return $slug !== '' ? $slug : $this->assign($event, $this->suggest($event), false);
    }

    /**
     * Re-derive the slug from the event data. A slug that was set by hand is kept.
     */
    public function regenerate(Event $event): string
    {
        if ($event->slug_manual && ! empty($event->slug)) {
            return (string) $event->slug;
        }

        return $this->assign($event, $this->suggest($event), false);
    }

    /**
     * Store a slug. Generated slugs get a numeric suffix on collision; a slug set by
     * hand is rejected instead, so the caller learns that the name is taken.
     *
     * @throws InvalidArgumentException
     */
    public function assign(Event $event, string $slug, bool $manual = true): string
    {
        $season = (int) $event->season;
        $wanted = $this->sanitize($slug);

        if ($wanted === '') {
            throw new InvalidArgumentException('Slug is empty after normalization.');
        }

        if ($this->isReserved($wanted)) {
            throw new InvalidArgumentException("Slug '{$wanted}' is reserved for an application route.");
        }

        if (! $this->isAvailable($wanted, $season, (int) $event->id)) {
            if ($manual) {
                throw new InvalidArgumentException("Slug '{$wanted}' is already used in this season.");
            }

            $wanted = $this->makeUnique($wanted, $season, (int) $event->id);
        }

        $previous = (string) ($event->slug ?? '');
        if ($previous !== '' && $previous !== $wanted) {
            DB::table('event_slug_history')->insertOrIgnore([
                'event' => (int) $event->id,
                'season' => $season,
                'slug' => $previous,
                'replaced_at' => now(),
            ]);
        }

        // Taking a slug back means it is current again, not history.
        DB::table('event_slug_history')
            ->where('event', (int) $event->id)
            ->where('season', $season)
            ->where('slug', $wanted)
            ->delete();

        $event->slug = $wanted;
        $event->slug_manual = $manual;
        $event->save();

        return $wanted;
    }

    /**
     * Slug proposal from level and event name, without checking availability.
     */
    public function suggest(Event $event): string
    {
        $base = match ((int) $event->level) {
            2 => $this->qualiBase((string) $event->name),
            3 => 'finale',
            default => $this->regioBase($event),
        };

        return $this->sanitize($base) ?: 'event-'.$event->id;
    }

    /**
     * @return array<int, string>
     */
    public function reserved(): array
    {
        $reserved = array_merge(self::RESERVED, self::RESERVED_SHORTENER);
        sort($reserved);

        return $reserved;
    }

    public function isReserved(string $slug): bool
    {
        // A purely numeric slug would be read as the season prefix of an archive URL.
        return in_array($slug, self::RESERVED, true)
            || in_array($slug, self::RESERVED_SHORTENER, true)
            || preg_match('/^\d+$/', $slug) === 1;
    }

    public function isAvailable(string $slug, int $season, ?int $exceptEventId = null): bool
    {
        if ($slug === '' || $this->isReserved($slug)) {
            return false;
        }

        $takenByEvent = Event::where('slug', $slug)
            ->where('season', $season)
            ->when($exceptEventId, fn ($query) => $query->where('id', '<>', $exceptEventId))
            ->exists();

        if ($takenByEvent) {
            return false;
        }

        return ! DB::table('event_slug_history')
            ->where('slug', $slug)
            ->where('season', $season)
            ->when($exceptEventId, fn ($query) => $query->where('event', '<>', $exceptEventId))
            ->exists();
    }

    public function makeUnique(string $base, int $season, ?int $exceptEventId = null): string
    {
        $slug = $this->sanitize($base) ?: 'event';
        $candidate = $slug;
        $suffix = 1;

        while (! $this->isAvailable($candidate, $season, $exceptEventId)) {
            $candidate = $slug.'-'.(++$suffix);

            if ($suffix > 100) {
                throw new InvalidArgumentException("No free slug for base '{$slug}' in season {$season}.");
            }
        }

        return $candidate;
    }

    public function sanitize(string $raw): string
    {
        $slug = mb_strtolower(trim($raw), 'UTF-8');
        $slug = str_replace(
            ['ä', 'ö', 'ü', 'ß', '/', ' ', '_'],
            ['ae', 'oe', 'ue', 'ss', '-', '-', '-'],
            $slug
        );
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    public function base(): string
    {
        return rtrim((string) config('app.public_url', 'https://handson.tools'), '/');
    }

    /**
     * Public path. The current season answers without a prefix; older seasons keep
     * their plans reachable under the season year.
     */
    public function path(Event $event): ?string
    {
        $slug = (string) ($event->slug ?? '');
        if ($slug === '') {
            return null;
        }

        $seasonId = (int) $event->season;
        $year = $this->seasonYear($seasonId);

        if ($year !== null && ! $this->isCurrentSeason($seasonId)) {
            return '/'.$year.'/'.$slug;
        }

        return '/'.$slug;
    }

    public function url(Event $event): ?string
    {
        $path = $this->path($event);

        return $path === null ? null : $this->base().$path;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(Event $event): array
    {
        $seasonId = (int) $event->season;

        return [
            'event_id' => (int) $event->id,
            'draht_ids' => $event->programs
                ->pluck('draht_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'slug' => $event->slug ? (string) $event->slug : null,
            'path' => $this->path($event),
            'url' => $this->url($event),
            'manual' => (bool) $event->slug_manual,
            'season_id' => $seasonId,
            'season_year' => $this->seasonYear($seasonId),
            'current_season' => $this->isCurrentSeason($seasonId),
        ];
    }

    public function isCurrentSeason(int $seasonId): bool
    {
        return $seasonId > 0 && $seasonId === $this->currentSeasonId();
    }

    public function currentSeasonId(): ?int
    {
        if (! $this->currentSeasonResolved) {
            $this->currentSeasonResolved = true;

            try {
                $this->currentSeasonId = SeasonService::currentSeasonId();
            } catch (\Throwable) {
                $this->currentSeasonId = null;
            }
        }

        return $this->currentSeasonId;
    }

    public function seasonYear(int $seasonId): ?int
    {
        if (! array_key_exists($seasonId, $this->seasonYears)) {
            $year = MSeason::query()->where('id', $seasonId)->value('year');
            $this->seasonYears[$seasonId] = $year === null ? null : (int) $year;
        }

        return $this->seasonYears[$seasonId];
    }

    public function seasonIdForYear(int $year): ?int
    {
        $id = MSeason::query()->where('year', $year)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function regioBase(Event $event): string
    {
        $name = (string) $event->name;
        $level = (int) $event->level;

        // One regional partner running two events of the same name in a season: the
        // program letters are what keeps those slugs apart. Events that already differ
        // by name get no suffix, so the slug stays as short as it can be.
        if ($level > 0 && $this->hasNamesake($event)) {
            $letters = $this->programLetters($event);
            if ($letters !== '') {
                $name .= '-'.$letters;
            }
        }

        return $name;
    }

    /**
     * Whether the same regional partner runs another event of the same level and name
     * in this season. Names are compared after normalization, because that is what ends
     * up in the slug.
     */
    private function hasNamesake(Event $event): bool
    {
        $mine = $this->sanitize((string) $event->name);

        return Event::where('regional_partner', $event->regional_partner)
            ->where('level', (int) $event->level)
            ->where('season', $event->season)
            ->when($event->id, fn ($query) => $query->where('id', '<>', (int) $event->id))
            ->pluck('name')
            ->contains(fn ($other) => $this->sanitize((string) $other) === $mine);
    }

    /**
     * Program letters in catalog order: Explore e, Challenge c, Future f. The catalog
     * letter is the source; its digits (F5, F8) drop out, because the suffix only has to
     * tell one partner's events apart, and a repeated letter is written once.
     */
    private function programLetters(Event $event): string
    {
        $letters = '';

        foreach ($event->programs as $program) {
            $letter = preg_replace('/[^a-z]/', '', mb_strtolower((string) $program->letter, 'UTF-8')) ?? '';

            if ($letter === '') {
                $letter = mb_substr(mb_strtolower((string) $program->name, 'UTF-8'), 0, 1);
            }

            if ($letter !== '' && ! str_contains($letters, $letter)) {
                $letters .= $letter;
            }
        }

        return $letters;
    }

    private function qualiBase(string $name): string
    {
        // "Region - Stadt" becomes "quali-stadt"; the two characters after the first
        // dash are the dash itself and the space behind it.
        $dash = strpos($name, '-');
        $tail = $dash === false ? $name : substr($name, $dash + 2);

        return 'quali-'.$tail;
    }
}
