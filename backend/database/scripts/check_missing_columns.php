<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "🔍 Checking for Missing Columns in Existing Tables\n";
echo "==================================================\n\n";

// Read the create_master_tables migration to find all table definitions
$migrationFile = __DIR__ . '/../migrations/2025_01_01_000000_create_master_tables.php';
$migrationContent = file_get_contents($migrationFile);

// Extract table creation blocks that are conditional (only create if table doesn't exist)
// Pattern: if (!Schema::hasTable('table_name')) { Schema::create('table_name', function (Blueprint $table) { ... }); }

$tablesToCheck = [
    'regional_partner' => [],
    'event' => [],
    'slideshow' => [],
    'slide' => [],
    'publication' => [],
    'user' => [],
    'user_regional_partner' => [],
    'room' => [],
    'room_type_room' => [],
    'team' => [],
    'plan' => [],
    'team_plan' => [],
    'plan_param_value' => [],
    'extra_block' => [],
    'plan_extra_block' => [],
    'activity_group' => [],
    'activity' => [],
    'logo' => [],
    'event_logo' => [],
    'table_event' => [],
    'q_plan' => [],
    'q_plan_match' => [],
    'q_plan_team' => [],
    'q_run' => [],
];

// Parse the migration file to extract column definitions
// This is a simplified parser - we'll look for Schema::create blocks

$missingColumns = [];

foreach ($tablesToCheck as $tableName => $columns) {
    if (!Schema::hasTable($tableName)) {
        continue; // Table doesn't exist, skip
    }
    
    // Get actual columns in database
    $actualColumns = [];
    $dbColumns = DB::select("SHOW COLUMNS FROM `$tableName`");
    foreach ($dbColumns as $col) {
        $actualColumns[] = $col->Field;
    }
    
    // Extract expected columns from migration file
    // Look for: Schema::create('table_name', function (Blueprint $table) { ... });
    $pattern = "/Schema::create\('$tableName'[^}]*function\s*\([^)]*\)\s*\{([^}]+)\}/s";
    if (preg_match($pattern, $migrationContent, $matches)) {
        $tableDef = $matches[1];
        
        // Extract column definitions
        preg_match_all('/\$table->(\w+)\([^)]*\)(?:->[^;]+)?;/', $tableDef, $colMatches);
        
        $expectedColumns = [];
        foreach ($colMatches[0] as $colDef) {
            // Extract column name from $table->string('name') or $table->id() etc.
            if (preg_match("/->(\w+)\(['\"]([^'\"]+)['\"]/", $colDef, $nameMatch)) {
                $expectedColumns[] = $nameMatch[2];
            } elseif (preg_match("/->id\(\)/", $colDef)) {
                $expectedColumns[] = 'id';
            } elseif (preg_match("/->timestamps\(\)/", $colDef)) {
                $expectedColumns[] = 'created_at';
                $expectedColumns[] = 'updated_at';
            }
        }
        
        // Check for missing columns
        $missing = array_diff($expectedColumns, $actualColumns);
        if (!empty($missing)) {
            $missingColumns[$tableName] = $missing;
        }
    }
}

if (empty($missingColumns)) {
    echo "✅ No missing columns found!\n";
} else {
    echo "❌ Missing columns found:\n\n";
    foreach ($missingColumns as $tableName => $columns) {
        echo "📋 Table: $tableName\n";
        echo "   Missing columns: " . implode(', ', $columns) . "\n\n";
    }
}

// Also check for columns that might have been added in other migrations
echo "\n🔍 Checking for columns added in other migrations:\n";
echo "==================================================\n\n";

// Check plan table for generator_status (we already know this one)
if (Schema::hasTable('plan') && !Schema::hasColumn('plan', 'generator_status')) {
    echo "❌ plan.generator_status - MISSING (will be added by migration)\n";
}

// Check slide table for active column
if (Schema::hasTable('slide')) {
    if (!Schema::hasColumn('slide', 'active')) {
        echo "❌ slide.active - MISSING (should be added by 2025_09_11_112538_slide_active.php)\n";
    }
    if (!Schema::hasColumn('slide', 'slideshow_id') && Schema::hasColumn('slide', 'slideshow')) {
        echo "⚠️  slide.slideshow - EXISTS but should be renamed to slideshow_id\n";
    }
}

// Check room table for sequence and is_accessible
if (Schema::hasTable('room')) {
    if (!Schema::hasColumn('room', 'sequence')) {
        echo "❌ room.sequence - MISSING (should be added by 2025_10_26_111426_add_sequence_to_room_table.php)\n";
    }
    if (!Schema::hasColumn('room', 'is_accessible')) {
        echo "❌ room.is_accessible - MISSING (should be added by 2025_10_26_131531_add_is_accessible_to_room_table.php)\n";
    }
}

// Check event table for contao_id columns
if (Schema::hasTable('event')) {
    if (!Schema::hasColumn('event', 'contao_id_explore')) {
        echo "❌ event.contao_id_explore - MISSING (should be added by 2025_10_16_074532_add_contao_ids_to_event_table.php)\n";
    }
    if (!Schema::hasColumn('event', 'contao_id_challenge')) {
        echo "❌ event.contao_id_challenge - MISSING (should be added by 2025_10_16_074532_add_contao_ids_to_event_table.php)\n";
    }
}

// Check team_plan for noshow
if (Schema::hasTable('team_plan')) {
    if (!Schema::hasColumn('team_plan', 'noshow')) {
        echo "❌ team_plan.noshow - MISSING (should be added by 2025_10_27_074001_add_noshow_to_team_plan_table.php)\n";
    }
}

// Check q_plan for additional columns
if (Schema::hasTable('q_plan')) {
    $qPlanColumns = ['q2_1_count', 'q2_2_count', 'q2_3_count', 'q2_score_avg', 
                     'q3_1_count', 'q3_2_count', 'q3_3_count', 'q3_score_avg',
                     'q6_duration', 'last_change'];
    foreach ($qPlanColumns as $col) {
        if (!Schema::hasColumn('q_plan', $col)) {
            echo "❌ q_plan.$col - MISSING\n";
        }
    }
}

echo "\n✅ Check complete!\n";

