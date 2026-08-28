<?php

namespace App\Support;

final class VolunteerColumnDefinition
{
    /**
     * @param  list<array{key: string, label: string, table?: bool, export?: bool, sortable?: bool}>  $definitions
     * @return list<array{key: string, label: string, sortable: bool}>
     */
    public static function tablePayload(array $definitions): array
    {
        $columns = [];
        foreach ($definitions as $definition) {
            if (! ($definition['table'] ?? false)) {
                continue;
            }

            $columns[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'sortable' => (bool) ($definition['sortable'] ?? false),
            ];
        }

        return $columns;
    }

    /**
     * @param  list<array{key: string, label: string, table?: bool, export?: bool}>  $definitions
     * @return list<string>
     */
    public static function exportLabels(array $definitions): array
    {
        $labels = [];
        foreach ($definitions as $definition) {
            if ($definition['export'] ?? false) {
                $labels[] = $definition['label'];
            }
        }

        return $labels;
    }
}
