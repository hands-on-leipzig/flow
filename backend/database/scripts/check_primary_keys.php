<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$tablesToCheck = ['m_season', 'm_level', 'event', 'plan', 'user', 'regional_partner'];
$dbName = DB::connection()->getDatabaseName();

echo "Checking PRIMARY KEY status for id columns:\n";
echo str_repeat('=', 80) . "\n";

foreach ($tablesToCheck as $tableName) {
    if (!Schema::hasTable($tableName)) {
        echo "{$tableName}: Table not found\n";
        continue;
    }
    
    // Check if id is PRIMARY KEY using information_schema
    $keys = DB::select("
        SELECT COUNT(*) as cnt 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = ? 
        AND TABLE_NAME = ? 
        AND COLUMN_NAME = 'id' 
        AND CONSTRAINT_NAME = 'PRIMARY'
    ", [$dbName, $tableName]);
    
    $isPrimary = $keys[0]->cnt > 0;
    
    // Check AUTO_INCREMENT
    $sql = "SHOW COLUMNS FROM `{$tableName}` WHERE Field = 'id'";
    $columns = DB::select($sql);
    $hasAI = !empty($columns) && strpos($columns[0]->Extra, 'auto_increment') !== false;
    
    echo sprintf("%-25s PRIMARY KEY: %-5s AUTO_INCREMENT: %-5s\n", 
        $tableName, 
        $isPrimary ? 'YES' : 'NO', 
        $hasAI ? 'YES' : 'NO'
    );
}
