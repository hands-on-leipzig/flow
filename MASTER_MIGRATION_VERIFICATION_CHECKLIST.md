# Master Migration Verification Checklist

## Purpose
Verify that the master migration (`2025_01_01_000000_create_master_tables.php`) is correct and complete before creating sync migrations for existing databases.

## Pre-Verification Checks

### 1. **Code Review**
- [ ] Review master migration file for syntax errors
- [ ] Verify all 46 tables are included
- [ ] Check table creation order (dependencies resolved)
- [ ] Verify foreign key creation order
- [ ] Check for any hardcoded values that should be configurable

### 2. **Schema Comparison**
- [ ] Compare master migration with Dev DB schema export
- [ ] Verify all columns match (name, type, nullability, defaults)
- [ ] Verify all foreign keys match (columns, references, delete rules)
- [ ] Verify all indexes match
- [ ] Verify all unique constraints match

### 3. **Data Type Verification**
- [ ] All IDs are `unsignedInteger` (length 10)
- [ ] All FKs are `unsignedInteger` (length 10)
- [ ] String lengths match Dev DB
- [ ] Date/time types match Dev DB
- [ ] Boolean types correct
- [ ] Decimal types correct (precision, scale)

## Testing on Fresh Database

### 4. **Fresh Database Test**
```bash
# Create fresh database
mysql -e "CREATE DATABASE test_fresh_master;"

# Update .env to use test database
# Run migration
php artisan migrate:fresh --path=database/migrations/2025_01_01_000000_create_master_tables.php
```

**Checks**:
- [ ] Migration runs without errors
- [ ] All 46 tables created
- [ ] All foreign keys created
- [ ] All indexes created
- [ ] Schema matches Dev DB exactly

### 5. **Schema Export Comparison**
```bash
# Export schema from fresh database
php database/scripts/export_dev_schema.php --database=test_fresh_master --output=fresh_schema.md

# Compare with Dev DB schema
# Should match exactly
```

**Checks**:
- [ ] All tables present
- [ ] All columns match
- [ ] All FKs match
- [ ] All indexes match
- [ ] Data types match

## Testing on Existing Database (Idempotency)

### 6. **Idempotency Test on Dev**
```bash
# Run master migration on existing Dev database
php artisan migrate --path=database/migrations/2025_01_01_000000_create_master_tables.php
```

**Checks**:
- [ ] Migration runs without errors
- [ ] No duplicate tables created
- [ ] No duplicate foreign keys created
- [ ] Existing data preserved
- [ ] Schema still matches expectations

### 7. **Foreign Key Verification**
```sql
-- Check all foreign keys exist
SELECT 
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME,
    DELETE_RULE
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, COLUMN_NAME;
```

**Checks**:
- [ ] All expected FKs exist
- [ ] DELETE_RULE matches master migration
- [ ] No unexpected FKs
- [ ] No duplicate FKs

### 8. **Index Verification**
```sql
-- Check all indexes
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME,
    NON_UNIQUE
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
```

**Checks**:
- [ ] All expected indexes exist
- [ ] Composite indexes correct
- [ ] Unique constraints correct
- [ ] No unexpected indexes

## Specific Table Checks

### 9. **Critical Tables Verification**
Check these tables specifically (they had the most changes):

- [ ] **team**: No `room` or `noshow` columns, `team_number_hot` NOT NULL
- [ ] **team_plan**: Has `noshow` column, `team_number_plan` NOT NULL, FKs CASCADE
- [ ] **user**: Has `name` and `email` columns, FKs SET NULL
- [ ] **slide**: Has `active` column, `slideshow_id` (not `slideshow`), `content` is longText
- [ ] **table_event**: No `name` column, FKs CASCADE
- [ ] **s_generator**: `plan` NOT NULL, FK CASCADE, no timestamps
- [ ] **s_one_link_access**: `id` is unsignedInteger, all columns present
- [ ] **room**: Has `sequence` and `is_accessible`, no `room_type`, FK CASCADE
- [ ] **room_type_room**: All three FKs CASCADE, explicit indexes

### 10. **Master Tables (m_*)**
- [ ] All m_* tables are dropped and recreated (not preserved)
- [ ] All m_* tables have correct structure
- [ ] All m_* tables have correct FKs

## Code Quality Checks

### 11. **Migration Structure**
- [ ] Proper use of `if (!Schema::hasTable(...))` for data tables
- [ ] Proper use of `Schema::dropIfExists()` for m_* tables
- [ ] Foreign key checks disabled/enabled correctly
- [ ] Try-catch blocks where appropriate
- [ ] Comments are clear and helpful

### 12. **Error Handling**
- [ ] Migration handles missing tables gracefully
- [ ] Migration handles existing tables gracefully
- [ ] Migration handles missing foreign keys gracefully
- [ ] No hard failures that would break deployment

## Documentation Checks

### 13. **Review Documentation**
- [ ] All table review documents are complete
- [ ] All decisions documented
- [ ] Migration strategy documented
- [ ] Known issues documented

## Automated Verification Script

### 14. **Create Verification Script**
```php
// database/scripts/verify_master_migration.php
// Compares master migration with actual database schema
// Reports any discrepancies
```

**Checks**:
- [ ] Script runs without errors
- [ ] Script reports all discrepancies
- [ ] Script output is clear and actionable

## Final Sign-Off

### 15. **Ready for Sync Migration**
- [ ] All checks above passed
- [ ] Master migration verified on fresh database
- [ ] Master migration verified on existing Dev database
- [ ] All discrepancies documented
- [ ] Ready to create sync migration

## Issues Found

Document any issues found during verification:

| Issue | Table | Description | Status |
|-------|-------|-------------|--------|
|       |       |             |        |

## Next Steps After Verification

1. Fix any issues found
2. Re-test after fixes
3. Create sync migration for existing databases
4. Test sync migration on TST
5. Deploy to PRD

