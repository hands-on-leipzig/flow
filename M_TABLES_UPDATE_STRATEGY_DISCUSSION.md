# M-Tables Update Strategy Discussion
## Updating from JSON WITHOUT Dropping Tables or Disabling FK Checks

## Current Approach (refresh_m_tables.php)

```php
1. Disable FK checks
2. Drop all m_ tables
3. Run migrations (recreate tables)
4. Seed from JSON (insert all records)
```

**Problems:**
- FK checks are disabled (bypasses data integrity)
- Tables are dropped (loses any data not in JSON)
- No handling of FK constraint violations

## Proposed Approach: Incremental Update

### Core Strategy

Instead of dropping and recreating, we **update existing tables**:

```php
1. Keep FK checks ENABLED (maintain data integrity)
2. For each m-table in JSON:
   a. UPDATE existing records (by ID)
   b. INSERT new records (not in DB)
   c. DELETE removed records (in DB but not in JSON)
3. Handle FK constraints properly
```

## Step-by-Step Process

### 1. Load and Parse JSON

```php
$jsonData = json_decode(file_get_contents('main-tables-latest.json'), true);
$tables = $jsonData['_metadata']['tables']; // ['m_level', 'm_season', ...]
```

### 2. For Each m-Table: Compare JSON vs Database

```php
foreach ($tables as $table) {
    $jsonRecords = $jsonData[$table]; // Array of records from JSON
    $dbRecords = DB::table($table)->get()->keyBy('id'); // Current DB records
    
    // Categorize records
    $toUpdate = []; // In both JSON and DB (different values)
    $toInsert = []; // Only in JSON (new records)
    $toDelete = []; // Only in DB (removed records)
    
    foreach ($jsonRecords as $jsonRecord) {
        $id = $jsonRecord['id'];
        if (isset($dbRecords[$id])) {
            // Record exists - check if needs update
            if (recordsDiffer($jsonRecord, $dbRecords[$id])) {
                $toUpdate[] = $jsonRecord;
            }
        } else {
            // New record
            $toInsert[] = $jsonRecord;
        }
    }
    
    // Find records to delete (in DB but not in JSON)
    foreach ($dbRecords as $id => $dbRecord) {
        if (!isset($jsonRecordsById[$id])) {
            $toDelete[] = $id;
        }
    }
}
```

### 3. Handle Updates and Inserts (Easy)

```php
// UPDATE existing records
foreach ($toUpdate as $record) {
    DB::table($table)
        ->where('id', $record['id'])
        ->update($record);
}

// INSERT new records
if (!empty($toInsert)) {
    DB::table($table)->insert($toInsert);
}
```

### 4. Handle Deletes (Complex - FK Constraints)

This is where Pattern 2 (Cascade Delete) comes into play:

```php
foreach ($toDelete as $id) {
    try {
        // Try to delete - FK constraints will handle it
        DB::table($table)->where('id', $id)->delete();
        
        // If CASCADE: Operational data will be automatically deleted
        // If RESTRICT: Will throw exception (can't delete)
        
    } catch (\Exception $e) {
        if (isForeignKeyConstraintError($e)) {
            // Check what type of FK constraint
            $fkInfo = getForeignKeyInfo($table, $id);
            
            if ($fkInfo['onDelete'] === 'CASCADE') {
                // Should have worked - investigate
                throw new \Exception("CASCADE delete failed unexpectedly");
            } else if ($fkInfo['onDelete'] === 'RESTRICT') {
                // Cannot delete - referenced by operational data
                handleRestrictConstraint($table, $id, $fkInfo);
            }
        } else {
            throw $e;
        }
    }
}
```

### 5. Handle RESTRICT Constraints

When a record can't be deleted due to RESTRICT:

**Option A: Keep the record** (if it's still valid)
```php
function handleRestrictConstraint($table, $id, $fkInfo) {
    echo "⚠️  Cannot delete {$table}.id={$id} - referenced by: {$fkInfo['referencing_tables']}\n";
    echo "   Keeping record (may be orphaned from JSON but still referenced)\n";
    // Record stays in DB even though not in JSON
}
```

**Option B: Update operational data first** (if business logic allows)
```php
function handleRestrictConstraint($table, $id, $fkInfo) {
    // Example: If m_level is removed, update events to use default level
    if ($table === 'm_level') {
        $defaultLevel = DB::table('m_level')
            ->where('id', '!=', $id)
            ->orderBy('id')
            ->first();
        
        if ($defaultLevel) {
            // Update all events referencing this level
            DB::table('event')
                ->where('level', $id)
                ->update(['level' => $defaultLevel->id]);
            
            // Now can delete
            DB::table($table)->where('id', $id)->delete();
        }
    }
}
```

**Option C: Block deployment** (if critical)
```php
function handleRestrictConstraint($table, $id, $fkInfo) {
    throw new \Exception(
        "Cannot delete {$table}.id={$id} - referenced by operational data. " .
        "Deployment blocked. Please update operational data first."
    );
}
```

## Dependency Order Matters

m-tables have dependencies on each other. Must process in correct order:

```php
// Correct order (dependencies first)
$mTableOrder = [
    'm_season',              // No dependencies
    'm_level',               // No dependencies
    'm_room_type_group',     // No dependencies
    'm_first_program',       // No dependencies
    'm_parameter',           // Depends on: m_level, m_first_program
    'm_room_type',           // Depends on: m_room_type_group, m_level
    'm_activity_type',       // Depends on: m_first_program
    'm_activity_type_detail', // Depends on: m_activity_type, m_first_program
    'm_insert_point',        // Depends on: m_first_program, m_level
    'm_role',                 // Depends on: m_first_program
    'm_visibility',           // Depends on: m_activity_type_detail, m_role
    'm_supported_plan',       // Depends on: m_first_program
    'm_news',                // No dependencies
    'm_parameter_condition',  // Depends on: m_parameter (CASCADE, so order matters less)
];
```

**Strategy:**
1. Process tables in dependency order
2. For each table: UPDATE → INSERT → DELETE (in that order)
3. This ensures FK references are valid before deleting

## Implementation Considerations

### 1. Transaction Safety

```php
DB::transaction(function() use ($jsonData, $tables) {
    foreach ($mTableOrder as $table) {
        updateMTable($table, $jsonData[$table]);
    }
});
```

### 2. Rollback on Error

If any step fails, rollback entire update.

### 3. Validation Before Delete

```php
function canDeleteSafely($table, $id): bool {
    // Check if referenced by operational data with RESTRICT
    $restrictReferences = findRestrictReferences($table, $id);
    
    if (empty($restrictReferences)) {
        return true; // Safe to delete (CASCADE will handle it)
    }
    
    // Has RESTRICT references - need to handle
    return false;
}
```

### 4. Logging and Reporting

```php
$report = [
    'updated' => [],
    'inserted' => [],
    'deleted' => [],
    'skipped' => [], // RESTRICT constraints
    'errors' => []
];

// Report what happened
echo "📊 Update Summary:\n";
echo "  Updated: " . count($report['updated']) . " records\n";
echo "  Inserted: " . count($report['inserted']) . " records\n";
echo "  Deleted: " . count($report['deleted']) . " records\n";
echo "  Skipped (RESTRICT): " . count($report['skipped']) . " records\n";
```

## Comparison: Current vs Proposed

| Aspect | Current (Drop & Recreate) | Proposed (Incremental Update) |
|--------|---------------------------|-------------------------------|
| **FK Checks** | ❌ Disabled | ✅ Enabled (maintains integrity) |
| **Data Loss** | ⚠️ All data not in JSON lost | ✅ Only removed records deleted |
| **FK Handling** | ❌ Bypassed | ✅ Properly handled (CASCADE/RESTRICT) |
| **Performance** | ⚠️ Slow (drop/create) | ✅ Faster (update only changed) |
| **Rollback** | ⚠️ Difficult | ✅ Transaction-based |
| **Complexity** | ✅ Simple | ⚠️ More complex (FK logic) |
| **Safety** | ⚠️ Less safe | ✅ Safer (validates constraints) |

## Questions to Discuss - DECISIONS

1. **RESTRICT Constraint Handling**: ✅ **Option C - Block deployment (fail fast)**
   - If an m-table record is removed from JSON but still referenced by operational data with RESTRICT constraint, deployment will fail
   - This ensures data integrity and forces manual resolution

2. **Dependency Order**: ✅ **Dynamic discovery from FK relationships**
   - Discover dependency order automatically from database FK constraints
   - More maintainable than hardcoding

3. **CASCADE Delete Behavior**: ✅ **Yes - Cascades are defined in DB**
   - Operational data will be automatically deleted when m-table records are removed (Pattern 2)
   - This is by design and acceptable

4. **Validation**: ✅ **Yes - Should avoid need for rollbacks**
   - Validate JSON structure and data integrity before starting update
   - Catch issues early to prevent partial updates

5. **Dry-Run Mode**: ❌ **No**
   - Not needed for this use case

6. **Migration Handling**: ⚠️ **Needs clarification** (see explanation below)

## Critical Issue: ID Mismatch

### The Problem

When inserting new records into the target database, MySQL will auto-generate IDs if:
- The table uses `AUTO_INCREMENT`
- We don't explicitly set the `id` value

**Example:**
- JSON has: `m_level` with `id=5, name="Regional"`
- Target DB already has: `m_level` with `id=1, name="Regional"` and `id=2, name="National"`
- If we INSERT without specifying ID, MySQL might assign `id=3`
- But `event.level=5` references the JSON ID, not the new DB ID!

### Current Behavior (MainDataSeeder)

Looking at `MainDataSeeder.php` line 256-260:
```php
if ($hasId && isset($filteredItem['id'])) {
    DB::table($table)->updateOrInsert(
        ['id' => $filteredItem['id']],
        $filteredItem
    );
}
```

**Good news**: It uses `updateOrInsert` with explicit ID, so IDs ARE preserved!

**But**: This only works if:
1. The ID doesn't already exist in the target DB (for INSERT)
2. MySQL allows explicit ID insertion (requires `AUTO_INCREMENT` handling)

### Solution: Explicit ID Insertion

We need to ensure IDs from JSON are preserved:

```php
// For INSERT operations
DB::statement("SET @OLD_AUTO_INCREMENT = (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?)", [$table]);
DB::table($table)->insert($recordWithId); // Explicitly set ID
DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = @OLD_AUTO_INCREMENT");
```

Or simpler approach:
```php
// Temporarily disable auto-increment behavior
DB::statement("SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
DB::table($table)->insert($recordWithId); // ID from JSON is used
DB::statement("SET sql_mode = DEFAULT");
```

### Validation: Check ID Consistency

Before starting update, validate:
```php
function validateIdConsistency($jsonData, $tables): array {
    $issues = [];
    
    foreach ($tables as $table) {
        $jsonIds = array_column($jsonData[$table], 'id');
        $dbIds = DB::table($table)->pluck('id')->toArray();
        
        // Check for ID conflicts
        $conflicts = array_intersect($jsonIds, $dbIds);
        if (!empty($conflicts)) {
            // IDs exist in both - will UPDATE, not INSERT (good)
        }
        
        // Check for gaps that might cause auto-increment issues
        $maxJsonId = max($jsonIds);
        $maxDbId = max($dbIds ?: [0]);
        
        if ($maxJsonId > $maxDbId) {
            // Need to ensure auto-increment is high enough
            $issues[] = [
                'table' => $table,
                'action' => 'adjust_auto_increment',
                'current_max' => $maxDbId,
                'needed_max' => $maxJsonId
            ];
        }
    }
    
    return $issues;
}
```

## Question 6: Migration Handling - Detailed Explanation

### The Scenario

**Situation**: m-table schema changes (e.g., new column added, column type changed)

**Example**:
- Dev: `m_level` table has new column `description` (added via migration)
- JSON export: Contains `m_level` records with `description` field
- Target DB: `m_level` table doesn't have `description` column yet

### Current Process

1. **Migrations run first** (in `deploy-finalize.sh`):
   ```bash
   php artisan migrate --force
   ```
   This updates the schema to match dev.

2. **Then m-tables are refreshed**:
   ```bash
   php artisan tinker --execute="refreshMTables()"
   ```
   This drops and recreates tables.

### With New Approach (Incremental Update)

**Question**: Do we still need migrations to run first?

**Answer**: **YES** - because:

1. **Schema must match first**: If JSON has new columns that don't exist in target DB, INSERT/UPDATE will fail
2. **Column types must match**: If a column type changed (e.g., `varchar(50)` → `varchar(100)`), we need migration first
3. **New tables**: If a new m-table was added, migration creates it

### Proposed Flow

```php
// Step 1: Run migrations (updates schema)
php artisan migrate --force

// Step 2: Validate schema matches JSON structure
validateSchemaMatchesJson($jsonData);

// Step 3: Update m-tables from JSON (incremental)
updateMTablesFromJson($jsonPath);
```

### Schema Validation

Before updating data, validate schema:

```php
function validateSchemaMatchesJson($jsonData): void {
    foreach ($jsonData['_metadata']['tables'] as $table) {
        $jsonColumns = array_keys($jsonData[$table][0] ?? []);
        $dbColumns = Schema::getColumnListing($table);
        
        $missingColumns = array_diff($jsonColumns, $dbColumns);
        if (!empty($missingColumns)) {
            throw new \Exception(
                "Table {$table} is missing columns: " . implode(', ', $missingColumns) . 
                ". Please run migrations first."
            );
        }
    }
}
```

### Decision: ✅ **Option A + B** - Run migrations first, validate schema matches JSON

**Implementation:**
1. Always run migrations first (ensures schema matches)
2. Validate schema matches JSON structure before updating data
3. Fail fast with clear error message if schema doesn't match

**Benefits:**
- ✅ Safe - ensures schema matches before data update
- ✅ Simple - clear separation of concerns
- ✅ Fails fast with helpful message if schema is out of sync
- ✅ Prevents partial updates that would require rollback

## Proposed Function Signature

```php
/**
 * Update m-tables from JSON without dropping tables or disabling FK checks
 * 
 * @param string $jsonPath Path to main-tables-latest.json
 * @param bool $dryRun If true, only report changes without applying them
 * @param string $restrictStrategy 'keep'|'update'|'fail' - how to handle RESTRICT constraints
 * @return array Report of changes made
 */
function updateMTablesFromJson(
    string $jsonPath,
    bool $dryRun = false,
    string $restrictStrategy = 'keep'
): array;
```

## Next Steps

1. Decide on RESTRICT constraint handling strategy
2. Implement dependency order discovery or hardcode it
3. Create the update function
4. Add validation and error handling
5. Test with various scenarios (new records, deleted records, updated records)
6. Integrate into deployment script

