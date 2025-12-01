# Migration Deployment Strategy Explanation

## Overview

This document explains how the deployment script handles Laravel migrations, ensuring:
1. **Next deployment doesn't change anything** if no new migrations exist
2. **Future deployments execute new migrations** automatically
3. **Production sync** to master migration works correctly

---

## How Laravel Migrations Work

### The `migrations` Table

Laravel tracks which migrations have been run in a `migrations` table:

```sql
CREATE TABLE migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL
);
```

**Example records:**
```
id | migration                                    | batch
---|----------------------------------------------|------
1  | 2025_01_01_000000_create_master_tables      | 1
2  | 2025_08_14_063522_create_jobs_table         | 1
3  | 2025_09_10_061841_create_s_generator_table  | 1
...
```

### How `php artisan migrate` Works

1. **Scans** `database/migrations/` directory for migration files
2. **Compares** filenames with records in `migrations` table
3. **Runs only** migrations that are NOT in the table
4. **Records** each executed migration in the `migrations` table

**Key Point:** If a migration is already recorded, Laravel **skips it** (idempotent).

---

## Current Deployment Script Behavior

### Step-by-Step Flow

```bash
# 1. Database backup (production only)
💾 Creating database backup before deployment...

# 2. Fix migration records (production only - first time sync)
🔧 Fixing migration records for existing tables...
# This ensures the master migration is recorded if production was synced manually

# 3. Check migration status (production only)
📊 Checking migration status before running...

# 4. Run migrations
🔄 Running migrations...
php artisan migrate --force
# Laravel automatically:
#   - Checks which migrations are already run
#   - Runs ONLY new migrations
#   - Records new migrations in migrations table

# 5. Verify migrations (production only)
✅ Verifying migrations...
```

### Code Location

**File:** `backend/scripts/deploy-finalize.sh`

**Lines 108-118:**
```bash
# Run migrations (MUST run before updating m-tables to ensure schema matches JSON)
echo "🔄 Running migrations..."
php artisan migrate --force || {
  echo "❌ ERROR: Migrations failed!"
  if [ "$VERIFY_MIGRATIONS" == "true" ]; then
    echo "📊 Checking migration status after failure..."
    php artisan migrate:status || true
  fi
  exit 1
}
echo "✓ Migrations completed successfully"
```

---

## Scenario 1: Next Deployment (No New Migrations)

### What Happens

1. **Deployment runs** `php artisan migrate --force`
2. **Laravel checks** `migrations` table
3. **All migrations are already recorded** (from previous deployments)
4. **Laravel outputs:** `Nothing to migrate.`
5. **No database changes** occur ✅

### Example Output

```bash
🔄 Running migrations...
Nothing to migrate.
✓ Migrations completed successfully
```

**Result:** Database schema remains unchanged, deployment continues normally.

---

## Scenario 2: Future Deployment (New Migrations Added)

### What Happens

1. **Developer creates** new migration: `2025_12_01_120000_add_new_feature.php`
2. **Migration file** is committed to repository
3. **Deployment runs** `php artisan migrate --force`
4. **Laravel detects** new migration file not in `migrations` table
5. **Laravel runs** the new migration
6. **Laravel records** it in `migrations` table
7. **Database schema** is updated ✅

### Example Output

```bash
🔄 Running migrations...
Migrating: 2025_12_01_120000_add_new_feature
Migrated:  2025_12_01_120000_add_new_feature (XX.XXms)
✓ Migrations completed successfully
```

**Result:** New migration is applied automatically.

---

## Scenario 3: Production Sync to Master Migration

### The Problem

Production database was synced manually to match the master migration (`2025_01_01_000000_create_master_tables.php`), but:
- The `migrations` table may not exist
- Or it may not have the correct records
- Or it may have old/incorrect migration records

### The Solution: `fix_migration_records.php`

**When:** Runs only for production (`FIX_MIGRATION_RECORDS=true`)

**What it does:**
1. **Ensures** `migrations` table exists
2. **Checks** which migrations should be recorded (based on files in `database/migrations/`)
3. **Adds missing records** for migrations that were run manually
4. **Removes incorrect records** for migrations that don't exist

**Code:** `backend/database/scripts/fix_migration_records.php`

**Called from:** `deploy-finalize.sh` lines 94-100

```bash
# Fix migration records (production only)
if [ "$FIX_MIGRATION_RECORDS" == "true" ]; then
  echo "🔧 Fixing migration records for existing tables..."
  php artisan tinker --execute="include 'database/scripts/fix_migration_records.php'; fixMigrationRecords();" || {
    echo "⚠️  WARNING: fix_migration_records.php failed, but continuing..."
  }
fi
```

### After First Production Sync

1. **First deployment** after sync:
   - `fix_migration_records.php` ensures all existing migrations are recorded
   - `php artisan migrate` runs but finds nothing new (all recorded)
   - Database unchanged ✅

2. **Subsequent deployments**:
   - `fix_migration_records.php` still runs (but does nothing if already correct)
   - `php artisan migrate` runs and applies any new migrations
   - Works normally ✅

---

## Migration File Structure

### Master Migration

**File:** `backend/database/migrations/2025_01_01_000000_create_master_tables.php`

**Purpose:** Defines the complete database schema as of the sync point.

**Key Characteristics:**
- **Drops and recreates** m_ tables (master data tables)
- **Creates** all other tables with their current structure
- **Defines** all foreign keys and constraints
- **Is idempotent** - safe to run multiple times

### Subsequent Migrations

**Examples:**
- `2025_08_14_063522_create_jobs_table.php` - Adds new table
- `2025_09_10_080000_convert_datetime_to_timestamp.php` - Modifies existing columns
- `2025_10_13_150204_update_q_run_table_schema.php` - Drops and recreates table

**All migrations are:**
- **Timestamped** (YYYY_MM_DD_HHMMSS format)
- **Idempotent** (safe to run multiple times)
- **Tracked** in `migrations` table

---

## Ensuring No Changes on Next Deployment

### How It Works

1. **Laravel's built-in mechanism:**
   - `php artisan migrate` only runs migrations NOT in `migrations` table
   - If all migrations are recorded, nothing happens

2. **Verification step** (production only):
   ```bash
   # Check migration status before running (production only)
   if [ "$VERIFY_MIGRATIONS" == "true" ]; then
     echo "📊 Checking migration status before running..."
     php artisan migrate:status || echo "ℹ️  Note: migrate:status may fail if migrations table doesn't exist yet"
   fi
   ```

3. **Post-migration verification** (production only):
   - Counts migration records
   - Verifies table count
   - Lists recent migrations

### Example: No New Migrations

```bash
📊 Checking migration status before running...
+------+------------------------------------------+-------+
| Ran? | Migration                                | Batch |
+------+------------------------------------------+-------+
| Yes  | 2025_01_01_000000_create_master_tables  | 1     |
| Yes  | 2025_08_14_063522_create_jobs_table       | 1     |
| Yes  | 2025_09_10_061841_create_s_generator_table| 1     |
| ...  | (all migrations listed)                 | ...   |
+------+------------------------------------------+-------+

🔄 Running migrations...
Nothing to migrate.
✓ Migrations completed successfully

✅ Verifying migrations...
📊 Counting migration records in database...
Migration records in database: 45
✓ Table count verification passed (45 tables)
```

**Result:** No database changes, deployment continues.

---

## Ensuring Future Migrations Execute

### How It Works

1. **New migration file** is added to `database/migrations/`
2. **File is committed** to repository
3. **Deployment runs** `php artisan migrate --force`
4. **Laravel detects** file not in `migrations` table
5. **Laravel runs** the migration automatically
6. **Laravel records** it in `migrations` table

### Example: New Migration Added

```bash
📊 Checking migration status before running...
+------+------------------------------------------+-------+
| Ran? | Migration                                | Batch |
+------+------------------------------------------+-------+
| Yes  | 2025_01_01_000000_create_master_tables  | 1     |
| ...  | (existing migrations)                   | ...   |
| No   | 2025_12_01_120000_add_new_feature       | -     |  ← NEW!
+------+------------------------------------------+-------+

🔄 Running migrations...
Migrating: 2025_12_01_120000_add_new_feature
Migrated:  2025_12_01_120000_add_new_feature (15.23ms)
✓ Migrations completed successfully

✅ Verifying migrations...
📊 Counting migration records in database...
Migration records in database: 46  ← Increased!
✓ Table count verification passed (46 tables)  ← Increased!
```

**Result:** New migration applied automatically.

---

## Environment-Specific Behavior

### Development (`main` branch)

```yaml
fix_migration_records: false
verify_migrations: false
```

- **No** migration record fixing
- **No** migration verification
- **Just runs** `php artisan migrate --force`
- **Applies** any new migrations automatically

### Test (`test` branch)

```yaml
fix_migration_records: false
verify_migrations: false
```

- **Same as dev** - simple migration execution
- **Applies** any new migrations automatically

### Production (release)

```yaml
fix_migration_records: true
verify_migrations: true
```

- **Fixes** migration records (ensures sync state is recorded)
- **Verifies** migration status before and after
- **Applies** any new migrations automatically
- **More thorough** verification and logging

---

## Key Safety Features

### 1. Idempotent Migrations

All migrations should be **idempotent** (safe to run multiple times):

```php
// ✅ GOOD: Checks if table exists before creating
if (!Schema::hasTable('new_table')) {
    Schema::create('new_table', function (Blueprint $table) {
        // ...
    });
}

// ✅ GOOD: Checks if column exists before adding
if (!Schema::hasColumn('table', 'new_column')) {
    Schema::table('table', function (Blueprint $table) {
        $table->string('new_column')->nullable();
    });
}
```

### 2. Transaction Safety

- Migrations run in **transactions** (if database supports it)
- **Rollback** on error
- **No partial** schema changes

### 3. Force Flag

```bash
php artisan migrate --force
```

- **Skips** confirmation prompts
- **Required** for automated deployments
- **Safe** because migrations are idempotent

### 4. Verification Steps

Production deployments include:
- **Pre-migration** status check
- **Post-migration** verification
- **Table count** validation
- **Migration record** counting

---

## Summary

### How Next Deployment Won't Change Anything

✅ **Laravel's built-in mechanism:**
- `php artisan migrate` only runs migrations NOT in `migrations` table
- If all migrations are already recorded → nothing happens
- Output: `Nothing to migrate.`

### How Future Migrations Will Execute

✅ **Automatic detection:**
- New migration files are detected automatically
- Laravel compares files with `migrations` table
- Unrecorded migrations are executed automatically
- New migrations are recorded in `migrations` table

### Production Sync Handling

✅ **First deployment after sync:**
- `fix_migration_records.php` ensures all existing migrations are recorded
- Subsequent `php artisan migrate` finds nothing new
- Database unchanged

✅ **Future deployments:**
- `fix_migration_records.php` ensures records are correct (if needed)
- `php artisan migrate` applies any new migrations
- Works normally

---

## Best Practices

1. **Always make migrations idempotent** (check before creating/modifying)
2. **Test migrations on dev/test** before production
3. **Use descriptive migration names** (include what they do)
4. **Keep migrations small** (one logical change per migration)
5. **Never modify existing migrations** (create new ones instead)
6. **Backup production** before migrations (already automated)

---

## Troubleshooting

### Issue: Migration runs every time (should be idempotent)

**Solution:** Add existence checks:
```php
if (!Schema::hasTable('table_name')) {
    Schema::create('table_name', ...);
}
```

### Issue: Migration fails on production

**Solution:** 
- Check error logs
- Verify database permissions
- Check foreign key constraints
- Restore from backup if needed

### Issue: Migration not running

**Solution:**
- Check `migrations` table - is it already recorded?
- Verify migration file is in `database/migrations/`
- Check file naming (must match Laravel format)
- Run `php artisan migrate:status` to see what's pending

---

## Conclusion

The deployment script handles migrations **safely and automatically**:

1. ✅ **Next deployment** (no new migrations): Nothing changes (Laravel skips already-run migrations)
2. ✅ **Future deployments** (new migrations): New migrations are applied automatically
3. ✅ **Production sync**: `fix_migration_records.php` ensures state is correctly recorded

**No manual intervention needed** - the system is fully automated and safe.

