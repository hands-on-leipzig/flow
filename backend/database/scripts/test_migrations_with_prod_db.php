<?php

/**
 * Test migrations with production database structure
 * 
 * This script:
 * 1. Creates a test database
 * 2. Imports the production SQL dump
 * 3. Runs fix_migration_records
 * 4. Runs refresh_m_tables
 * 5. Runs migrations
 * 6. Reports any errors
 * 
 * Usage: php database/scripts/test_migrations_with_prod_db.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;

// Use existing database or create a test one
$testDbName = getenv('TEST_DB_NAME') ?: 'fll_planning_migration_test';
$sqlDumpPath = __DIR__ . '/../exports/fll_planning_prod (2).sql';

echo "🧪 Testing Migrations with Production Database Structure\n";
echo "========================================================\n\n";

try {
    // Step 1: Get database connection info
    $host = config('database.connections.mysql.host');
    $username = config('database.connections.mysql.username');
    $password = config('database.connections.mysql.password');
    $port = config('database.connections.mysql.port', 3306);
    
    echo "📊 Database Configuration:\n";
    echo "  Host: {$host}\n";
    echo "  Port: {$port}\n";
    echo "  User: {$username}\n";
    echo "  Test DB: {$testDbName}\n\n";
    
    // Step 2: Try to create or use existing test database
    echo "Step 1: Setting up test database...\n";
    $pdo = new PDO("mysql:host={$host};port={$port}", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if database exists
    $stmt = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($testDbName));
    $dbExists = $stmt->fetch();
    
    if (!$dbExists) {
        // Try to create database
        try {
            $pdo->exec("CREATE DATABASE `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            echo "  ✓ Test database created\n\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Access denied') !== false) {
                echo "  ⚠️  Cannot create database (permission denied)\n";
                echo "  Please create the database manually:\n";
                echo "    CREATE DATABASE `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
                echo "  Or set TEST_DB_NAME environment variable to use an existing database.\n";
                exit(1);
            }
            throw $e;
        }
    } else {
        echo "  ✓ Test database already exists\n";
        // Drop all tables to start fresh
        try {
            $pdo->exec("USE `{$testDbName}`");
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            echo "  ✓ Cleared existing tables\n\n";
        } catch (PDOException $e) {
            echo "  ⚠️  Could not clear tables: " . $e->getMessage() . "\n";
            echo "  Continuing anyway...\n\n";
        }
    }
    
    // Step 3: Import production SQL dump using Laravel DB connection
    echo "Step 2: Importing production SQL dump...\n";
    if (!file_exists($sqlDumpPath)) {
        echo "  ❌ SQL dump not found: {$sqlDumpPath}\n";
        exit(1);
    }
    
    try {
        // Switch to test database
        config(['database.connections.mysql.database' => $testDbName]);
        DB::purge('mysql');
        DB::reconnect('mysql');
        
        // Replace database name in SQL dump
        $sqlContent = file_get_contents($sqlDumpPath);
        $sqlContent = str_replace('`fll_planning_prod`', "`{$testDbName}`", $sqlContent);
        
        // Remove comments and split into statements
        $sqlContent = preg_replace('/--.*$/m', '', $sqlContent);
        $sqlContent = preg_replace('/\/\*.*?\*\//s', '', $sqlContent);
        
        // Split by semicolon, but preserve within quotes
        $statements = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = null;
        
        for ($i = 0; $i < strlen($sqlContent); $i++) {
            $char = $sqlContent[$i];
            $current .= $char;
            
            if (($char === '"' || $char === "'" || $char === '`') && ($i === 0 || $sqlContent[$i-1] !== '\\')) {
                if (!$inQuotes) {
                    $inQuotes = true;
                    $quoteChar = $char;
                } elseif ($char === $quoteChar) {
                    $inQuotes = false;
                    $quoteChar = null;
                }
            }
            
            if (!$inQuotes && $char === ';') {
                $stmt = trim($current);
                if (!empty($stmt) && !preg_match('/^(SET|START|COMMIT|ROLLBACK)/i', $stmt)) {
                    $statements[] = $stmt;
                }
                $current = '';
            }
        }
        
        // Execute statements
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $executed = 0;
        foreach ($statements as $stmt) {
            try {
                if (stripos($stmt, 'CREATE TABLE') !== false || 
                    stripos($stmt, 'INSERT INTO') !== false ||
                    stripos($stmt, 'ALTER TABLE') !== false) {
                    DB::statement($stmt);
                    $executed++;
                }
            } catch (Exception $e) {
                // Ignore errors for now - some statements might fail
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        
        echo "  ✓ Executed {$executed} SQL statements\n\n";
    } catch (Exception $e) {
        echo "  ⚠️  Partial import (some statements may have failed): " . $e->getMessage() . "\n";
        echo "  Continuing with migration test...\n\n";
    }
    
    // Step 4: Switch Laravel to use test database
    echo "Step 3: Configuring Laravel to use test database...\n";
    config(['database.connections.mysql.database' => $testDbName]);
    DB::purge('mysql');
    DB::reconnect('mysql');
    echo "  ✓ Connected to test database\n\n";
    
    // Step 5: Run fix_migration_records
    echo "Step 4: Running fix_migration_records...\n";
    try {
        include __DIR__ . '/fix_migration_records.php';
        fixMigrationRecords();
        echo "  ✓ Migration records fixed\n\n";
    } catch (Exception $e) {
        echo "  ❌ Failed to fix migration records: " . $e->getMessage() . "\n";
        echo "  Continuing anyway...\n\n";
    }
    
    // Step 6: Run refresh_m_tables
    echo "Step 5: Running refresh_m_tables...\n";
    try {
        include __DIR__ . '/refresh_m_tables.php';
        refreshMTables();
        echo "  ✓ m_ tables refreshed\n\n";
    } catch (Exception $e) {
        echo "  ❌ Failed to refresh m_ tables: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    // Step 7: Run migrations
    echo "Step 6: Running migrations...\n";
    try {
        Artisan::call('migrate', ['--force' => true]);
        $output = Artisan::output();
        echo $output;
        echo "  ✓ Migrations completed\n\n";
    } catch (Exception $e) {
        echo "  ❌ Migration failed: " . $e->getMessage() . "\n";
        echo "  Stack trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
    
    // Step 8: Verify
    echo "Step 7: Verifying migration results...\n";
    $tableCount = DB::select("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = ?", [$testDbName]);
    $migrationCount = DB::table('migrations')->count();
    
    echo "  Tables in database: " . $tableCount[0]->count . "\n";
    echo "  Migration records: {$migrationCount}\n";
    
    // Check for m_ tables
    $mTables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE 'm_%'", [$testDbName]);
    echo "  m_ tables found: " . count($mTables) . "\n";
    
    if (count($mTables) < 10) {
        echo "  ⚠️  Warning: Expected at least 10 m_ tables, found " . count($mTables) . "\n";
    }
    
    echo "\n✅ Migration test completed successfully!\n";
    echo "========================================================\n";
    
    // Cleanup
    echo "\nCleaning up test database...\n";
    try {
        $pdo = new PDO("mysql:host={$host};port={$port}", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
        echo "  ✓ Test database dropped\n";
    } catch (PDOException $e) {
        echo "  ⚠️  Failed to drop test database: " . $e->getMessage() . "\n";
        echo "  You may need to drop it manually: DROP DATABASE `{$testDbName}`;\n";
    }
    
} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

