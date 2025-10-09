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
     * Get all available main tables with their record counts
     */
    public function index(): JsonResponse
    {
        $tables = [
            'm_season',
            'm_level',
            'm_room_type',
            'm_room_type_group',
            'm_parameter',
            'm_activity_type',
            'm_activity_type_detail',
            'm_first_program',
            'm_insert_point',
            'm_role',
            'm_visibility',
            'm_supported_plan'
        ];

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
            $tables = [
                'm_season',
                'm_level',
                'm_room_type',
                'm_room_type_group',
                'm_parameter',
                'm_activity_type',
                'm_activity_type_detail',
                'm_first_program',
                'm_insert_point',
                'm_role',
                'm_visibility',
                'm_supported_plan'
            ];

            $exportData = [];
            foreach ($tables as $table) {
                $data = DB::table($table)->get()->toArray();
                $exportData[$table] = array_map(function($record) {
                    return (array) $record;
                }, $data);
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

            // Generate MainDataSeeder.php for local use
            \Artisan::call('main-data:generate-seeder');

            Log::info("Main tables exported successfully", [
                'filename' => $filename,
                'tables' => $tables,
                'total_records' => array_sum(array_map('count', $exportData)),
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
     * Create GitHub PR with exported main data
     */
    public function createPR(): JsonResponse
    {
        try {
            // Run the PR creation command
            \Artisan::call('main-data:create-pr');
            $output = \Artisan::output();
            
            Log::info("Main data PR creation initiated", [
                'output' => $output
            ]);

            return response()->json([
                'success' => true,
                'message' => 'GitHub PR creation initiated. Check the command output for details.',
                'output' => $output
            ]);

        } catch (\Exception $e) {
            Log::error("Error creating main data PR: " . $e->getMessage());
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
