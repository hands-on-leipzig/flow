<?php

namespace App\Scor;

/**
 * BIOGLOW Future Edition jury sheet.
 * Projekt and Technisches Design follow the season rubric. Gear rows count toward core values as well.
 */
final class FutureJuryCatalog
{
    public const CORE_VALUES_NOTE = 'Kriterien mit diesem Zahnrad fließen sowohl in die Bewertung der Kategorie „Projekt“ bzw. „Technisches Design“ als auch der Kategorie „Grundwerte“ ein. Die Grundwerte machen 25 % der Gesamtwertung für die Kategorie „Champion“ aus und sollten sich während der gesamten Arbeit sowie beim Event zeigen.';

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
                'note' => 'Die Juror:innen kreuzen in jeder Zeile das erreichte Bewertungsniveau an. Erreicht das Team in einem Bewertungsbereich die Stufe HERAUSRAGEND, ergänzt die Jury im dafür vorgesehenen Feld eine Begründung.',
                'items' => [
                    self::mark('ENTDECKEN: Wie setzte sich das Team mit dem Projektpfad auseinander?', 'Das Team erläutert klar, wie die Projektpfade die Entwicklung der Projektidee beeinflusst haben.', false),
                    self::mark('ENTDECKEN: Wie setzte sich das Team mit dem Projektpfad auseinander?', 'Das Team nutzte mehrere relevante Quellen, um sich über das gewählte Thema zu informieren.', true),
                    self::mark('ENTWICKELN: Welche Ideen entwickelte das Team auf der Grundlage seiner Recherche?', 'Das Team erkundete mehrere Ideen und erklärt klar, warum es sich für eine entschieden hat.', false),
                    self::mark('ENTWICKELN: Welche Ideen entwickelte das Team auf der Grundlage seiner Recherche?', 'Das Team erklärt klar, wie es seine Idee Schritt für Schritt weiterentwickelt und verbessert hat – mit Hilfe von Recherche oder Feedback.', false),
                    self::mark('ENTWICKELN: Welche Ideen entwickelte das Team auf der Grundlage seiner Recherche?', 'Das Team erläutert klar den innovativen Ansatz oder die neuartige Idee seines Projekts.', true),
                    self::mark('UMSETZEN: Wie kann die Idee des Teams eine positive Wirkung erzielen?', 'Das Team bestimmt klar die realen Nutzer:innen und die potenzielle Wirkung seiner Idee.', true),
                    self::mark('UMSETZEN: Wie kann die Idee des Teams eine positive Wirkung erzielen?', 'Das Team zeigt klar die notwendigen Schritte zur Umsetzung seiner Idee.', false),
                    self::mark('UMSETZEN: Wie kann die Idee des Teams eine positive Wirkung erzielen?', 'Das Team prüft die Umsetzbarkeit seiner Idee, inklusive Herausforderungen und Kompromissen.', false),
                    self::mark('MITTEILEN: Wie präsentierte das Team seine Projektarbeit?', 'Das Team stellt zur Anschaulichkeit Grafiken, Modelle oder Prototypen bereit.', false),
                    self::mark('MITTEILEN: Wie präsentierte das Team seine Projektarbeit?', 'Das Team erklärt den wertvollen Beitrag aller Mitglieder zur Projektentwicklung.', true),
                ],
            ],
            [
                'title' => 'Technisches Design',
                'kind' => 'rubric',
                'note' => 'Die Juror:innen kreuzen in jeder Zeile das erreichte Bewertungsniveau an. Erreicht das Team in einem Bewertungsbereich die Stufe HERAUSRAGEND, ergänzt die Jury im dafür vorgesehenen Feld eine Begründung.',
                'items' => [
                    self::mark('ERKENNEN: Wie übte das Team und wie erkannte es Verbesserungspotenziale?', 'Das Team erklärt die Nutzung der begleiteten Missionen & Konstruier-/Programmierressourcen.', false),
                    self::mark('ERKENNEN: Wie übte das Team und wie erkannte es Verbesserungspotenziale?', 'Das Team erkannte Verbesserungspotenziale der Werkzeuge, um Designanforderungen abzuleiten.', true),
                    self::mark('ENTWERFEN & ENTWICKELN: Welche Ideen entwickelte das Team um Werkzeuge und Strategie zu verbessern?', 'Das Team entwickelte eine klare Missionsstrategie, die Designentscheidungen bestimmte.', false),
                    self::mark('ENTWERFEN & ENTWICKELN: Welche Ideen entwickelte das Team um Werkzeuge und Strategie zu verbessern?', 'Teamlösungen umfassen eigene mechanische Bauten zur Unterstützung der Missionsstrategie.', false),
                    self::mark('ENTWERFEN & ENTWICKELN: Welche Ideen entwickelte das Team um Werkzeuge und Strategie zu verbessern?', 'Teamlösungen umfassen eigene Programme zur Unterstützung der Missionsstrategie.', false),
                    self::mark('WEITERENTWICKELN: Wie testete und verbesserte das Team seine Entwicklungen?', 'Das Team erkundete mehrere Varianten jedes Rollenwerkzeugs, bevor es eines testete.', false),
                    self::mark('WEITERENTWICKELN: Wie testete und verbesserte das Team seine Entwicklungen?', 'Das Team folgte einem klaren Testprozess und dokumentierte die Ergebnisse.', false),
                    self::mark('WEITERENTWICKELN: Wie testete und verbesserte das Team seine Entwicklungen?', 'Das Team nutzte die Testergebnisse, um die Entwicklungen zu verbessern.', true),
                    self::mark('MITTEILEN: Wie präsentierte das Team seinen Designprozess und seine Strategie?', 'Das Team erklärt klar seine kooperative Strategie zwischen den Rollen und mit anderen Teams.', true),
                    self::mark('MITTEILEN: Wie präsentierte das Team seinen Designprozess und seine Strategie?', 'Das Team erklärt den wertvollen Beitrag aller Mitglieder zum technischen Designprozess.', true),
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
            'note' => 'Lehrer:innen oder Juror:innen geben den Teams schriftliches Feedback, um ihre Leistungen anzuerkennen und Verbesserungsvorschläge zu machen. Pro Feld ist mindestens ein Kommentar erforderlich.',
            'items' => [
                ['item_type' => 'text', 'label' => 'ANERKENNUNG: Das habt ihr toll gemacht…', 'hint' => null, 'core_value' => false, 'config' => null],
                ['item_type' => 'text', 'label' => 'EMPFEHLUNGEN: Denkt als nächstes über Folgendes nach…', 'hint' => null, 'core_value' => false, 'config' => null],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mark(string $question, string $criterion, bool $coreValue): array
    {
        return [
            'item_type' => 'rubric',
            'label' => $criterion,
            'hint' => null,
            'core_value' => $coreValue,
            'config' => [
                'group' => $question,
                'group_hint' => '',
                'prompt' => $criterion,
                'cells' => [],
            ],
        ];
    }
}
