# Next Steps: Applying Master Migration to Dev, Test, and Prod

## Current State

✅ **Master Migration**: Cleaned and ready (`2025_01_01_000000_create_master_tables.php`)
- Contains all 46 reviewed tables
- Removed obsolete tables (`plan_extra_block`, `q_plan_match`)
- All cascade delete chains verified
- All foreign key rules verified

📋 **Databases**: Dev, Test, and Prod in various states
- May have inconsistent schemas
- Some migrations may have been run, others not
- Direct SQL tweaks may have been applied
- Master migration only creates missing tables (doesn't alter existing ones)

## Phase 1: Verify Master Migration (Dev First)

### Step 1.1: Test Master Migration on Fresh Database
**Purpose**: Verify master migration works correctly for new installations

```bash
# Create test database
mysql -e "CREATE DATABASE test_fresh_master;"

# Update .env temporarily
DB_DATABASE=test_fresh_master

# Run master migration
php artisan migrate:fresh --path=database/migrations/2025_01_01_000000_create_master_tables.php

# Export schema
php database/scripts/export_dev_schema.php --database=test_fresh_master --output=fresh_schema.md

# Compare with Dev DB schema
# Should match exactly
```

**Success Criteria**:
- ✅ Migration runs without errors
- ✅ All 46 tables created
- ✅ All foreign keys created correctly
- ✅ Schema matches Dev DB exactly

### Step 1.2: Test Master Migration on Existing Dev Database
**Purpose**: Verify idempotency (safe to run on existing database)

```bash
# Ensure you're on Dev database
# Run master migration
php artisan migrate --path=database/migrations/2025_01_01_000000_create_master_tables.php

# Should run without errors (tables already exist, so it skips them)
```

**Success Criteria**:
- ✅ Migration runs without errors
- ✅ No duplicate tables created
- ✅ Existing data preserved
- ✅ No schema changes (expected - it only creates missing tables)

## Phase 2: Analyze Existing Databases

### Step 2.1: Export Schemas from All Databases
**Purpose**: Compare actual schemas with master migration expectations

```bash
# Export Dev schema (baseline)
php database/scripts/export_dev_schema.php --database=dev --output=dev_schema.md

# Export Test schema
php database/scripts/export_dev_schema.php --database=test --output=test_schema.md

# Export Prod schema (if accessible)
php database/scripts/export_dev_schema.php --database=prod --output=prod_schema.md
```

### Step 2.2: Compare Schemas
**Purpose**: Identify discrepancies between actual schemas and master migration

**Compare**:
- Missing tables
- Missing columns
- Wrong data types
- Missing foreign keys
- Wrong foreign key delete rules
- Missing indexes
- Obsolete columns/tables

**Tools**:
- Manual comparison of exported schemas
- Or create automated comparison script

## Phase 3: Create Sync Migration

### Step 3.1: Design Sync Migration
**Purpose**: Create migration that brings existing databases to match master schema

**Approach**: Create `2025_01_02_000000_sync_existing_databases_to_master.php`

**Pattern**:
```php
// For each table that might need fixes:
if (Schema::hasTable('table_name')) {
    Schema::table('table_name', function (Blueprint $table) {
        // Add missing columns
        if (!Schema::hasColumn('table_name', 'column_name')) {
            $table->string('column_name', 255)->nullable();
        }
        
        // Remove obsolete columns
        if (Schema::hasColumn('table_name', 'obsolete_column')) {
            $table->dropColumn('obsolete_column');
        }
        
        // Fix foreign keys
        // (Drop and recreate with correct delete rule)
    });
}
```

### Step 3.2: Implement Sync Migration
**Based on discrepancies found in Phase 2**

**Key Areas to Address**:
1. **Missing columns** - Add columns that exist in master but not in existing DBs
2. **Obsolete columns** - Remove columns that don't exist in master
3. **Data types** - Fix incorrect data types (e.g., bigint → unsignedInteger)
4. **Foreign keys** - Add missing FKs, fix delete rules
5. **Indexes** - Add missing indexes
6. **Obsolete tables** - Remove tables that don't exist in master

**Make it Idempotent**:
- Check before adding (if column doesn't exist)
- Check before removing (if column exists)
- Handle errors gracefully (try-catch)

## Phase 4: Test Sync Migration

### Step 4.1: Test on Dev Database
**Purpose**: Verify sync migration works on Dev (should have minimal changes)

```bash
# Backup Dev database
mysqldump dev_database > dev_backup_$(date +%Y%m%d_%H%M%S).sql

# Run sync migration
php artisan migrate --path=database/migrations/2025_01_02_000000_sync_existing_databases_to_master.php

# Verify schema matches master
php database/scripts/export_dev_schema.php --database=dev --output=dev_schema_after_sync.md
# Compare with master expectations
```

**Success Criteria**:
- ✅ Migration runs without errors
- ✅ Schema matches master migration
- ✅ No data loss
- ✅ Application still works

### Step 4.2: Test on Test Database
**Purpose**: Verify sync migration works on Test (may have more discrepancies)

```bash
# Backup Test database
mysqldump test_database > test_backup_$(date +%Y%m%d_%H%M%S).sql

# Run sync migration
php artisan migrate --path=database/migrations/2025_01_02_000000_sync_existing_databases_to_master.php

# Verify schema matches master
php database/scripts/export_dev_schema.php --database=test --output=test_schema_after_sync.md
# Compare with master expectations
```

**Success Criteria**:
- ✅ Migration runs without errors
- ✅ Schema matches master migration
- ✅ No data loss
- ✅ Application still works

## Phase 5: Deploy to Production

### Step 5.1: Final Verification
**Before deploying to Prod**:
- ✅ Sync migration tested on Dev
- ✅ Sync migration tested on Test
- ✅ All discrepancies resolved
- ✅ Application tested on Test after sync
- ✅ Rollback plan documented

### Step 5.2: Deploy to Prod
```bash
# Backup Prod database (CRITICAL!)
mysqldump prod_database > prod_backup_$(date +%Y%m%d_%H%M%S).sql

# Run sync migration
php artisan migrate --path=database/migrations/2025_01_02_000000_sync_existing_databases_to_master.php

# Verify schema matches master
php database/scripts/export_dev_schema.php --database=prod --output=prod_schema_after_sync.md
# Compare with master expectations
```

**Success Criteria**:
- ✅ Migration runs without errors
- ✅ Schema matches master migration
- ✅ No data loss
- ✅ Application still works
- ✅ Monitor for issues

## Rollback Plan

### If Sync Migration Fails:
```bash
# Rollback migration
php artisan migrate:rollback --step=1

# Or restore from backup
mysql prod_database < prod_backup_YYYYMMDD_HHMMSS.sql
```

## Summary of Next Steps

1. **✅ Verify Master Migration** (Phase 1)
   - Test on fresh database
   - Test on existing Dev database

2. **📊 Analyze Existing Databases** (Phase 2)
   - Export schemas from Dev, Test, Prod
   - Compare with master migration
   - Identify discrepancies

3. **🔧 Create Sync Migration** (Phase 3)
   - Based on discrepancies found
   - Make it idempotent
   - Handle all table fixes

4. **🧪 Test Sync Migration** (Phase 4)
   - Test on Dev first
   - Test on Test
   - Verify no data loss

5. **🚀 Deploy to Production** (Phase 5)
   - Backup first!
   - Run sync migration
   - Verify and monitor

## Questions to Answer Before Starting

1. **Do we have access to all three databases?**
   - Dev: ✅ (assumed yes)
   - Test: ❓
   - Prod: ❓

2. **Can we create test databases?**
   - For fresh database testing

3. **What's the backup strategy?**
   - Automated backups?
   - Manual backups before migration?

4. **What's the deployment window?**
   - Maintenance window for Prod?
   - Can we test during business hours?

5. **Who needs to be involved?**
   - DBA for database access?
   - DevOps for deployment?
   - QA for testing?

## Recommended Order

1. **Start with Dev** - Lowest risk, can iterate quickly
2. **Then Test** - More realistic, may have more discrepancies
3. **Finally Prod** - Highest risk, needs thorough testing first

## Tools Needed

1. **Schema Export Script** - Already exists (`export_dev_schema.php`)
2. **Schema Comparison Tool** - May need to create
3. **Sync Migration Generator** - May need to create (or manual)

Would you like me to:
1. Create a script to compare schemas automatically?
2. Start creating the sync migration based on known discrepancies?
3. Create a verification script to check schema matches?

