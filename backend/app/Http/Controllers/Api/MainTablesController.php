<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MainTableSchemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MainTablesController extends Controller
{
    public function __construct(
        private readonly MainTableSchemaService $schemaService,
    ) {}

    /**
     * Get all available main tables with their record counts
     */
    public function index(): JsonResponse
    {
        $tables = $this->schemaService->discoverMTables();

        $result = [];
        foreach ($tables as $table) {
            try {
                $count = DB::table($table)->count();
                $result[] = [
                    'name' => $table,
                    'display_name' => $this->getTableDisplayName($table),
                    'count' => $count,
                ];
            } catch (\Exception $e) {
                Log::error("Error getting count for table {$table}: ".$e->getMessage());
                $result[] = [
                    'name' => $table,
                    'display_name' => $this->getTableDisplayName($table),
                    'count' => 0,
                ];
            }
        }

        return response()->json(['tables' => $result]);
    }

    /**
     * Get record count for a specific table
     */
    public function getCount(string $table): JsonResponse
    {
        if (! $this->schemaService->isAllowedTable($table)) {
            return response()->json(['error' => 'Table not allowed'], 404);
        }

        try {
            $count = DB::table($table)->count();

            return response()->json(['count' => $count]);
        } catch (\Exception $e) {
            Log::error("Error getting count for table {$table}: ".$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Live schema (columns, FKs with options) — never from migrations.
     */
    public function schema(string $table): JsonResponse
    {
        if (! $this->schemaService->isAllowedTable($table)) {
            return response()->json(['error' => 'Table not allowed'], 404);
        }

        try {
            return response()->json($this->schemaService->schema($table));
        } catch (\Exception $e) {
            Log::error("Error getting schema for table {$table}: ".$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all data from a specific table, with per-row delete impact.
     */
    public function getTableData(string $table): JsonResponse
    {
        if (! $this->schemaService->isAllowedTable($table)) {
            return response()->json(['error' => 'Table not allowed'], 404);
        }

        try {
            $primaryKey = $this->schemaService->getPrimaryKeyColumn($table);
            $rows = DB::table($table)->get();
            $data = [];

            foreach ($rows as $row) {
                $arr = (array) $row;
                $id = $arr[$primaryKey] ?? null;
                $impact = $id !== null
                    ? $this->schemaService->deleteImpact($table, $id)
                    : ['can_delete' => false, 'blockers' => [], 'cascade_impact' => []];
                $arr['can_delete'] = $impact['can_delete'];
                $arr['blockers'] = $impact['blockers'];
                $arr['cascade_impact'] = $impact['cascade_impact'];
                $data[] = $arr;
            }

            return response()->json([
                'data' => $data,
                'primary_key' => $primaryKey,
            ]);
        } catch (\Exception $e) {
            Log::error("Error getting data for table {$table}: ".$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get column structure for a table (legacy; prefer /schema).
     */
    public function getTableColumns(string $table): JsonResponse
    {
        if (! $this->schemaService->isAllowedTable($table)) {
            return response()->json(['error' => 'Table not allowed'], 404);
        }

        try {
            $schema = $this->schemaService->schema($table);

            return response()->json([
                'columns' => array_map(static fn ($c) => $c['name'], $schema['columns']),
                'primary_key' => $schema['primary_key'],
            ]);
        } catch (\Exception $e) {
            Log::error("Error getting columns for table {$table}: ".$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a new record in a table
     */
    public function store(Request $request, string $table): JsonResponse
    {
        if (! $this->schemaService->isAllowedTable($table)) {
            return response()->json(['error' => 'Table not allowed'], 404);
        }
        if ($blocked = $this->denyMatchCatalogWrites($table)) {
            return $blocked;
        }

        try {
            $prepared = $this->schemaService->prepareWritePayload($table, $request->all(), true);
            if (! $prepared['ok']) {
                return response()->json(['error' => $prepared['error']], 422);
            }

            $data = $prepared['data'];
            $primaryKey = $this->schemaService->getPrimaryKeyColumn($table);

            if ($primaryKey === 'id') {
                $id = DB::table($table)->insertGetId($data);
                $record = DB::table($table)->where('id', $id)->first();
            } else {
                DB::table($table)->insert($data);
                $record = DB::table($table)->where($primaryKey, $data[$primaryKey] ?? null)->first();
            }

            return response()->json(['data' => $record]);
        } catch (\Exception $e) {
            Log::error("Error creating record in table {$table}: ".$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing record in a table
     */
    public function update(Request $request, string $table, string $id): JsonResponse
    {
        if (! $this->schemaService->isAllowedTable($table)) {
            return response()->json(['error' => 'Table not allowed'], 404);
        }
        if ($blocked = $this->denyMatchCatalogWrites($table)) {
            return $blocked;
        }

        try {
            $prepared = $this->schemaService->prepareWritePayload($table, $request->all(), false);
            if (! $prepared['ok']) {
                return response()->json(['error' => $prepared['error']], 422);
            }

            $data = $prepared['data'];
            $primaryKey = $this->schemaService->getPrimaryKeyColumn($table);

            DB::table($table)->where($primaryKey, $id)->update($data);
            $record = DB::table($table)->where($primaryKey, $id)->first();

            return response()->json(['data' => $record]);
        } catch (\Exception $e) {
            Log::error("Error updating record {$id} in table {$table}: ".$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a record from a table
     */
    public function destroy(string $table, string $id): JsonResponse
    {
        if (! $this->schemaService->isAllowedTable($table)) {
            return response()->json(['error' => 'Table not allowed'], 404);
        }
        if ($blocked = $this->denyMatchCatalogWrites($table)) {
            return $blocked;
        }

        try {
            $impact = $this->schemaService->deleteImpact($table, $id);
            if (! $impact['can_delete']) {
                return response()->json([
                    'error' => 'Delete blocked by foreign key references',
                    'blockers' => $impact['blockers'],
                ], 409);
            }

            $primaryKey = $this->schemaService->getPrimaryKeyColumn($table);
            DB::table($table)->where($primaryKey, $id)->delete();

            return response()->json([
                'success' => true,
                'cascade_impact' => $impact['cascade_impact'],
            ]);
        } catch (\Exception $e) {
            Log::error("Error deleting record {$id} from table {$table}: ".$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export all main tables data
     */
    public function export()
    {
        try {
            $tables = $this->schemaService->discoverMTables();

            if (empty($tables)) {
                throw new \Exception('No m_ tables found in the database');
            }

            $exportData = $this->buildExportData($tables);

            $filename = 'main-tables-export-'.now()->format('Y-m-d-H-i-s').'.json';
            Storage::put("exports/{$filename}", json_encode($exportData, JSON_PRETTY_PRINT));

            $repoPath = database_path('exports');
            if (! file_exists($repoPath)) {
                mkdir($repoPath, 0755, true);
            }
            file_put_contents(
                database_path('exports/main-tables-latest.json'),
                json_encode($exportData, JSON_PRETTY_PRINT)
            );

            return response()->streamDownload(function () use ($exportData) {
                echo json_encode($exportData, JSON_PRETTY_PRINT);
            }, 'main-tables-data.json', [
                'Content-Type' => 'application/json',
                'X-Seeder-Generated' => 'true',
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting main tables: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export main tables and create GitHub PR in one action
     */
    public function createPR(): JsonResponse
    {
        try {
            $tables = $this->schemaService->discoverMTables();

            if (empty($tables)) {
                throw new \Exception('No m_ tables found in the database');
            }

            $exportData = $this->buildExportData($tables);

            $repoPath = database_path('exports');
            if (! file_exists($repoPath)) {
                mkdir($repoPath, 0755, true);
            }
            file_put_contents(
                database_path('exports/main-tables-latest.json'),
                json_encode($exportData, JSON_PRETTY_PRINT)
            );

            $exitCode = Artisan::call('main-data:create-pr');
            $output = Artisan::output();

            if ($exitCode !== 0) {
                Log::error('Main data PR creation failed', [
                    'exit_code' => $exitCode,
                    'output' => $output,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'GitHub PR creation failed',
                    'message' => 'GitHub PR creation failed',
                    'output' => $output,
                    'exit_code' => $exitCode,
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Export completed and GitHub PR created successfully.',
                'output' => $output,
                'tables_exported' => count($tables),
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting and creating main data PR: '.$e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, mixed>
     */
    private function buildExportData(array $tables): array
    {
        $exportData = [];
        foreach ($tables as $table) {
            try {
                $data = DB::table($table)->get()->toArray();
                $exportData[$table] = array_map(static fn ($record) => (array) $record, $data);
            } catch (\Exception $e) {
                Log::error("Failed to export table {$table}: ".$e->getMessage());
                $exportData[$table] = [];
            }
        }

        $exportData['_metadata'] = [
            'exported_at' => now()->toISOString(),
            'tables' => $tables,
            'version' => '1.0',
        ];

        return $exportData;
    }

    private function getTableDisplayName(string $table): string
    {
        $displayNames = [
            'm_season' => 'Seasons',
            'm_level' => 'Levels',
            'm_room_type' => 'Room Types',
            'm_room_type_group' => 'Room Type Groups',
            'm_parameter' => 'Parameters',
            'm_parameter_condition' => 'Parameter Conditions',
            'm_activity_type' => 'Activity Types',
            'm_activity_type_detail' => 'Activity Type Details',
            'm_first_program' => 'First Programs',
            'm_role' => 'Roles',
            'm_staffing_rule' => 'Staffing Rules',
            'm_visibility' => 'Visibility Rules',
            'm_supported_plan' => 'Supported Plans',
            'm_match' => 'Match Plans (use Matchpläne)',
        ];

        return $displayNames[$table] ?? $table;
    }

    /**
     * Catalog match grids are edited only via Matchpläne admin — not flat Main Tables CRUD.
     */
    private function denyMatchCatalogWrites(string $table): ?JsonResponse
    {
        if ($table !== 'm_match') {
            return null;
        }

        return response()->json([
            'error' => 'm_match is edited only in Admin → Matchpläne',
        ], 403);
    }
}
