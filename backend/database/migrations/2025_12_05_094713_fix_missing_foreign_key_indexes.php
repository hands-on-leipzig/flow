<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix missing indexes on foreign key columns.
     * 
     * This migration checks all foreign keys in the database and ensures
     * each FK column (or set of columns for composite FKs) has an appropriate index.
     * An index is "appropriate" if the FK columns are the first columns in the index.
     * 
     * Works across all environments (dev, test, prod).
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            \Log::info('Skipping FK index check - not MySQL/MariaDB');
            return;
        }

        $dbName = DB::connection()->getDatabaseName();
        
        // Get all foreign keys with their columns
        $foreignKeys = DB::select("
            SELECT 
                rc.CONSTRAINT_NAME,
                kcu.TABLE_NAME,
                kcu.COLUMN_NAME,
                kcu.ORDINAL_POSITION,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME
            FROM information_schema.REFERENTIAL_CONSTRAINTS rc
            INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
                ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE rc.CONSTRAINT_SCHEMA = ?
            ORDER BY rc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
        ", [$dbName]);
        
        // Group by constraint to handle composite FKs
        $fkGroups = [];
        foreach ($foreignKeys as $fk) {
            $key = $fk->CONSTRAINT_NAME;
            if (!isset($fkGroups[$key])) {
                $fkGroups[$key] = [
                    'constraint_name' => $fk->CONSTRAINT_NAME,
                    'table_name' => $fk->TABLE_NAME,
                    'columns' => [],
                    'referenced_table' => $fk->REFERENCED_TABLE_NAME,
                ];
            }
            $fkGroups[$key]['columns'][] = [
                'name' => $fk->COLUMN_NAME,
                'position' => $fk->ORDINAL_POSITION,
                'referenced_column' => $fk->REFERENCED_COLUMN_NAME,
            ];
        }
        
        $fixed = 0;
        $skipped = 0;
        
        foreach ($fkGroups as $fk) {
            $tableName = $fk['table_name'];
            $constraintName = $fk['constraint_name'];
            $columns = array_column($fk['columns'], 'name');
            
            // Check if table exists
            if (!Schema::hasTable($tableName)) {
                \Log::warning("Skipping FK {$constraintName}: Table {$tableName} does not exist");
                $skipped++;
                continue;
            }
            
            // Check if all columns exist
            $allColumnsExist = true;
            foreach ($columns as $col) {
                if (!Schema::hasColumn($tableName, $col)) {
                    \Log::warning("Skipping FK {$constraintName}: Column {$tableName}.{$col} does not exist");
                    $allColumnsExist = false;
                    break;
                }
            }
            if (!$allColumnsExist) {
                $skipped++;
                continue;
            }
            
            // Check if there's an appropriate index
            $hasAppropriateIndex = $this->hasAppropriateIndex($dbName, $tableName, $columns);
            
            if (!$hasAppropriateIndex) {
                // Add index
                try {
                    Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns) {
                        // Generate index name
                        $indexName = $this->generateIndexName($tableName, $columns);
                        $table->index($columns, $indexName);
                    });
                    \Log::info("Added index on {$tableName}." . implode(', ', $columns) . " for FK {$constraintName}");
                    $fixed++;
                } catch (\Throwable $e) {
                    \Log::warning("Failed to add index on {$tableName}." . implode(', ', $columns) . " for FK {$constraintName}: " . $e->getMessage());
                    $skipped++;
                }
            }
        }
        
        \Log::info("FK index check complete: {$fixed} indexes added, {$skipped} skipped");
    }

    /**
     * Check if there's an appropriate index for the given columns.
     * An index is appropriate if the FK columns are the first columns in the index.
     */
    private function hasAppropriateIndex(string $dbName, string $tableName, array $fkColumns): bool
    {
        // Get all indexes for this table
        $indexes = DB::select("
            SELECT 
                INDEX_NAME,
                COLUMN_NAME,
                SEQ_IN_INDEX
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ", [$dbName, $tableName]);
        
        // Group by index name
        $indexGroups = [];
        foreach ($indexes as $idx) {
            $indexName = $idx->INDEX_NAME;
            if (!isset($indexGroups[$indexName])) {
                $indexGroups[$indexName] = [];
            }
            $indexGroups[$indexName][] = $idx->COLUMN_NAME;
        }
        
        // Check if any index starts with our FK columns
        foreach ($indexGroups as $indexColumns) {
            // Skip PRIMARY key (it's not suitable for FK)
            if (in_array('PRIMARY', $indexColumns)) {
                continue;
            }
            
            // Check if this index starts with our FK columns
            if (count($indexColumns) >= count($fkColumns)) {
                $matches = true;
                for ($i = 0; $i < count($fkColumns); $i++) {
                    if ($indexColumns[$i] !== $fkColumns[$i]) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Generate a unique index name for the given table and columns.
     */
    private function generateIndexName(string $tableName, array $columns): string
    {
        $baseName = $tableName . '_' . implode('_', $columns) . '_index';
        
        // Ensure name doesn't exceed MySQL's 64 character limit
        if (strlen($baseName) > 64) {
            $baseName = substr($tableName, 0, 20) . '_' . implode('_', array_map(function($col) {
                return substr($col, 0, 10);
            }, $columns)) . '_idx';
            // If still too long, truncate
            if (strlen($baseName) > 64) {
                $baseName = substr($baseName, 0, 64);
            }
        }
        
        return $baseName;
    }

    /**
     * Reverse the migrations.
     * 
     * Note: We don't remove indexes in down() because:
     * 1. Indexes might be used by other constraints
     * 2. Removing indexes can impact performance
     * 3. It's safer to leave them in place
     */
    public function down(): void
    {
        // Rollback not implemented - indexes are safe to leave in place
        // If needed, manually identify and remove indexes using:
        // SHOW INDEXES FROM table_name;
    }
};
