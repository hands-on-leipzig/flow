# Database Schema Sync Scripts

These scripts help sync any database (Dev, Test, Prod) to match the master migration schema.

## Master Migration

**File:** `database/migrations/2025_01_01_000000_create_master_tables.php`

This is the **ultimate truth** - the desired, clean database schema. All databases should match this.

## Schema Exports

**Files:** `dev_schema.md`, `test_schema.md` (in project root)

These are snapshots of the current database schemas, exported for comparison.

## Scripts

### 1. Export Schema

**File:** `export_schema.php`

Exports the currently connected database schema to a Markdown file.

```php
php artisan tinker
>>> include 'database/scripts/export_schema.php';
>>> exportSchema('dev');  // or 'test', 'prod'
```

**Output:** `{environment}_schema.md` in project root

### 2. Compare Schema to Master

**File:** `compare_schema_to_master.php`

Compares the exported schema against the master migration and reports all differences.

```php
php artisan tinker
>>> include 'database/scripts/compare_schema_to_master.php';
>>> compareSchemaToMaster('dev');  // or 'test', 'prod'
```

**Output:** Detailed comparison report showing:
- Missing tables
- Missing/extra columns
- Type mismatches
- Nullable mismatches
- Default mismatches
- Missing/wrong foreign keys
- Missing/extra indexes

### 3. Generate Sync Migration

**File:** `generate_sync_migration.php`

Generates a Laravel migration to sync the current database to match the master migration.

```php
php artisan tinker
>>> include 'database/scripts/generate_sync_migration.php';
>>> generateSyncMigration('dev');  // or 'test', 'prod'
```

**Output:** Migration file in `database/migrations/` with timestamp

**Next Steps:**
1. Review the generated migration
2. Test on a backup database if possible
3. Run: `php artisan migrate`
4. Verify with: `compareSchemaToMaster('dev')`

## Workflow

### For a New Environment Sync:

1. **Connect to the target database** (update `.env` or database config)

2. **Export current schema:**
   ```php
   >>> include 'database/scripts/export_schema.php';
   >>> exportSchema('test');
   ```

3. **Compare to master:**
   ```php
   >>> include 'database/scripts/compare_schema_to_master.php';
   >>> compareSchemaToMaster('test');
   ```

4. **Generate sync migration:**
   ```php
   >>> include 'database/scripts/generate_sync_migration.php';
   >>> generateSyncMigration('test');
   ```

5. **Review and run migration:**
   ```bash
   php artisan migrate
   ```

6. **Verify:**
   ```php
   >>> include 'database/scripts/compare_schema_to_master.php';
   >>> compareSchemaToMaster('test');
   ```

## Helper Script

**File:** `generate_sync_migration_simple.php`

This contains the core parsing and comparison logic. It's required by the other scripts but not meant to be called directly.

## Notes

- All scripts work against the **currently connected database** (from Laravel config)
- Schema exports are saved to the **project root** (not storage/app)
- The master migration is the **single source of truth**
- Generated migrations are **idempotent** (safe to run multiple times)

