<?php

namespace App\Scor;

/**
 * BIOGLOW Future Edition referee sheet, from the 2026–27 mission and rules booklets.
 * Flow-Admins can still edit rows, labels and point values.
 */
final class FutureRefereeCatalog
{
    /**
     * @return list<array{title: string, kind: string, note: string, items: list<array<string, mixed>>}>
     */
    public static function sections(): array
    {
        return [
            [
                'title' => '01 Lebensgemeinschaften',
                'kind' => 'mission',
                'note' => 'Schlüsselarten müssen lose in der Lebensgemeinschaft liegen und kein Material berühren. Die vierte Schlüsselart im jungen Wald zählt nicht als Platzierung.',
                'items' => [
                    self::count(
                        'Schlüsselarten im jungen Wald',
                        'Höchstens 3. Lose, ohne Material zu berühren.',
                        3,
                        20,
                    ),
                    self::count(
                        'Schlüsselarten im großen Baum',
                        'Höchstens 3. Lose, ohne Material zu berühren.',
                        3,
                        20,
                    ),
                    self::count(
                        'Schlüsselarten im hohlen Baum',
                        'Höchstens 3. Lose, ohne Material zu berühren.',
                        3,
                        20,
                    ),
                    self::yesNo(
                        'Bonus invasive Königin',
                        'Mindestens 3 Schlüsselarten im jungen Wald und die invasive Königin auf der gegenüberliegenden Seite ist umgestoßen.',
                        40,
                    ),
                ],
            ],
            [
                'title' => '02 Wurzeln der Erneuerung',
                'kind' => 'mission',
                'note' => 'Ressourcen müssen durch den Baum nach oben befördert werden. Direkt in die Baumkronenkammer gelegte Ressourcen zählen nicht.',
                'items' => [
                    self::count(
                        'Ressourcen im Fuß des großen Baums',
                        'In den Missionen ohne Obergrenze. Der Zähler geht bis 30.',
                        30,
                        5,
                    ),
                    self::count(
                        'Ressourcen in der Baumkronenkammer',
                        'Nur Ressourcen, die durch den Baum nach oben befördert wurden.',
                        15,
                        10,
                    ),
                ],
            ],
            [
                'title' => '03 Höhlenwasserfall',
                'kind' => 'mission',
                'note' => 'Es zählt der Zählerstand am Matchende. Der Zähler steigt nur durch Schlüsselarten, die durch den Wasserfall kommen.',
                'items' => [
                    self::count('Zählerstand am Matchende', null, 50, 5),
                ],
            ],
            [
                'title' => '04 Erwachender Regenwald',
                'kind' => 'mission',
                'note' => '',
                'items' => [
                    self::yesNo('Alle Schlüsselarten sind aus dem Nest freigesetzt', null, 30),
                    self::yesNo('Alle Ressourcen sind aus dem hohlen Baum freigesetzt', null, 20),
                ],
            ],
            [
                'title' => '05 Zentraler Rückzugsort',
                'kind' => 'mission',
                'note' => 'Kooperative Mission. Dieselbe Anzahl auf beide Bögen. Beide Teams bekommen die vollen Punkte.',
                'items' => [
                    self::count(
                        'Schlüsselarten oder Ressourcen vollständig im Rückzugsort',
                        'Vollständig innerhalb der Fläche, Linien zählen mit.',
                        40,
                        5,
                    ),
                ],
            ],
            [
                'title' => 'Bonus invasive Arten',
                'kind' => 'mission',
                'note' => 'Die invasive Königin ist Pflicht und gibt für das Hinzufügen keine Punkte.',
                'items' => [
                    self::count(
                        'Hinzugefügte invasive Arten',
                        'Ohne die Königin. Vor dem Match eingesetzt.',
                        5,
                        20,
                    ),
                    self::count(
                        'Invasive Arten in der Eindämmungszone',
                        'Einschließlich der Königin, wenn sie dort liegt.',
                        6,
                        10,
                    ),
                ],
            ],
            [
                'title' => 'Strafen',
                'kind' => 'mission',
                'note' => 'Erste Beeinträchtigung 10 Punkte Abzug, zweite weitere 20. Die dritte setzt das gesamte Matchergebnis auf 0. Das setzt der Bogen noch nicht von allein.',
                'items' => [
                    self::choice('Beeinträchtigungen in diesem Match', null, [
                        ['label' => 'Keine', 'points' => 0],
                        ['label' => 'Erste', 'points' => -10],
                        ['label' => 'Zweite', 'points' => -30],
                    ]),
                ],
            ],
            [
                'title' => 'Freundliches Miteinander',
                'kind' => 'mission',
                'note' => 'Kein Erscheinen gibt 0. Die weiteren Stufen stehen nicht in den Spielmissionen und Spielregeln.',
                'items' => [
                    self::choice('Bewertung für dieses Match', null, [
                        ['label' => 'Kein Erscheinen', 'points' => 0],
                    ], optional: true),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function count(string $label, ?string $hint, int $max, int $pointsEach): array
    {
        return [
            'item_type' => 'count',
            'label' => $label,
            'hint' => $hint,
            'core_value' => false,
            'config' => ['max' => $max, 'points_each' => $pointsEach],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function yesNo(string $label, ?string $hint, int $yesPoints): array
    {
        return [
            'item_type' => 'yes_no',
            'label' => $label,
            'hint' => $hint,
            'core_value' => false,
            'config' => [
                'yes_label' => 'Ja',
                'yes_points' => $yesPoints,
                'no_label' => 'Nein',
                'no_points' => 0,
            ],
        ];
    }

    /**
     * @param  list<array{label: string, points: int}>  $options
     * @return array<string, mixed>
     */
    private static function choice(string $label, ?string $hint, array $options, bool $optional = false): array
    {
        return [
            'item_type' => 'choice',
            'label' => $label,
            'hint' => $hint,
            'core_value' => false,
            'config' => ['options' => $options, 'optional' => $optional],
        ];
    }
}
