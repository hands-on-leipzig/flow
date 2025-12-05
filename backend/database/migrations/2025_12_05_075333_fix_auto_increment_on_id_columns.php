<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix AUTO_INCREMENT on id columns that are missing it.
     * 
     * This migration automatically discovers all tables with an 'id' column that is:
     * - A PRIMARY KEY
     * - Missing AUTO_INCREMENT
     * 
     * It then adds AUTO_INCREMENT to match the master migration schema.
     * This works across all environments (dev, test, prod) regardless of which tables exist.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        $dbName = DB::connection()->getDatabaseName();
        
        // Discover all tables with an 'id' column that is a PRIMARY KEY but missing AUTO_INCREMENT
        $tablesToFix = DB::select("
            SELECT 
                c.TABLE_NAME,
                c.COLUMN_TYPE,
                c.IS_NULLABLE,
                c.EXTRA
            FROM information_schema.COLUMNS c
            INNER JOIN information_schema.KEY_COLUMN_USAGE k
                ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
                AND c.TABLE_NAME = k.TABLE_NAME
                AND c.COLUMN_NAME = k.COLUMN_NAME
            WHERE c.TABLE_SCHEMA = ?
                AND c.COLUMN_NAME = 'id'
                AND k.CONSTRAINT_NAME = 'PRIMARY'
                AND c.EXTRA NOT LIKE '%auto_increment%'
            ORDER BY c.TABLE_NAME
        ", [$dbName]);

        foreach ($tablesToFix as $tableInfo) {
            $tableName = $tableInfo->TABLE_NAME;
            
            // Get full column definition
            $columns = DB::select("SHOW COLUMNS FROM `{$tableName}` WHERE Field = 'id'");
            
            if (empty($columns)) {
                continue; // Skip if no id column (shouldn't happen, but safety check)
            }

            $column = $columns[0];
            $hasAutoIncrement = strpos($column->Extra, 'auto_increment') !== false;

            if (!$hasAutoIncrement) {
                // Get current column definition
                $type = $column->Type;
                $null = $column->Null === 'YES' ? 'NULL' : 'NOT NULL';
                
                // Get max id value to set AUTO_INCREMENT correctly
                $maxId = DB::table($tableName)->max('id') ?? 0;
                $nextAutoIncrement = $maxId + 1;
                
                // Build the ALTER statement
                $sql = "ALTER TABLE `{$tableName}` MODIFY COLUMN `id` {$type} {$null} AUTO_INCREMENT";
                
                // Add AUTO_INCREMENT to the id column
                try {
                    DB::statement($sql);
                    
                    // Set AUTO_INCREMENT value explicitly if table has data
                    if ($maxId > 0) {
                        DB::statement("ALTER TABLE `{$tableName}` AUTO_INCREMENT = {$nextAutoIncrement}");
                    }
                } catch (\Throwable $e) {
                    // Log error but continue with other tables
                    \Log::warning("Failed to add AUTO_INCREMENT to {$tableName}.id: " . $e->getMessage());
                }
            }
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    /**
     * Reverse the migrations.
     * 
     * Note: Removing AUTO_INCREMENT is generally not recommended as it can break
     * applications that rely on auto-generated IDs. This rollback is provided
     * for completeness but should be used with caution.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        $dbName = DB::connection()->getDatabaseName();
        
        // Discover all tables with an 'id' column that is a PRIMARY KEY and has AUTO_INCREMENT
        $tablesToRevert = DB::select("
            SELECT 
                c.TABLE_NAME,
                c.COLUMN_TYPE,
                c.IS_NULLABLE
            FROM information_schema.COLUMNS c
            INNER JOIN information_schema.KEY_COLUMN_USAGE k
                ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
                AND c.TABLE_NAME = k.TABLE_NAME
                AND c.COLUMN_NAME = k.COLUMN_NAME
            WHERE c.TABLE_SCHEMA = ?
                AND c.COLUMN_NAME = 'id'
                AND k.CONSTRAINT_NAME = 'PRIMARY'
                AND c.EXTRA LIKE '%auto_increment%'
            ORDER BY c.TABLE_NAME
        ", [$dbName]);

        foreach ($tablesToRevert as $tableInfo) {
            $tableName = $tableInfo->TABLE_NAME;

            $columns = DB::select("SHOW COLUMNS FROM `{$tableName}` WHERE Field = 'id'");
            
            if (empty($columns)) {
                continue;
            }

            $column = $columns[0];
            $hasAutoIncrement = strpos($column->Extra, 'auto_increment') !== false;

            if ($hasAutoIncrement) {
                $type = $column->Type;
                $null = $column->Null === 'YES' ? 'NULL' : 'NOT NULL';
                
                $sql = "ALTER TABLE `{$tableName}` MODIFY COLUMN `id` {$type} {$null}";
                
                try {
                    DB::statement($sql);
                } catch (\Throwable $e) {
                    \Log::warning("Failed to remove AUTO_INCREMENT from {$tableName}.id: " . $e->getMessage());
                }
            }
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }
};
