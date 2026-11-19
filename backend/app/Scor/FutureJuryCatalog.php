<?php

namespace App\Scor;

/**
 * BIOGLOW Future Edition jury sheet.
 * Projekt and Technisches Design follow the season rubric. Gear rows count toward core values as well.
 */
final class FutureJuryCatalog
{
    public const CORE_VALUES_NOTE = 'Kriterien mit diesem Zahnrad zählen sowohl für Projekt bzw. Technisches Design als auch für die Grundwerte. Die Grundwerte machen 25 % der Champion-Wertung aus und sollen in der gesamten Arbeit und beim Event sichtbar sein.';

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function columns(): array
    {
        return [
            ['value' => 1, 'label' => 'Ausbaufähig'],
            ['value' => 2, 'label' => 'Gut'],
            ['value' => 3, 'label' => 'Sehr gut'],
            ['value' => 4, 'label' => 'Herausragend'],
        ];
    }

    /**
     * @return list<array{title: string, kind: string, note: string, items: list<array<string, mixed>>}>
     */
    public static function sections(): array
    {
        return [
            [
                'title' => 'Projekt',
                'kind' => 'rubric',
                'note' => 'In jeder Zeile eine Stufe ankreuzen. Bei Herausragend einen kurzen Kommentar eintragen.',
                'items' => [
                    self::mark('Erforschen', 'Wie recherchiert das Team den Projektpfad?', 'Das Team erklärt klar, wie die angeleitete Recherche die Richtung des Projekts beeinflusst hat.', false),
                    self::mark('Erforschen', 'Wie recherchiert das Team den Projektpfad?', 'Das Team nutzt mehrere passende Quellen, um den gewählten Projektpfad kennenzulernen.', true),
                    self::mark('Ideen entwickeln', 'Welche Ideen entwickelt das Team aus der Recherche?', 'Das Team hat mehrere Ideen untersucht und erklärt klar, warum es diese Auswahl getroffen hat.', false),
                    self::mark('Ideen entwickeln', 'Welche Ideen entwickelt das Team aus der Recherche?', 'Das Team erklärt klar, wie es die Idee anhand von Recherche oder Feedback weiterentwickelt und verbessert hat.', false),
                    self::mark('Ideen entwickeln', 'Welche Ideen entwickelt das Team aus der Recherche?', 'Das Team erklärt klar den innovativen Ansatz oder das eigenständige Denken in der Projektidee.', true),
                    self::mark('Umsetzen', 'Wie wirkt die Idee des Teams positiv?', 'Das Team benennt klar, wen die Idee in der Praxis betrifft und welche Wirkung sie haben kann.', true),
                    self::mark('Umsetzen', 'Wie wirkt die Idee des Teams positiv?', 'Das Team benennt klar die Schritte, die zur Umsetzung der Idee nötig sind.', false),
                    self::mark('Umsetzen', 'Wie wirkt die Idee des Teams positiv?', 'Das Team berücksichtigt, ob die Idee umsetzbar ist, einschließlich Herausforderungen und Abwägungen.', false),
                    self::mark('Mitteilen', 'Wie stellt das Team seine Projektarbeit vor?', 'Das Team nutzt klare Bilder, Modelle oder Prototypen in der Projektpräsentation.', false),
                    self::mark('Mitteilen', 'Wie stellt das Team seine Projektarbeit vor?', 'Das Team erklärt, wie alle Mitglieder sinnvoll an der Entwicklung des Projekts mitwirken.', true),
                ],
            ],
            [
                'title' => 'Technisches Design',
                'kind' => 'rubric',
                'note' => 'In jeder Zeile eine Stufe ankreuzen. Bei Herausragend einen kurzen Kommentar eintragen.',
                'items' => [
                    self::mark('Erkennen', 'Wie übt das Team, und wo erkennt es Verbesserungen?', 'Das Team erklärt, wie es die angeleiteten Missionen und die Bau- und Programmierhilfen genutzt hat.', false),
                    self::mark('Erkennen', 'Wie übt das Team, und wo erkennt es Verbesserungen?', 'Das Team erkennt Verbesserungsmöglichkeiten an den vorgegebenen Werkzeugen und leitet daraus Anforderungen an das Design ab.', true),
                    self::mark('Entwerfen und bauen', 'Welche Ideen entwickelt das Team, um Werkzeuge und Strategie zu verbessern?', 'Das Team entwickelt eine klare Spielstrategie, an der sich die Designentscheidungen orientieren.', false),
                    self::mark('Entwerfen und bauen', 'Welche Ideen entwickelt das Team, um Werkzeuge und Strategie zu verbessern?', 'Die Lösungen enthalten eigene mechanische Aufbauten, die zur Spielstrategie passen.', false),
                    self::mark('Entwerfen und bauen', 'Welche Ideen entwickelt das Team, um Werkzeuge und Strategie zu verbessern?', 'Die Lösungen enthalten eigenen Code, der zur Spielstrategie passt.', false),
                    self::mark('Weiterentwickeln', 'Wie testet und verbessert das Team die Entwürfe?', 'Das Team untersucht mehrere Entwürfe für jedes Rollenwerkzeug, bevor es eines zum Testen auswählt.', false),
                    self::mark('Weiterentwickeln', 'Wie testet und verbessert das Team die Entwürfe?', 'Das Team folgt einem klaren Testablauf und dokumentiert die Ergebnisse.', false),
                    self::mark('Weiterentwickeln', 'Wie testet und verbessert das Team die Entwürfe?', 'Das Team nutzt die Testergebnisse, um die Entwürfe zu verbessern.', true),
                    self::mark('Mitteilen', 'Wie stellt das Team den Designprozess und die Strategie vor?', 'Das Team erklärt klar die gemeinsame Spielstrategie zwischen den Rollen und mit dem anderen Team.', true),
                    self::mark('Mitteilen', 'Wie stellt das Team den Designprozess und die Strategie vor?', 'Das Team erklärt, wie alle Mitglieder sinnvoll am Prozess des technischen Designs mitwirken.', true),
                ],
            ],
            self::feedback('Feedback Projekt'),
            self::feedback('Feedback Technisches Design'),
            self::feedback('Feedback Grundwerte'),
        ];
    }

    /**
     * @return array{title: string, kind: string, note: string, items: list<array<string, mixed>>}
     */
    private static function feedback(string $title): array
    {
        return [
            'title' => $title,
            'kind' => 'feedback',
            'note' => 'Mindestens ein Kommentar je Feld. Das Feedback geht am Ende des Events an das Team.',
            'items' => [
                ['item_type' => 'text', 'label' => 'Anerkennung: Das habt ihr gut gemacht …', 'hint' => null, 'core_value' => false, 'config' => null],
                ['item_type' => 'text', 'label' => 'Empfehlung: Als nächsten Schritt denkt darüber nach …', 'hint' => null, 'core_value' => false, 'config' => null],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mark(string $group, string $groupHint, string $prompt, bool $coreValue): array
    {
        return [
            'item_type' => 'rubric',
            'label' => $prompt,
            'hint' => null,
            'core_value' => $coreValue,
            'config' => [
                'group' => $group,
                'group_hint' => $groupHint,
                'prompt' => $prompt,
                'cells' => [],
            ],
        ];
    }
}
