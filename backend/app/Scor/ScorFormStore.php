<?php

namespace App\Scor;

use App\Enums\FirstProgram;
use Illuminate\Support\Facades\DB;

final class ScorFormStore
{
    public function ensure(int $seasonId): void
    {
        $program = FirstProgram::FUTURE_8->value;
        $this->ensureKind($seasonId, $program, 'jury', FutureJuryCatalog::sections());
        $this->ensureKind($seasonId, $program, 'referee', FutureRefereeCatalog::sections());
    }

    /**
     * @return array{season: int, forms: list<array<string, mixed>>}
     */
    public function document(int $seasonId): array
    {
        $this->ensure($seasonId);

        $forms = DB::table('scor_form')
            ->where('season', $seasonId)
            ->where('first_program', FirstProgram::FUTURE_8->value)
            ->orderBy('kind')
            ->get();

        return [
            'season' => $seasonId,
            'columns' => FutureJuryCatalog::columns(),
            'forms' => $forms->map(fn ($form) => $this->formPayload($form))->values()->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    public function replace(int $formId, array $sections): array
    {
        $form = DB::table('scor_form')->where('id', $formId)->first();
        if (!$form || (int) $form->first_program !== FirstProgram::FUTURE_8->value) {
            throw new ScorAccessException('Bogen nicht gefunden.', 404);
        }

        DB::transaction(function () use ($form, $sections) {
            $sectionIds = DB::table('scor_section')->where('form', $form->id)->pluck('id');
            if ($sectionIds->isNotEmpty()) {
                DB::table('scor_item')->whereIn('section', $sectionIds)->delete();
                DB::table('scor_section')->where('form', $form->id)->delete();
            }

            foreach (array_values($sections) as $sectionIndex => $section) {
                $kind = (string) ($section['kind'] ?? '');
                $this->assertSectionKind((string) $form->kind, $kind);
                $sectionId = DB::table('scor_section')->insertGetId([
                    'form' => $form->id,
                    'sequence' => $sectionIndex,
                    'title' => trim((string) ($section['title'] ?? '')),
                    'kind' => $kind,
                    'note' => trim((string) ($section['note'] ?? '')) ?: null,
                ]);

                foreach (array_values($section['items'] ?? []) as $itemIndex => $item) {
                    $type = (string) ($item['item_type'] ?? '');
                    $this->assertItemType((string) $form->kind, $kind, $type);
                $config = $this->encodeConfig($type, $item['config'] ?? null);
                $label = trim((string) ($item['label'] ?? ''));
                if ($type === 'rubric' && is_array($item['config']['cells'] ?? null)) {
                    $label = trim((string) ($item['config']['cells'][0] ?? $label));
                }
                DB::table('scor_item')->insert([
                    'section' => $sectionId,
                    'sequence' => $itemIndex,
                    'item_type' => $type,
                    'label' => $label !== '' ? $label : null,
                        'hint' => trim((string) ($item['hint'] ?? '')) ?: null,
                    'core_value' => (bool) ($item['core_value'] ?? false),
                    'config' => $config,
                ]);
                }
            }
        });

        return $this->formPayload(DB::table('scor_form')->where('id', $formId)->first());
    }

    public function formId(int $seasonId, string $kind): int
    {
        $this->ensure($seasonId);
        $id = DB::table('scor_form')
            ->where('season', $seasonId)
            ->where('first_program', FirstProgram::FUTURE_8->value)
            ->where('kind', $kind)
            ->value('id');

        if (!$id) {
            throw new ScorAccessException('Bogen nicht gefunden.', 404);
        }

        return (int) $id;
    }

    /**
     * @return list<object>
     */
    public function itemsFor(int $formId): array
    {
        return DB::table('scor_item')
            ->join('scor_section', 'scor_section.id', '=', 'scor_item.section')
            ->where('scor_section.form', $formId)
            ->orderBy('scor_section.sequence')
            ->orderBy('scor_item.sequence')
            ->get([
                'scor_item.*',
                'scor_section.id as section_id',
                'scor_section.title as section_title',
                'scor_section.kind as section_kind',
                'scor_section.note as section_note',
                'scor_section.sequence as section_sequence',
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function ensureKind(int $seasonId, int $program, string $kind, array $sections): void
    {
        $form = DB::table('scor_form')
            ->where('season', $seasonId)
            ->where('first_program', $program)
            ->where('kind', $kind)
            ->first();
        if (!$form) {
            $formId = DB::table('scor_form')->insertGetId([
                'season' => $seasonId,
                'first_program' => $program,
                'kind' => $kind,
            ]);
            if ($sections !== []) {
                $this->replace($formId, $sections);
            }

            return;
        }

        if ($sections === []) {
            return;
        }

        $hasSections = DB::table('scor_section')->where('form', $form->id)->exists();
        if (!$hasSections) {
            $this->replace((int) $form->id, $sections);
        }
    }

    private function formPayload(object $form): array
    {
        $sectionRows = DB::table('scor_section')->where('form', $form->id)->orderBy('sequence')->get();
        $items = $sectionRows->isEmpty()
            ? collect()
            : DB::table('scor_item')->whereIn('section', $sectionRows->pluck('id'))->orderBy('sequence')->get()->groupBy('section');

        $sections = [];
        foreach ($sectionRows as $section) {
            $sections[] = [
                'id' => (int) $section->id,
                'title' => $section->title,
                'kind' => $section->kind,
                'note' => $section->note,
                'items' => ($items[$section->id] ?? collect())->map(fn ($item) => [
                    'id' => (int) $item->id,
                    'item_type' => $item->item_type,
                    'label' => $item->label,
                    'hint' => $item->hint,
                    'core_value' => (bool) $item->core_value,
                    'config' => $this->decodeConfig($item->config),
                ])->values()->all(),
            ];
        }

        return [
            'id' => (int) $form->id,
            'kind' => $form->kind,
            'sections' => $sections,
        ];
    }

    private function assertSectionKind(string $formKind, string $sectionKind): void
    {
        $allowed = $formKind === 'jury' ? ['rubric', 'feedback'] : ['mission'];
        if (!in_array($sectionKind, $allowed, true)) {
            throw new ScorAccessException('Dieser Abschnitt passt nicht zum Bogen.', 422);
        }
    }

    private function assertItemType(string $formKind, string $sectionKind, string $type): void
    {
        if ($formKind === 'jury' && $sectionKind === 'feedback' && $type === 'text') {
            return;
        }
        if ($formKind === 'jury' && $sectionKind === 'rubric' && $type === 'rubric') {
            return;
        }
        if ($formKind === 'referee' && in_array($type, ['yes_no', 'choice', 'count'], true)) {
            return;
        }

        throw new ScorAccessException('Dieser Fragetyp passt nicht zum Bogen.', 422);
    }

    private function encodeConfig(string $type, mixed $config): ?string
    {
        if (!is_array($config)) {
            return null;
        }

        if ($type === 'rubric') {
            $cells = array_values(array_map(fn ($cell) => trim((string) $cell), $config['cells'] ?? []));
            $cells = array_pad(array_slice($cells, 0, 3), 3, '');

            return json_encode([
                'group' => trim((string) ($config['group'] ?? '')),
                'group_hint' => trim((string) ($config['group_hint'] ?? '')),
                'cells' => $cells,
            ], JSON_UNESCAPED_UNICODE);
        }

        if ($type === 'yes_no') {
            return json_encode([
                'yes_label' => trim((string) ($config['yes_label'] ?? 'Ja')) ?: 'Ja',
                'yes_points' => (int) ($config['yes_points'] ?? 0),
                'no_label' => trim((string) ($config['no_label'] ?? 'Nein')) ?: 'Nein',
                'no_points' => (int) ($config['no_points'] ?? 0),
            ], JSON_UNESCAPED_UNICODE);
        }

        if ($type === 'choice') {
            $options = [];
            foreach ($config['options'] ?? [] as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $label = trim((string) ($option['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $options[] = ['label' => $label, 'points' => (int) ($option['points'] ?? 0)];
            }
            if ($options === []) {
                throw new ScorAccessException('Eine Auswahl braucht mindestens eine Beschriftung.', 422);
            }

            return json_encode(['options' => $options], JSON_UNESCAPED_UNICODE);
        }

        if ($type === 'count') {
            return json_encode([
                'max' => max(0, (int) ($config['max'] ?? 0)),
                'points_each' => (int) ($config['points_each'] ?? 0),
            ], JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    private function decodeConfig(mixed $config): ?array
    {
        if (is_array($config)) {
            return $config;
        }
        if (!is_string($config) || $config === '') {
            return null;
        }
        $decoded = json_decode($config, true);

        return is_array($decoded) ? $decoded : null;
    }
}
