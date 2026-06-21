<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventSlug;
use App\Models\RegionalPartner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SlugService
{
    /**
     * Erzeugt alle Slugs für ein Event und speichert sie in event_slug.
     * Bestehende Slugs für dieses Event + Saison werden zuvor gelöscht.
     *
     * Gibt ein Array aller erzeugten Slugs zurück, nach is_primary sortiert.
     */
    public function generateForEvent(Event $event): array
    {
        $seasonId = $event->season;
        $partner  = RegionalPartner::find($event->regional_partner);

        if (!$partner) {
            Log::warning("SlugService: Kein RegionalPartner für Event {$event->id}");
            return [];
        }

        // Basis-Präfixe ermitteln
        [$baseLong, $baseShort] = $this->resolvePrefixes($partner);

        // Programm-Suffixe bestimmen
        $slugDefs = $this->buildSlugDefinitions($event, $baseLong, $baseShort);

        if (empty($slugDefs)) {
            Log::warning("SlugService: Keine Slug-Definitionen für Event {$event->id}");
            return [];
        }

        // Alte Slugs für dieses Event + Saison löschen
        EventSlug::where('event_id', $event->id)
            ->where('season_id', $seasonId)
            ->delete();

        $saved = [];

        foreach ($slugDefs as $def) {
            // Konflikt-Check: existiert der Slug bereits für eine andere event_id in dieser Saison?
            $conflict = EventSlug::where('slug', $def['slug'])
                ->where('season_id', $seasonId)
                ->where('event_id', '!=', $event->id)
                ->first();

            if ($conflict) {
                Log::warning("SlugService: Slug-Konflikt für \"{$def['slug']}\" (Event {$event->id} vs. {$conflict->event_id}) in Saison {$seasonId}. Slug wird übersprungen.");
                continue;
            }

            $record = EventSlug::updateOrCreate(
                [
                    'slug'      => $def['slug'],
                    'season_id' => $seasonId,
                ],
                [
                    'event_id'   => $event->id,
                    'program'    => $def['program'],
                    'variant'    => $def['variant'],
                    'is_primary' => $def['is_primary'],
                ]
            );

            $saved[] = $record->toArray();
        }

        // slug_long / slug_short auf regional_partner persistieren, falls noch nicht gesetzt
        $this->persistPrefixesOnPartner($partner, $baseLong, $baseShort);

        return $saved;
    }

    /**
     * Löscht alle Slugs eines Events für eine Saison.
     */
    public function deleteForEvent(int $eventId, int $seasonId): void
    {
        EventSlug::where('event_id', $eventId)
            ->where('season_id', $seasonId)
            ->delete();
    }

    /**
     * Gibt alle Slugs für ein Event zurück (aktuelle Saison).
     */
    public function getSlugsForEvent(int $eventId, int $seasonId): array
    {
        return EventSlug::where('event_id', $eventId)
            ->where('season_id', $seasonId)
            ->orderByDesc('is_primary')
            ->orderBy('variant')   // long vor short
            ->orderBy('program')
            ->get()
            ->toArray();
    }

    /**
     * Findet ein Event anhand eines Slugs in der aktuellen Saison.
     * Gibt das Event zurück oder null.
     */
    public function findEventBySlug(string $slug, int $seasonId): ?Event
    {
        $record = EventSlug::where('slug', $slug)
            ->where('season_id', $seasonId)
            ->first();

        if (!$record) {
            return null;
        }

        return Event::find($record->event_id);
    }

    /**
     * Gibt den primären Slug eines Events zurück oder null.
     */
    public function getPrimarySlug(int $eventId, int $seasonId): ?string
    {
        $record = EventSlug::where('event_id', $eventId)
            ->where('season_id', $seasonId)
            ->where('is_primary', true)
            ->first();

        return $record?->slug;
    }

    // -------------------------------------------------------------------------
    // Interne Hilfsmethoden
    // -------------------------------------------------------------------------

    /**
     * Ermittelt [slug_long, slug_short] für einen RegionalPartner.
     * Prio: DB-Wert → KFZ-Lookup → Ableitung aus Name.
     */
    private function resolvePrefixes(RegionalPartner $partner): array
    {
        $baseLong  = $partner->slug_long  ?: $this->sanitize($partner->name);
        $baseShort = $partner->slug_short ?: $this->lookupKfz($partner->name) ?: $this->deriveShort($baseLong);

        return [$baseLong, $baseShort];
    }

    /**
     * Baut die Liste aller zu erzeugenden Slugs für ein Event.
     *
     * Regeln:
     *  - Level 1: explore/challenge/future nach gesetzten event_*-Feldern
     *             Wenn mehr als ein Programm → auch "joint" Slugs ohne Suffix
     *  - Level 2: Quali → challenge only, Präfix "quali-"
     *  - Level 3: Finale → fester Slug "finale"
     */
    private function buildSlugDefinitions(Event $event, string $baseLong, string $baseShort): array
    {
        $level = (int) $event->level;

        return match ($level) {
            1       => $this->level1Slugs($event, $baseLong, $baseShort),
            2       => $this->level2Slugs($baseLong, $baseShort),
            3       => $this->level3Slugs(),
            default => [],
        };
    }

    /**
     * Level 1 (Regional): pro Programm ein Slug-Paar (long+short),
     * bei mehreren Programmen zusätzlich joint Slugs.
     */
    private function level1Slugs(Event $event, string $baseLong, string $baseShort): array
    {
        $programs = $this->detectPrograms($event);

        if (empty($programs)) {
            // Kein Programm gesetzt → generischen Slug ohne Suffix erzeugen
            return [
                $this->def("{$baseLong}",        $baseShort,        'joint', 'long',  true),
                $this->def("{$baseShort}",        $baseShort,        'joint', 'short', false),
            ];
        }

        $isJoint = count($programs) > 1;
        $defs    = [];

        // Joint-Slugs (ohne Programm-Suffix) wenn mehrere Programme
        if ($isJoint) {
            $defs[] = $this->def($baseLong,  $baseShort, 'joint', 'long',  true);
            $defs[] = $this->def($baseShort, $baseShort, 'joint', 'short', false);
        }

        foreach ($programs as $program) {
            $suffix      = $this->programSuffix($program);     // "-explore"
            $shortSuffix = $this->programShortSuffix($program); // "e"

            $isPrimary = !$isJoint && count($defs) === 0; // Erster programm-slug ist primary wenn kein joint

            $defs[] = $this->def("{$baseLong}{$suffix}",         $baseShort, $program, 'long',  $isPrimary);
            $defs[] = $this->def("{$baseShort}{$shortSuffix}",   $baseShort, $program, 'short', false);
        }

        return $defs;
    }

    /**
     * Level 2 (Quali): nur Challenge, Präfix "quali-".
     */
    private function level2Slugs(string $baseLong, string $baseShort): array
    {
        return [
            $this->def("quali-{$baseLong}", $baseShort, 'challenge', 'long',  true),
            $this->def("q{$baseShort}",     $baseShort, 'challenge', 'short', false),
        ];
    }

    /**
     * Level 3 (Finale): fester Slug, keine Region.
     */
    private function level3Slugs(): array
    {
        return [
            $this->def('finale',   '', 'challenge', 'long',  true),
            $this->def('finale-c', '', 'challenge', 'short', false),
        ];
    }

    /**
     * Welche Programme hat das Event? Gibt Array aus 'explore', 'challenge', 'future' zurück.
     */
    private function detectPrograms(Event $event): array
    {
        $programs = [];

        if (!empty($event->event_explore))   $programs[] = 'explore';
        if (!empty($event->event_challenge)) $programs[] = 'challenge';
        // future: Spalte event_future, noch nicht implementiert
        // if (!empty($event->event_future)) $programs[] = 'future';

        return $programs;
    }

    private function programSuffix(string $program): string
    {
        return match ($program) {
            'explore'   => '-explore',
            'challenge' => '-challenge',
            'future'    => '-future',
            default     => '',
        };
    }

    private function programShortSuffix(string $program): string
    {
        return match ($program) {
            'explore'   => 'e',
            'challenge' => 'c',
            'future'    => 'f',
            default     => '',
        };
    }

    /**
     * Hilfsmethode: Slug-Definition als Array.
     */
    private function def(string $slug, string $baseShort, string $program, string $variant, bool $isPrimary): array
    {
        return [
            'slug'       => $this->sanitize($slug),
            'program'    => $program,
            'variant'    => $variant,
            'is_primary' => $isPrimary,
        ];
    }

    /**
     * Schlägt den KFZ-Code für einen Städtenamen nach.
     * Normalisiert den Namen vor dem Lookup (Umlaute, Groß-/Kleinschreibung).
     */
    private function lookupKfz(string $cityName): ?string
    {
        $kfz = config('kfz');

        if (empty($kfz)) {
            return null;
        }

        // Direkte Suche
        if (isset($kfz[$cityName])) {
            return strtolower($kfz[$cityName]);
        }

        // Case-insensitive Suche
        foreach ($kfz as $name => $code) {
            if (mb_strtolower($name) === mb_strtolower($cityName)) {
                return strtolower($code);
            }
        }

        // Suche nach dem ersten Wort (bei Namen wie "Frankfurt am Main")
        $firstWord = explode(' ', trim($cityName))[0];
        foreach ($kfz as $name => $code) {
            if (mb_strtolower(explode(' ', $name)[0]) === mb_strtolower($firstWord)) {
                return strtolower($code);
            }
        }

        return null;
    }

    /**
     * Leitet einen kurzen Slug aus dem langen ab (erste 1–3 Buchstaben bis zum ersten Bindestrich).
     * Fallback wenn kein KFZ-Kennzeichen gefunden.
     */
    private function deriveShort(string $slugLong): string
    {
        $parts = explode('-', $slugLong);
        return mb_substr($parts[0], 0, 3);
    }

    /**
     * Bereinigt einen String zu einem URL-sicheren Slug.
     */
    public function sanitize(string $value): string
    {
        $value = trim(mb_strtolower($value));

        $replacements = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            'ß' => 'ss',
            '/' => '-',
            ' ' => '-',
            '_' => '-',
        ];
        $value = str_replace(array_keys($replacements), array_values($replacements), $value);

        // Mehrfache Bindestriche reduzieren
        $value = preg_replace('/-+/', '-', $value);
        $value = trim($value, '-');

        return $value;
    }

    /**
     * Speichert slug_long/slug_short auf dem Partner, wenn noch nicht gesetzt.
     */
    private function persistPrefixesOnPartner(RegionalPartner $partner, string $baseLong, string $baseShort): void
    {
        $changed = false;

        if (empty($partner->slug_long)) {
            $partner->slug_long = $baseLong;
            $changed = true;
        }

        if (empty($partner->slug_short)) {
            $partner->slug_short = $baseShort;
            $changed = true;
        }

        if ($changed) {
            $partner->save();
        }
    }
}
