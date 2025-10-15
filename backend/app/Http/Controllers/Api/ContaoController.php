<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ContaoController extends Controller
{
    /**
     * Get data from Contao database
     */
    public function getData(Request $request): JsonResponse
    {
        try {
            $table = $request->input('table');
            $id = $request->input('id');
            $conditions = $request->input('conditions', []);
            
            if (!$table) {
                return response()->json(['error' => 'Table parameter is required'], 400);
            }

            $query = DB::connection('contao')->table($table);
            
            // Add conditions if provided
            foreach ($conditions as $condition) {
                if (isset($condition['column'], $condition['operator'], $condition['value'])) {
                    $query->where($condition['column'], $condition['operator'], $condition['value']);
                }
            }
            
            // Get specific record by ID if provided
            if ($id) {
                $result = $query->where('id', $id)->first();
                return response()->json($result);
            }
            
            // Get all records
            $result = $query->get();
            return response()->json($result);
            
        } catch (Exception $e) {
            Log::error('Contao getData error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve data from Contao'], 500);
        }
    }

    /**
     * Set/Update data in Contao database
     */
    public function setData(Request $request): JsonResponse
    {
        try {
            $table = $request->input('table');
            $data = $request->input('data');
            $id = $request->input('id');
            
            if (!$table || !$data) {
                return response()->json(['error' => 'Table and data parameters are required'], 400);
            }

            // Validate data is array
            if (!is_array($data)) {
                return response()->json(['error' => 'Data must be an array'], 400);
            }

            if ($id) {
                // Update existing record
                $updated = DB::connection('contao')->table($table)
                    ->where('id', $id)
                    ->update($data);
                
                if ($updated) {
                    return response()->json(['message' => 'Record updated successfully', 'id' => $id]);
                } else {
                    return response()->json(['error' => 'Record not found or no changes made'], 404);
                }
            } else {
                // Insert new record
                $newId = DB::connection('contao')->table($table)->insertGetId($data);
                return response()->json(['message' => 'Record created successfully', 'id' => $newId]);
            }
            
        } catch (Exception $e) {
            Log::error('Contao setData error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to save data to Contao'], 500);
        }
    }

    /**
     * Delete data from Contao database
     */
    public function deleteData(Request $request): JsonResponse
    {
        try {
            $table = $request->input('table');
            $id = $request->input('id');
            $conditions = $request->input('conditions', []);
            
            if (!$table) {
                return response()->json(['error' => 'Table parameter is required'], 400);
            }

            $query = DB::connection('contao')->table($table);
            
            if ($id) {
                $query->where('id', $id);
            } else {
                // Add conditions if provided
                foreach ($conditions as $condition) {
                    if (isset($condition['column'], $condition['operator'], $condition['value'])) {
                        $query->where($condition['column'], $condition['operator'], $condition['value']);
                    }
                }
            }
            
            $deleted = $query->delete();
            
            if ($deleted) {
                return response()->json(['message' => 'Record(s) deleted successfully', 'count' => $deleted]);
            } else {
                return response()->json(['error' => 'No records found to delete'], 404);
            }
            
        } catch (Exception $e) {
            Log::error('Contao deleteData error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete data from Contao'], 500);
        }
    }

    /**
     * Test Contao database connection
     */
    public function testConnection(): JsonResponse
    {
        try {
            $result = DB::connection('contao')->select('SELECT 1 as test');
            return response()->json([
                'status' => 'success',
                'message' => 'Contao database connection is working',
                'test_result' => $result[0]->test ?? null
            ]);
        } catch (Exception $e) {
            Log::error('Contao connection test failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Contao database connection failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get list of tables in Contao database
     */
    public function getTables(): JsonResponse
    {
        try {
            $tables = DB::connection('contao')->select('SHOW TABLES');
            $tableNames = array_map(function($table) {
                return array_values((array)$table)[0];
            }, $tables);
            
            return response()->json([
                'tables' => $tableNames,
                'count' => count($tableNames)
            ]);
        } catch (Exception $e) {
            Log::error('Contao getTables error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve tables from Contao'], 500);
        }
    }

    /**
     * Get table structure from Contao database
     */
    public function getTableStructure(Request $request): JsonResponse
    {
        try {
            $table = $request->input('table');
            
            if (!$table) {
                return response()->json(['error' => 'Table parameter is required'], 400);
            }

            $columns = DB::connection('contao')->select("DESCRIBE {$table}");
            
            return response()->json([
                'table' => $table,
                'columns' => $columns
            ]);
        } catch (Exception $e) {
            Log::error('Contao getTableStructure error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve table structure from Contao'], 500);
        }
    }
}