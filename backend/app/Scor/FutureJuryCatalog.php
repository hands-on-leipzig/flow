<?php

namespace App\Scor;

/**
 * Starting jury sheet for Future, taken from the Contao evaluation tool.
 * Flow-Admins edit rows and labels afterwards. The four columns stay fixed.
 */
final class FutureJuryCatalog
{
    public const CORE_VALUES_NOTE = 'Diese Punkte ergeben sich aus den Kriterien für die Forschung und das Roboterdesign, die auch in die Bewertung der Grundwerte einfließen.';

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
                'title' => 'Forschung',
                'kind' => 'rubric',
                'note' => 'In jeder Zeile eine Stufe ankreuzen. Bei Herausragend einen kurzen Kommentar eintragen.',
                'items' => [
                    self::row('Benennen', 'Das Team definierte ein Problem klar und recherchierte gründlich.', [
                        'Problem nicht klar definiert',
                        'Problem teilweise klar definiert',
                        'Klare Definition des Problems',
                    ], false),
                    self::row('Benennen', 'Das Team definierte ein Problem klar und recherchierte gründlich.', [
                        'Wenige Hinweise auf Recherche',
                        'Einige Hinweise auf Recherchen bei einer oder mehreren Quellen',
                        'Klare, detaillierte Recherche bei vielfältigen Quellen',
                    ], true),
                    self::row('Entwickeln', 'Das Team arbeitete gemeinsam an der Erstellung eines Projektplans und der Entwicklung seiner Ideen.', [
                        'Wenige Hinweise auf einen effektiven Projektplan',
                        'Einige Hinweise auf einen effektiven Projektplan',
                        'Klare Hinweise auf einen effektiven Projektplan',
                    ], false),
                    self::row('Entwickeln', 'Das Team arbeitete gemeinsam an der Erstellung eines Projektplans und der Entwicklung seiner Ideen.', [
                        'Wenige Hinweise, dass alle Teammitglieder am Entwicklungsprozess beteiligt waren',
                        'Einige Hinweise, dass alle Teammitglieder am Entwicklungsprozess beteiligt waren',
                        'Klare Hinweise, dass alle Teammitglieder am Entwicklungsprozess beteiligt waren',
                    ], true),
                    self::row('Erstellen', 'Das Team entwickelte eine eigene Idee oder baute auf einer bestehenden auf. Mit einem Prototypmodell oder einer Zeichnung veranschaulichte das Team die Lösung.', [
                        'Wenig Erläuterung zur Innovation der Lösung',
                        'Einfache Erläuterung zur Innovation der Lösung',
                        'Detaillierte Erläuterung zur Innovation der Lösung',
                    ], true),
                    self::row('Erstellen', 'Das Team entwickelte eine eigene Idee oder baute auf einer bestehenden auf. Mit einem Prototypmodell oder einer Zeichnung veranschaulichte das Team die Lösung.', [
                        'Unklares Modell/Zeichnung zur Darstellung der Lösung',
                        'Einfaches Modell/Zeichnung zur Darstellung der Lösung',
                        'Detailliertes Modell/Zeichnung zur Darstellung der Lösung',
                    ], false),
                    self::row('Iteration', 'Das Team teilte seine Ideen mit anderen, sammelte Feedback und integrierte Verbesserungen in seine Lösung.', [
                        'Lösung wurde kaum anderen gezeigt',
                        'Lösung wurde mindestens einer Person/Gruppe gezeigt',
                        'Lösung wurde mehreren Personen/Gruppen gezeigt',
                    ], false),
                    self::row('Iteration', 'Das Team teilte seine Ideen mit anderen, sammelte Feedback und integrierte Verbesserungen in seine Lösung.', [
                        'Wenige Hinweise auf Verbesserungen aufgrund von Feedback',
                        'Einige Hinweise auf Verbesserungen aufgrund von Feedback',
                        'Klare Hinweise auf Verbesserungen aufgrund von Feedback',
                    ], false),
                    self::row('Mitteilen', 'Das Team präsentierte seine Lösung überzeugend, zeigte deren Wirkung auf andere und feierte seinen Fortschritt.', [
                        'Unklare Erläuterung der Lösung und der möglichen Wirkung auf andere',
                        'Teilweise klare Erläuterung der Lösung und der möglichen Wirkung auf andere',
                        'Klare Erläuterung der Lösung und der möglichen Wirkung auf andere',
                    ], true),
                    self::row('Mitteilen', 'Das Team präsentierte seine Lösung überzeugend, zeigte deren Wirkung auf andere und feierte seinen Fortschritt.', [
                        'Präsentation zeigt nur wenig Stolz oder Begeisterung für die eigene Arbeit',
                        'Präsentation zeigt teilweise Stolz oder Begeisterung für die eigene Arbeit',
                        'Präsentation zeigt klar Stolz oder Begeisterung für die eigene Arbeit',
                    ], true),
                ],
            ],
            [
                'title' => 'Roboterdesign',
                'kind' => 'rubric',
                'note' => 'In jeder Zeile eine Stufe ankreuzen. Bei Herausragend einen kurzen Kommentar eintragen.',
                'items' => [
                    self::row('Benennen', 'Das Team wählte zu lösende Aufgaben aus, erkundete Konstruier- und Programmierressourcen und suchte bei Bedarf Rat.', [
                        'Wenige Hinweise auf eine Aufgabenstrategie',
                        'Einige Hinweise auf eine Aufgabenstrategie',
                        'Klare Hinweise auf eine Aufgabenstrategie',
                    ], false),
                    self::row('Benennen', 'Das Team wählte zu lösende Aufgaben aus, erkundete Konstruier- und Programmierressourcen und suchte bei Bedarf Rat.', [
                        'Wenig Nutzung von Konstruier- oder Programmierressourcen',
                        'Einige Nutzung von Konstruier- oder Programmierressourcen',
                        'Klare Nutzung von Konstruier- oder Programmierressourcen zur Unterstützung ihrer Aufgabenstrategie',
                    ], true),
                    self::row('Entwickeln', 'Die Teammitglieder arbeiteten gemeinsam an ihrem Design und entwickelten die erforderlichen Konstruier- und Programmierfähigkeiten.', [
                        'Wenige Hinweise, dass alle Teammitglieder Ideen eingebracht haben',
                        'Einige Hinweise, dass alle Teammitglieder Ideen eingebracht haben',
                        'Klare Hinweise, dass alle Teammitglieder Ideen eingebracht haben',
                    ], true),
                    self::row('Entwickeln', 'Die Teammitglieder arbeiteten gemeinsam an ihrem Design und entwickelten die erforderlichen Konstruier- und Programmierfähigkeiten.', [
                        'Wenige Hinweise auf Konstruier- und Programmierfähigkeiten aller Teammitglieder',
                        'Einige Hinweise auf Konstruier- und Programmierfähigkeiten aller Teammitglieder',
                        'Klare Hinweise auf Konstruier- und Programmierfähigkeiten aller Teammitglieder',
                    ], false),
                    self::row('Erstellen', 'Das Team entwickelte eigene Designs oder verbesserte bestehende Designs entsprechend ihrer Aufgabenstrategie.', [
                        'Unklare Erläuterung der Anbauten und ihres Zwecks',
                        'Einfache Erläuterung der Anbauten und ihres Zwecks',
                        'Klare Erläuterung innovativer Anbauten und ihres Zwecks',
                    ], false),
                    self::row('Erstellen', 'Das Team entwickelte eigene Designs oder verbesserte bestehende Designs entsprechend ihrer Aufgabenstrategie.', [
                        'Unklare Erläuterung der Verwendung von Code und/oder Sensoren',
                        'Einfache Erläuterung der Verwendung von Code und/oder Sensoren',
                        'Klare Erläuterung der innovativen Verwendung von Code und/oder Sensoren',
                    ], false),
                    self::row('Iteration', 'Das Team testete mehrfach seinen Roboter und den Code, um Verbesserungsmöglichkeiten zu identifizieren und die Erkenntnisse in seine Lösungen einzuarbeiten.', [
                        'Kaum Hinweise, dass Roboter und Code getestet wurden',
                        'Einige Hinweise, dass Roboter und Code getestet wurden',
                        'Klare Hinweise, dass Roboter und Code wiederholt getestet wurden',
                    ], false),
                    self::row('Iteration', 'Das Team testete mehrfach seinen Roboter und den Code, um Verbesserungsmöglichkeiten zu identifizieren und die Erkenntnisse in seine Lösungen einzuarbeiten.', [
                        'Wenige Hinweise auf Verbesserungen aufgrund von Tests',
                        'Einige Hinweise auf Verbesserungen aufgrund von Tests',
                        'Klare Hinweise auf Verbesserungen aufgrund von Tests',
                    ], true),
                    self::row('Mitteilen', 'Das Team erläuterte überzeugend, was es beim Roboterdesign-Prozess gelernt hat, und wie es seine Fortschritte gefeiert hat.', [
                        'Unklare Erläuterung des Prozesses und der Erkenntnisse',
                        'Einfache Erläuterung des Prozesses und der Erkenntnisse',
                        'Detaillierte Erläuterung des Prozesses und der Erkenntnisse',
                    ], true),
                    self::row('Mitteilen', 'Das Team erläuterte überzeugend, was es beim Roboterdesign-Prozess gelernt hat, und wie es seine Fortschritte gefeiert hat.', [
                        'Das Team zeigt nur wenig Stolz oder Begeisterung für die eigene Arbeit',
                        'Das Team zeigt teilweise Stolz oder Begeisterung für die eigene Arbeit',
                        'Das Team zeigt klar Stolz oder Begeisterung für die eigene Arbeit',
                    ], true),
                ],
            ],
            self::feedback('Feedback Forschung'),
            self::feedback('Feedback Roboterdesign'),
            self::feedback('Feedback Grundwerte'),
        ];
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, mixed>
     */
    /**
     * @return array{title: string, kind: string, note: string, items: list<array<string, mixed>>}
     */
    private static function feedback(string $title): array
    {
        return [
            'title' => $title,
            'kind' => 'feedback',
            'note' => 'Pflicht für diese Kategorie, mindestens 20 Zeichen je Feld.',
            'items' => [
                ['item_type' => 'text', 'label' => 'Das war gut:', 'hint' => null, 'core_value' => false, 'config' => null],
                ['item_type' => 'text', 'label' => 'Das könnt ihr verbessern:', 'hint' => null, 'core_value' => false, 'config' => null],
            ],
        ];
    }

    private static function row(string $group, string $groupHint, array $cells, bool $coreValue): array
    {
        return [
            'item_type' => 'rubric',
            'label' => $cells[0],
            'hint' => null,
            'core_value' => $coreValue,
            'config' => [
                'group' => $group,
                'group_hint' => $groupHint,
                'cells' => $cells,
            ],
        ];
    }
}
