# Migration Path for Existing Databases (TST/PRD)

## The Problem

✅ **Master Migration**: Works perfectly for **new installations** (fresh databases)

❌ **Existing Databases (TST/PRD)**: May be in inconsistent states because:
1. Some migrations were run, others weren't
2. Direct SQL tweaks were applied
3. Tables exist but have wrong structure (columns, FKs, data types)
4. Master migration uses `if (!Schema::hasTable(...))` - **skips existing tables**

## The Challenge

The master migration is **idempotent** but **only creates missing tables**. It does NOT:
- ❌ Fix existing tables with wrong structure
- ❌ Add missing columns to existing tables
- ❌ Fix foreign keys on existing tables
- ❌ Update data types on existing tables
- ❌ Add missing indexes

## Solution Strategy

### Phase 1: **Schema Comparison & Analysis**

**Goal**: Compare TST/PRD databases with the master migration to identify differences.

**Steps**:
1. Export schema from TST database
2. Export schema from PRD database
3. Compare with master migration expectations
4. Generate discrepancy report

**Tools**:
```bash
# Export TST schema
mysqldump --no-data --routines --triggers tst_database > tst_schema.sql

# Export PRD schema
mysqldump --no-data --routines --triggers prd_database > prd_schema.sql

# Use our existing script
php database/scripts/export_dev_schema.php --database=tst
php database/scripts/export_dev_schema.php --database=prd
```

### Phase 2: **Create "Sync" Migrations**

**Goal**: Create migrations that bring existing databases to match master schema.

**Approach**: Create migrations that:
1. Check if table exists AND has wrong structure
2. Alter table to match master
3. Are idempotent (safe to run multiple times)

**Example Pattern**:
```php
// Example: Fix team table
Schema::table('team', function (Blueprint $table) {
    // Add missing column if it doesn't exist
    if (!Schema::hasColumn('team', 'name')) {
        $table->string('name', 255)->nullable()->after('id');
    }
    
    // Remove obsolete column if it exists
    if (Schema::hasColumn('team', 'room')) {
        $table->dropForeign(['room']);
        $table->dropColumn('room');
    }
    
    // Fix FK delete rule
    // (Need to drop and recreate FK)
    try {
        $table->dropForeign(['event']);
    } catch (\Exception $e) {
        // FK might not exist or have different name
    }
    $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
});
```

### Phase 3: **Create Comprehensive Sync Migration**

**Option A: One Big Sync Migration** (Recommended)
- Create `2025_01_02_000000_sync_existing_databases_to_master.php`
- Contains all fixes for all tables
- Idempotent checks for each change
- Safe to run on any existing database

**Option B: Per-Table Sync Migrations**
- Create separate migration for each table
- More granular but more files
- Easier to test individual tables

**Option C: Automated Sync Script**
- Script that compares schema and generates fixes
- More complex but automated
- Could generate migration file automatically

## Recommended Approach: **Comprehensive Sync Migration**

### Structure

```php
<?php
// 2025_01_02_000000_sync_existing_databases_to_master.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // For each table that might need fixes:
        $this->syncTeamTable();
        $this->syncTeamPlanTable();
        $this->syncUserTable();
        // ... etc for all 46 tables
    }
    
    private function syncTeamTable(): void
    {
        if (!Schema::hasTable('team')) {
            return; // Table doesn't exist, master migration will create it
        }
        
        Schema::table('team', function (Blueprint $table) {
            // Add missing columns
            if (!Schema::hasColumn('team', 'name')) {
                $table->string('name', 100)->after('id');
            }
            if (!Schema::hasColumn('team', 'email')) {
                $table->string('email', 255)->nullable()->after('name');
            }
            
            // Remove obsolete columns
            if (Schema::hasColumn('team', 'room')) {
                try {
                    $table->dropForeign(['room']);
                } catch (\Exception $e) {}
                $table->dropColumn('room');
            }
            if (Schema::hasColumn('team', 'noshow')) {
                $table->dropColumn('noshow');
            }
            
            // Fix data types
            // (Need to use DB::statement for ALTER COLUMN)
            if (Schema::hasColumn('team', 'team_number_hot')) {
                // Check current type and fix if needed
                $column = DB::select("SHOW COLUMNS FROM team WHERE Field = 'team_number_hot'");
                if ($column[0]->Null === 'YES') {
                    DB::statement('ALTER TABLE team MODIFY COLUMN team_number_hot INT(11) NOT NULL');
                }
            }
            
            // Fix foreign keys
            $this->fixForeignKey('team', 'event', 'event', 'id', 'cascade');
            $this->fixForeignKey('team', 'first_program', 'm_first_program', 'id', 'restrict');
        });
    }
    
    private function fixForeignKey(
        string $table,
        string $column,
        string $refTable,
        string $refColumn,
        string $onDelete
    ): void {
        // Drop existing FK if it exists
        $fks = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ", [$table, $column]);
        
        foreach ($fks as $fk) {
            try {
                DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");
            } catch (\Exception $e) {
                // Ignore if FK doesn't exist
            }
        }
        
        // Create new FK with correct rule
        try {
            DB::statement("
                ALTER TABLE {$table} 
                ADD CONSTRAINT {$table}_{$column}_foreign 
                FOREIGN KEY ({$column}) 
                REFERENCES {$refTable}({$refColumn}) 
                ON DELETE {$onDelete}
            ");
        } catch (\Exception $e) {
            // FK might already exist or table doesn't exist
        }
    }
};
```

## Implementation Steps

### Step 1: **Analyze TST Database**
```bash
# Export TST schema
php database/scripts/export_dev_schema.php --database=tst --output=tst_schema.md

# Compare with master migration expectations
# Create discrepancy list
```

### Step 2: **Analyze PRD Database**
```bash
# Export PRD schema (if accessible)
php database/scripts/export_dev_schema.php --database=prd --output=prd_schema.md

# Compare with master migration expectations
# Create discrepancy list
```

### Step 3: **Create Sync Migration**
- Based on discrepancies found
- Use idempotent pattern (check before change)
- Test on TST first
- Then apply to PRD

### Step 4: **Test Strategy**
1. **Test on TST**:
   ```bash
   # Backup TST
   mysqldump tst_database > tst_backup.sql
   
   # Run sync migration
   php artisan migrate --path=database/migrations/2025_01_02_000000_sync_existing_databases_to_master.php
   
   # Verify schema matches master
   php database/scripts/export_dev_schema.php --database=tst
   # Compare with Dev schema
   ```

2. **Test on PRD** (after TST verified):
   ```bash
   # Backup PRD
   mysqldump prd_database > prd_backup.sql
   
   # Run sync migration
   php artisan migrate --path=database/migrations/2025_01_02_000000_sync_existing_databases_to_master.php
   
   # Verify schema matches master
   ```

## Alternative: **Schema Diff Tool**

Create a tool that:
1. Compares existing database with master migration
2. Generates ALTER statements automatically
3. Creates migration file with fixes

**Example**:
```php
// database/scripts/generate_sync_migration.php
$masterSchema = parseMasterMigration();
$existingSchema = exportDatabaseSchema('tst');
$differences = compareSchemas($masterSchema, $existingSchema);
$migration = generateMigration($differences);
file_put_contents('sync_migration.php', $migration);
```

## Risk Mitigation

### Before Running Sync Migration:
1. ✅ **Full database backup**
2. ✅ **Test on TST first**
3. ✅ **Verify no data loss**
4. ✅ **Test application functionality**
5. ✅ **Document rollback procedure**

### Rollback Plan:
```bash
# If sync migration fails
php artisan migrate:rollback --step=1

# Restore from backup if needed
mysql tst_database < tst_backup.sql
```

## Summary

**The Problem**: Existing databases (TST/PRD) may have inconsistent schemas that the master migration won't fix (because it only creates missing tables).

**The Solution**: Create a **sync migration** that:
1. Checks existing table structures
2. Alters them to match master schema
3. Is idempotent (safe to run multiple times)
4. Fixes columns, FKs, data types, indexes

**Next Steps**:
1. Export and compare TST/PRD schemas with master
2. Create comprehensive sync migration
3. Test on TST
4. Apply to PRD after verification

Would you like me to:
1. Create a script to analyze TST/PRD schemas?
2. Generate the sync migration based on discrepancies?
3. Create a tool to automatically generate sync migrations?

