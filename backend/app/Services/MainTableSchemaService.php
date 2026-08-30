<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Live-DB schema introspection for admin Main Tables CRUD.
 * Never reads Laravel migrations — source of truth is the connected database.
 */
class MainTableSchemaService
{
    private const LABEL_COLUMNS = ['name', 'name_short', 'code', 'display_name'];

    /** @var array<string, list<string>>|null */
    private ?array $mTablesCache = null;

    /**
     * @return list<string>
     */
    public function discoverMTables(): array
    {
        if ($this->mTablesCache !== null) {
            return $this->mTablesCache;
        }

        $databaseName = DB::connection()->getDatabaseName();
        $tables = DB::select('SHOW TABLES');
        $tableKey = "Tables_in_{$databaseName}";

        $mTableNames = [];
        foreach ($tables as $table) {
            $tableName = $table->$tableKey;
            if (str_starts_with($tableName, 'm_')) {
                $mTableNames[] = $tableName;
            }
        }

        sort($mTableNames);
        $this->mTablesCache = $mTableNames;

        return $mTableNames;
    }

    public function isAllowedTable(string $table): bool
    {
        return in_array($table, $this->discoverMTables(), true);
    }

    public function getPrimaryKeyColumn(string $table): string
    {
        $keys = DB::select("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");

        return ! empty($keys) ? $keys[0]->Column_name : 'id';
    }

    /**
     * Full schema payload for the admin UI (columns, PK, outbound FKs with options).
     *
     * @return array{
     *   table: string,
     *   primary_key: string,
     *   columns: list<array<string, mixed>>,
     *   foreign_keys: array<string, array<string, mixed>>
     * }
     */
    public function schema(string $table): array
    {
        $columns = $this->describeColumns($table);
        $primaryKey = $this->getPrimaryKeyColumn($table);
        $foreignKeys = $this->outboundForeignKeys($table);

        return [
            'table' => $table,
            'primary_key' => $primaryKey,
            'columns' => $columns,
            'foreign_keys' => $foreignKeys,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function describeColumns(string $table): array
    {
        $rows = DB::select("SHOW COLUMNS FROM `{$table}`");
        $uniqueColumns = $this->uniqueColumnNames($table);
        $result = [];

        foreach ($rows as $row) {
            $type = (string) $row->Type;
            $extra = (string) ($row->Extra ?? '');
            $nullable = strtoupper((string) $row->Null) === 'YES';
            $enumValues = $this->parseEnumOrSetValues($type);

            $result[] = [
                'name' => $row->Field,
                'sql_type' => $type,
                'nullable' => $nullable,
                'default' => $row->Default,
                'extra' => $extra,
                'key' => $row->Key,
                'auto_increment' => str_contains(strtolower($extra), 'auto_increment'),
                'generated' => str_contains(strtolower($extra), 'generated'),
                'max_length' => $this->parseMaxLength($type),
                'unsigned' => str_contains(strtolower($type), 'unsigned'),
                'enum_values' => $enumValues,
                'is_set' => str_starts_with(strtolower($type), 'set('),
                'is_enum' => str_starts_with(strtolower($type), 'enum('),
                'is_booleanish' => (bool) preg_match('/^tinyint\\(1\\)/i', $type),
                'unique' => in_array($row->Field, $uniqueColumns, true),
                'writable' => ! str_contains(strtolower($extra), 'auto_increment')
                    && ! str_contains(strtolower($extra), 'generated'),
                'restriction' => $this->restrictionLine($row, $uniqueColumns),
            ];
        }

        return $result;
    }

    /**
     * Outbound FKs keyed by local column, with embedded select options.
     *
     * @return array<string, array{table: string, column: string, delete_rule: string, options: list<array{id: mixed, label: string}>}>
     */
    public function outboundForeignKeys(string $table): array
    {
        $databaseName = DB::connection()->getDatabaseName();
        $fks = DB::select(
            'SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE kcu
             JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
              AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
             WHERE kcu.TABLE_SCHEMA = ?
               AND kcu.TABLE_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL',
            [$databaseName, $table]
        );

        $result = [];
        foreach ($fks as $fk) {
            $refTable = $fk->REFERENCED_TABLE_NAME;
            $refColumn = $fk->REFERENCED_COLUMN_NAME;
            $result[$fk->COLUMN_NAME] = [
                'table' => $refTable,
                'column' => $refColumn,
                'delete_rule' => $fk->DELETE_RULE,
                'options' => $this->fkOptions($refTable, $refColumn),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{id: mixed, label: string}>
     */
    public function fkOptions(string $refTable, string $refColumn): array
    {
        try {
            $labelColumn = $this->pickLabelColumn($refTable);
            $rows = DB::table($refTable)->orderBy($refColumn)->get();
            $options = [];
            foreach ($rows as $row) {
                $id = $row->{$refColumn};
                $labelValue = $labelColumn !== null && isset($row->{$labelColumn})
                    ? (string) $row->{$labelColumn}
                    : (string) $id;
                $options[] = [
                    'id' => $id,
                    'label' => "#{$id} — {$labelValue}",
                ];
            }

            return $options;
        } catch (\Throwable $e) {
            Log::warning('FK options failed', [
                'table' => $refTable,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Delete impact for one row: hard-block RESTRICT/NO ACTION; cascade_impact for CASCADE/SET NULL.
     *
     * @return array{can_delete: bool, blockers: list<array<string, mixed>>, cascade_impact: list<array<string, mixed>>}
     */
    public function deleteImpact(string $table, string|int $id): array
    {
        $databaseName = DB::connection()->getDatabaseName();
        $inbound = DB::select(
            'SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME, rc.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE kcu
             JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
              AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
             WHERE kcu.CONSTRAINT_SCHEMA = ?
               AND kcu.REFERENCED_TABLE_NAME = ?',
            [$databaseName, $table]
        );

        $blockers = [];
        $cascadeImpact = [];

        foreach ($inbound as $ref) {
            $count = (int) DB::table($ref->TABLE_NAME)
                ->where($ref->COLUMN_NAME, $id)
                ->count();

            if ($count <= 0) {
                continue;
            }

            $entry = [
                'table' => $ref->TABLE_NAME,
                'column' => $ref->COLUMN_NAME,
                'count' => $count,
                'delete_rule' => $ref->DELETE_RULE,
            ];

            $rule = strtoupper((string) $ref->DELETE_RULE);
            if (in_array($rule, ['RESTRICT', 'NO ACTION'], true)) {
                $blockers[] = $entry;
            } else {
                // CASCADE, SET NULL, SET DEFAULT, etc.
                $cascadeImpact[] = $entry;
            }
        }

        return [
            'can_delete' => empty($blockers),
            'blockers' => $blockers,
            'cascade_impact' => $cascadeImpact,
        ];
    }

    /**
     * Filter and coerce request payload against live schema for insert/update.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function prepareWritePayload(string $table, array $input, bool $isCreate): array
    {
        $columns = $this->describeColumns($table);
        $byName = [];
        foreach ($columns as $col) {
            $byName[$col['name']] = $col;
        }

        $primaryKey = $this->getPrimaryKeyColumn($table);
        $data = [];

        foreach ($input as $key => $value) {
            if (! isset($byName[$key])) {
                return ['ok' => false, 'error' => "Unknown column: {$key}"];
            }

            $col = $byName[$key];

            if (! $col['writable']) {
                continue;
            }

            if ($isCreate && $col['auto_increment']) {
                continue;
            }

            if (! $isCreate && $key === $primaryKey) {
                continue;
            }

            if ($value === '' || $value === null) {
                if ($col['nullable']) {
                    $data[$key] = null;
                    continue;
                }

                if ($this->isStringType($col['sql_type'])) {
                    $data[$key] = '';
                    continue;
                }

                return [
                    'ok' => false,
                    'error' => "Column {$key} is NOT NULL and cannot be empty",
                ];
            }

            if ($col['is_set'] && is_array($value)) {
                $data[$key] = implode(',', $value);
                continue;
            }

            $data[$key] = $value;
        }

        return ['ok' => true, 'data' => $data];
    }

    private function pickLabelColumn(string $table): ?string
    {
        $listing = DB::getSchemaBuilder()->getColumnListing($table);
        foreach (self::LABEL_COLUMNS as $candidate) {
            if (in_array($candidate, $listing, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function uniqueColumnNames(string $table): array
    {
        $indexes = DB::select("SHOW INDEXES FROM `{$table}`");
        $unique = [];
        foreach ($indexes as $index) {
            if ((int) $index->Non_unique === 0 && $index->Key_name !== 'PRIMARY') {
                $unique[] = $index->Column_name;
            }
        }

        return array_values(array_unique($unique));
    }

    /**
     * @return list<string>|null
     */
    private function parseEnumOrSetValues(string $type): ?array
    {
        if (! preg_match('/^(enum|set)\\((.*)\\)$/i', $type, $m)) {
            return null;
        }

        preg_match_all("/'((?:\\\\'|[^'])*)'/", $m[2], $matches);

        return array_map(
            static fn (string $v) => str_replace("\\'", "'", $v),
            $matches[1] ?? []
        );
    }

    private function parseMaxLength(string $type): ?int
    {
        if (preg_match('/^(?:var)?char\\((\\d+)\\)/i', $type, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function isStringType(string $type): bool
    {
        $t = strtolower($type);

        return str_contains($t, 'char')
            || str_contains($t, 'text')
            || str_starts_with($t, 'enum(')
            || str_starts_with($t, 'set(');
    }

    /**
     * @param  list<string>  $uniqueColumns
     */
    private function restrictionLine(object $row, array $uniqueColumns): string
    {
        $parts = [(string) $row->Type];
        $parts[] = strtoupper((string) $row->Null) === 'YES' ? 'NULL' : 'NOT NULL';
        if ($row->Default !== null) {
            $parts[] = 'default '.json_encode($row->Default);
        } elseif (strtoupper((string) $row->Null) === 'YES') {
            $parts[] = 'default NULL';
        }
        if (! empty($row->Extra)) {
            $parts[] = (string) $row->Extra;
        }
        if (in_array($row->Field, $uniqueColumns, true)) {
            $parts[] = 'UNIQUE';
        }

        return implode(' ', $parts);
    }
}
