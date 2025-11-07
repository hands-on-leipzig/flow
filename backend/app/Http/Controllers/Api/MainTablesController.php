<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MainTablesController extends Controller
{
    /**
     * Dynamically discover all m_ tables from the database
     */
    private function discoverMTables(): array
    {
        try {
            $databaseName = DB::connection()->getDatabaseName();
            $tables = DB::select("SHOW TABLES");
            $tableKey = "Tables_in_{$databaseName}";
            
            Log::info("Discovering m_ tables", [
                'database' => $databaseName,
                'total_tables' => count($tables)
            ]);
            
            $mTableNames = [];
            foreach ($tables as $table) {
                $tableName = $table->$tableKey;
                if (str_starts_with($tableName, 'm_')) {
                    $mTableNames[] = $tableName;
                }
            }
            
            // Sort alphabetically for consistency
            sort($mTableNames);
            
            Log::info("Discovered m_ tables", [
                'count' => count($mTableNames),
                'tables' => $mTableNames
            ]);
            
            return $mTableNames;
        } catch (\Exception $e) {
            Log::error("Error discovering m_ tables: " . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get all available main tables with their record counts
     */
    public function index(): JsonResponse
    {
        $tables = $this->discoverMTables();

        $result = [];
        foreach ($tables as $table) {
            try {
                $count = DB::table($table)->count();
                $result[] = [
                    'name' => $table,
                    'display_name' => $this->getTableDisplayName($table),
                    'count' => $count
                ];
            } catch (\Exception $e) {
                Log::error("Error getting count for table {$table}: " . $e->getMessage());
                $result[] = [
                    'name' => $table,
                    'display_name' => $this->getTableDisplayName($table),
                    'count' => 0
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
        try {
            $count = DB::table($table)->count();
            return response()->json(['count' => $count]);
        } catch (\Exception $e) {
            Log::error("Error getting count for table {$table}: " . $e->getMessage());
            return response()->json(['count' => 0]);
        }
    }

    /**
     * Get all data from a specific table
     */
    public function getTableData(string $table): JsonResponse
    {
        try {
            $data = DB::table($table)->get()->toArray();
            return response()->json(['data' => $data]);
        } catch (\Exception $e) {
            Log::error("Error getting data for table {$table}: " . $e->getMessage());
            return response()->json(['data' => []], 500);
        }
    }

    /**
     * Get column structure for a table
     */
    public function getTableColumns(string $table): JsonResponse
    {
        try {
            $columns = DB::getSchemaBuilder()->getColumnListing($table);
            return response()->json(['columns' => $columns]);
        } catch (\Exception $e) {
            Log::error("Error getting columns for table {$table}: " . $e->getMessage());
            return response()->json(['columns' => []], 500);
        }
    }

    /**
     * Create a new record in a table
     */
    public function store(Request $request, string $table): JsonResponse
    {
        try {
            $data = $request->all();
            
            // Remove empty values
            $data = array_filter($data, function($value) {
                return $value !== '' && $value !== null;
            });

            $id = DB::table($table)->insertGetId($data);
            $record = DB::table($table)->where('id', $id)->first();

            return response()->json(['data' => $record]);
        } catch (\Exception $e) {
            Log::error("Error creating record in table {$table}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing record in a table
     */
    public function update(Request $request, string $table, int $id): JsonResponse
    {
        try {
            $data = $request->all();
            
            // Remove empty values
            $data = array_filter($data, function($value) {
                return $value !== '' && $value !== null;
            });

            DB::table($table)->where('id', $id)->update($data);
            $record = DB::table($table)->where('id', $id)->first();

            return response()->json(['data' => $record]);
        } catch (\Exception $e) {
            Log::error("Error updating record {$id} in table {$table}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a record from a table
     */
    public function destroy(string $table, int $id): JsonResponse
    {
        try {
            DB::table($table)->where('id', $id)->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error("Error deleting record {$id} from table {$table}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export all main tables data
     */
    public function export()
    {
        try {
            // Dynamically discover all m_ tables from the database
            $tables = $this->discoverMTables();
            
            if (empty($tables)) {
                throw new \Exception('No m_ tables found in the database');
            }
            
            Log::info("Exporting m_ tables", ['tables' => $tables, 'count' => count($tables)]);

            $exportData = [];
            $exportErrors = [];
            
            foreach ($tables as $table) {
                try {
                    Log::info("Exporting table: {$table}");
                    $data = DB::table($table)->get()->toArray();
                    $exportData[$table] = array_map(function($record) {
                        return (array) $record;
                    }, $data);
                    Log::info("Successfully exported {$table}", ['record_count' => count($exportData[$table])]);
                } catch (\Exception $e) {
                    $errorMsg = "Failed to export table {$table}: " . $e->getMessage();
                    Log::error($errorMsg, [
                        'table' => $table,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $exportErrors[] = $errorMsg;
                    // Still include the table in export with empty array so it's not missing
                    $exportData[$table] = [];
                }
            }
            
            // Log any errors but don't fail the export
            if (!empty($exportErrors)) {
                Log::warning("Export completed with errors", ['errors' => $exportErrors]);
            }
            
            // Verify all discovered tables are in export data
            $missingTables = array_diff($tables, array_keys($exportData));
            if (!empty($missingTables)) {
                Log::error("Some tables are missing from export data", ['missing' => $missingTables]);
                // Add missing tables with empty arrays
                foreach ($missingTables as $missingTable) {
                    $exportData[$missingTable] = [];
                }
            }

            // Add metadata
            $exportData['_metadata'] = [
                'exported_at' => now()->toISOString(),
                'tables' => $tables,
                'version' => '1.0'
            ];

            // Save to storage for backup/reference
            $filename = 'main-tables-export-' . now()->format('Y-m-d-H-i-s') . '.json';
            Storage::put("exports/{$filename}", json_encode($exportData, JSON_PRETTY_PRINT));

            // Also save to database/exports/ for repo (used by MainDataSeeder during deployment)
            $repoPath = database_path('exports');
            if (!file_exists($repoPath)) {
                mkdir($repoPath, 0755, true);
            }
            file_put_contents(
                database_path('exports/main-tables-latest.json'),
                json_encode($exportData, JSON_PRETTY_PRINT)
            );

            // Generate MainDataSeeder.php for local use
            \Artisan::call('main-data:generate-seeder');

            // Verify export data structure
            $exportedTableCount = count(array_filter($exportData, fn($key) => $key !== '_metadata', ARRAY_FILTER_USE_KEY));
            $expectedTableCount = count($tables);
            
            if ($exportedTableCount !== $expectedTableCount) {
                Log::error("Table count mismatch in export", [
                    'expected' => $expectedTableCount,
                    'exported' => $exportedTableCount,
                    'tables' => $tables,
                    'export_keys' => array_keys(array_filter($exportData, fn($key) => $key !== '_metadata', ARRAY_FILTER_USE_KEY))
                ]);
            }
            
            Log::info("Main tables exported successfully", [
                'filename' => $filename,
                'tables' => $tables,
                'table_count' => $exportedTableCount,
                'expected_table_count' => $expectedTableCount,
                'total_records' => array_sum(array_map('count', array_filter($exportData, fn($key) => $key !== '_metadata', ARRAY_FILTER_USE_KEY))),
                'errors' => $exportErrors ?? [],
                'seeder_generated' => true,
                'note' => 'Use Create GitHub PR button for deployment updates'
            ]);

            // Return file download
            return response()->streamDownload(function () use ($exportData) {
                echo json_encode($exportData, JSON_PRETTY_PRINT);
            }, 'main-tables-data.json', [
                'Content-Type' => 'application/json',
                'X-Seeder-Generated' => 'true'
            ]);

        } catch (\Exception $e) {
            Log::error("Error exporting main tables: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export main tables and create GitHub PR in one action
     */
    public function createPR(): JsonResponse
    {
        try {
            // Step 1: First export all m_ tables from database to JSON
            Log::info("Starting export and PR creation process");
            
            // Dynamically discover all m_ tables from the database
            $tables = $this->discoverMTables();
            
            if (empty($tables)) {
                throw new \Exception('No m_ tables found in the database');
            }
            
            Log::info("Exporting m_ tables for PR", ['tables' => $tables, 'count' => count($tables)]);

            $exportData = [];
            $exportErrors = [];
            
            foreach ($tables as $table) {
                try {
                    Log::info("Exporting table: {$table}");
                    $data = DB::table($table)->get()->toArray();
                    $exportData[$table] = array_map(function($record) {
                        return (array) $record;
                    }, $data);
                    Log::info("Successfully exported {$table}", ['record_count' => count($exportData[$table])]);
                } catch (\Exception $e) {
                    $errorMsg = "Failed to export table {$table}: " . $e->getMessage();
                    Log::error($errorMsg, [
                        'table' => $table,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $exportErrors[] = $errorMsg;
                    // Still include the table in export with empty array so it's not missing
                    $exportData[$table] = [];
                }
            }
            
            // Log any errors but don't fail the export
            if (!empty($exportErrors)) {
                Log::warning("Export completed with errors", ['errors' => $exportErrors]);
            }
            
            // Verify all discovered tables are in export data
            $missingTables = array_diff($tables, array_keys($exportData));
            if (!empty($missingTables)) {
                Log::error("Some tables are missing from export data", ['missing' => $missingTables]);
                // Add missing tables with empty arrays
                foreach ($missingTables as $missingTable) {
                    $exportData[$missingTable] = [];
                }
            }

            // Add metadata
            $exportData['_metadata'] = [
                'exported_at' => now()->toISOString(),
                'tables' => $tables,
                'version' => '1.0'
            ];

            // Save to database/exports/ for repo (used by MainDataSeeder during deployment)
            $repoPath = database_path('exports');
            if (!file_exists($repoPath)) {
                mkdir($repoPath, 0755, true);
            }
            $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT);
            file_put_contents(
                database_path('exports/main-tables-latest.json'),
                $jsonContent
            );
            
            Log::info("JSON file saved successfully", [
                'path' => database_path('exports/main-tables-latest.json'),
                'tables' => $tables,
                'table_count' => count($tables)
            ]);

            // Step 2: Now create the GitHub PR with the exported JSON
            \Artisan::call('main-data:create-pr');
            $output = \Artisan::output();
            
            Log::info("Main data export and PR creation completed", [
                'output' => $output,
                'tables' => $tables,
                'errors' => $exportErrors ?? []
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Export completed and GitHub PR created successfully. Check the command output for details.',
                'output' => $output,
                'tables_exported' => count($tables),
                'errors' => $exportErrors ?? []
            ]);

        } catch (\Exception $e) {
            Log::error("Error exporting and creating main data PR: " . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Import main tables data
     */
    public function import(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:json'
            ]);

            $file = $request->file('file');
            $content = file_get_contents($file->getPathname());
            $data = json_decode($content, true);

            if (!$data || !isset($data['_metadata'])) {
                return response()->json(['error' => 'Invalid export file format'], 400);
            }

            $tables = $data['_metadata']['tables'] ?? [];
            $importedCounts = [];

            foreach ($tables as $table) {
                if (isset($data[$table])) {
                    // Clear existing data
                    DB::table($table)->truncate();
                    
                    // Insert new data
                    if (!empty($data[$table])) {
                        DB::table($table)->insert($data[$table]);
                    }
                    
                    $importedCounts[$table] = count($data[$table]);
                }
            }

            Log::info("Main tables imported successfully", [
                'imported_counts' => $importedCounts,
                'total_records' => array_sum($importedCounts)
            ]);

            return response()->json([
                'success' => true,
                'imported_counts' => $importedCounts,
                'message' => 'Import completed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error("Error importing main tables: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get display name for a table
     */
    private function getTableDisplayName(string $table): string
    {
        $displayNames = [
            'm_season' => 'Seasons',
            'm_level' => 'Levels',
            'm_room_type' => 'Room Types',
            'm_room_type_group' => 'Room Type Groups',
            'm_parameter' => 'Parameters',
            'm_activity_type' => 'Activity Types',
            'm_activity_type_detail' => 'Activity Type Details',
            'm_first_program' => 'First Programs',
            'm_insert_point' => 'Insert Points',
            'm_role' => 'Roles',
            'm_visibility' => 'Visibility Rules',
            'm_supported_plan' => 'Supported Plans'
        ];

        return $displayNames[$table] ?? $table;
    }
}
