<?php

namespace App\Scor;

use Illuminate\Support\Facades\DB;

/**
 * Blank Future referee sheet for teams. Nothing is stored.
 */
final class ScorPractice
{
    /**
     * @return array{event: array{id: int, name: mixed}, sections: list<array<string, mixed>>}
     */
    public function sheet(int $eventId): array
    {
        $event = DB::table('event')->where('id', $eventId)->first();
        if (!$event) {
            throw new ScorAccessException('Veranstaltung nicht gefunden.', 404);
        }

        return [
            'event' => ['id' => (int) $event->id, 'name' => $event->name],
            'sections' => $this->sections(),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{points: int}
     */
    public function score(array $values): array
    {
        $items = [];
        $answers = ['values' => []];
        foreach ($this->sections() as $section) {
            foreach ($section['items'] as $item) {
                $object = (object) [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'item_type' => $item['item_type'],
                    'config' => $item['config'],
                ];
                $items[] = $object;
                if (array_key_exists($item['id'], $values)) {
                    $answers['values'][$item['id']] = $values[$item['id']];
                }
            }
        }
        FutureRefereeRules::assertLegal($items, $answers);
        $points = 0;
        foreach ($items as $item) {
            $points += $this->points($item, $answers['values'][$item->id] ?? null);
        }

        return ['points' => FutureRefereeRules::finalizePoints($points, $items, $answers)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sections(): array
    {
        $sections = [];
        $n = 1;
        foreach (FutureRefereeCatalog::sections() as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                $items[] = [
                    'id' => 'p'.$n,
                    'item_type' => $item['item_type'],
                    'label' => $item['label'],
                    'hint' => $item['hint'],
                    'config' => $item['config'],
                ];
                $n++;
            }
            $sections[] = [
                'title' => $section['title'],
                'note' => $section['note'],
                'items' => $items,
            ];
        }

        return $sections;
    }

    private function points(object $item, mixed $raw): int
    {
        $config = is_array($item->config) ? $item->config : [];
        if ($raw === null || $raw === '') {
            return 0;
        }

        return match ($item->item_type) {
            'yes_no' => $raw === 'yes' ? (int) ($config['yes_points'] ?? 0) : ($raw === 'no' ? (int) ($config['no_points'] ?? 0) : 0),
            'count' => max(0, min((int) ($config['max'] ?? 0), (int) $raw)) * (int) ($config['points_each'] ?? 0),
            'choice' => (int) ($config['options'][(int) $raw]['points'] ?? 0),
            default => 0,
        };
    }
}
