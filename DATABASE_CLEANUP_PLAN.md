# Database Cleanup and Refactoring Plan

## Overview
Refactor the database schema to ensure all changes are captured in migrations, all foreign keys are properly defined, and data types are correct. This will create a solid baseline for consistent deployments across Dev, Test, and Prod.

## Goals
1. **Complete Migration Coverage**: All database changes captured in artisan migrations
2. **Foreign Key Completeness**: All foreign key relationships properly defined in schema
3. **Data Type Accuracy**: All column types match actual requirements
4. **Idempotent Migrations**: Migrations work on fresh and existing databases
5. **Clean Baseline**: Single master migration as the foundation for future changes

## Current State
- **Master Migration**: `2025_01_01_000000_create_master_tables.php` exists but doesn't match Dev DB exactly
- **Dev Database**: Has correct structure but not all changes are in migrations
- **Issues**: Missing foreign keys, incorrect data types, some changes not captured

## Phase 1: Analysis and Documentation

### 1.1 Export Current Dev Database Schema
**Objective**: Capture the exact current state of Dev database

**Tasks**:
- Connect to Dev database
- Export complete schema structure (tables, columns, data types, indexes, foreign keys)
- Use MySQL commands or Laravel schema inspection
- Document all tables and their structures

**Deliverables**:
- Complete schema export file (SQL or structured format)
- List of all tables with column details
- List of all foreign key constraints with their rules

**Tools/Commands**:
```bash
# Option 1: MySQL dump (structure only)
mysqldump --no-data --routines --triggers dev_database > dev_schema.sql

# Option 2: Laravel schema inspection
php artisan tinker
>>> Schema::getConnection()->getDoctrineSchemaManager()->listTables()
```

### 1.2 Compare Dev Schema with Master Migration
**Objective**: Identify all discrepancies between Dev DB and master migration

**Tasks**:
- Parse master migration file
- Compare each table definition:
  - Column names, types, nullability
  - Indexes
  - Foreign keys
- Create discrepancy report

**Deliverables**:
- **Discrepancy Report** with:
  - Missing foreign keys (table, column, referenced table, onDelete rule)
  - Incorrect data types (table, column, current type, should be)
  - Missing columns (table, column definition)
  - Extra columns in migration not in Dev (table, column)
  - Missing indexes
  - Incorrect nullability

**Format**: Structured document (markdown table or spreadsheet)

### 1.3 Review All Subsequent Migrations
**Objective**: Identify which migrations are now redundant or need adjustment

**Tasks**:
- List all migrations after master migration
- For each migration:
  - Determine what it changes
  - Check if change is already in Dev DB
  - Check if change is covered by master migration (after refactoring)
  - Mark as: Keep, Remove, or Adjust

**Deliverables**:
- **Migration Review Report**:
  - List of all migrations
  - Status: Keep/Remove/Adjust
  - Reason for decision
  - Dependencies

### 1.4 Document Foreign Key Strategy
**Objective**: Define rules for foreign key onDelete behavior

**Tasks**:
- Review each foreign key relationship
- Determine appropriate onDelete rule:
  - `cascade`: Child records should be deleted when parent is deleted
  - `set null`: Child records should have FK set to null when parent is deleted
  - `restrict`: Prevent deletion if child records exist
- Document decision for each FK

**Deliverables**:
- **Foreign Key Strategy Document**:
  - Table → Column → References → onDelete rule
  - Rationale for each decision

## Phase 2: Refactor Master Migration

### 2.1 Update Master Migration File
**Objective**: Make master migration match Dev database exactly

**Tasks**:
- Update `2025_01_01_000000_create_master_tables.php`:
  - Fix all data types to match Dev
  - Add all missing foreign keys with correct onDelete rules
  - Add missing columns
  - Remove columns that don't exist in Dev
  - Add missing indexes
  - Ensure proper nullability
- Make migration idempotent:
  - For m_* tables: Always drop and recreate (current behavior)
  - For other tables: Check if exists, create if not (current behavior)
  - Handle foreign key constraints properly

**Deliverables**:
- Updated master migration file
- Migration passes on fresh database
- Migration is idempotent on existing database

**Key Considerations**:
- Foreign key creation order matters (referenced tables must exist first)
- Disable foreign key checks during table creation (already done)
- Re-enable foreign key checks after creation
- Handle edge cases (tables that might not exist yet)

### 2.2 Data Type Standardization Discussion
**Objective**: Review and standardize data types before implementation

**Tasks**:
- Review current data types in Dev
- Identify inconsistencies:
  - `integer` vs `unsignedInteger`
  - `string` length variations
  - `text` vs `varchar`
  - `timestamp` vs `datetime`
- Discuss with team:
  - Standardization rules
  - Breaking changes
  - Migration path

**Deliverables**:
- **Data Type Standardization Document**:
  - Current types
  - Proposed standard types
  - Rationale
  - Impact assessment

### 2.3 Test Master Migration
**Objective**: Ensure refactored migration works correctly

**Tasks**:
- Test on fresh database:
  ```bash
  php artisan migrate:fresh
  ```
- Test on existing Dev database:
  ```bash
  php artisan migrate:refresh --path=database/migrations/2025_01_01_000000_create_master_tables.php
  ```
- Verify:
  - All tables created correctly
  - All foreign keys exist
  - All data types correct
  - No errors or warnings

**Deliverables**:
- Test results document
- List of any issues found
- Fixes applied

## Phase 3: Migration Cleanup

### 3.1 Remove Redundant Migrations
**Objective**: Remove migrations that are now covered by refactored master

**Tasks**:
- Based on Phase 1.3 review:
  - Remove migrations that only fix issues now in master
  - Remove migrations that add FKs now in master
  - Remove migrations that fix data types now in master
- Keep migrations that:
  - Add new features
  - Add new tables
  - Add new columns (not fixes)
  - Are still needed for historical reasons

**Deliverables**:
- List of migrations to remove
- Backup of removed migrations (git history)
- Updated migration list

### 3.2 Adjust Remaining Migrations
**Objective**: Update migrations that need changes due to master refactoring

**Tasks**:
- Review kept migrations
- Update if they:
  - Reference tables/columns that changed
  - Have dependencies on removed migrations
  - Need to be adjusted for new structure
- Ensure migrations are idempotent

**Deliverables**:
- Updated migration files
- Migration dependency graph
- Test results

### 3.3 Future Migration Strategy
**Objective**: Plan for future migrations after cleanup

**Tasks**:
- Document migration guidelines:
  - When to create new migration vs update master
  - Naming conventions
  - Idempotency requirements
  - Foreign key handling
- Consider second refactoring:
  - After individual table cleanups
  - Merge all into single master migration
  - Timeline and approach

**Deliverables**:
- Migration guidelines document
- Decision on second refactoring
- Timeline if applicable

## Phase 4: Testing and Validation

### 4.1 Fresh Database Test
**Objective**: Verify migrations work on clean database

**Tasks**:
- Create fresh database
- Run all migrations from scratch
- Verify:
  - All tables created
  - All foreign keys exist
  - All data types correct
  - No errors

**Deliverables**:
- Test results
- Any issues found and fixes

### 4.2 Existing Database Test
**Objective**: Verify migrations are idempotent

**Tasks**:
- Test on existing Dev database
- Run migrations
- Verify:
  - No errors
  - No data loss
  - Structure matches expected
  - Foreign keys correct

**Deliverables**:
- Test results
- Comparison: before/after schema

### 4.3 Foreign Key Validation
**Objective**: Verify all foreign keys are correctly defined

**Tasks**:
- Query database for all foreign keys
- Compare with master migration
- Verify onDelete rules
- Test cascade behavior (if applicable)

**Deliverables**:
- Foreign key validation report
- List of all FKs with their rules
- Test results

### 4.4 Data Type Validation
**Objective**: Verify all data types match requirements

**Tasks**:
- Query database for all column types
- Compare with master migration
- Verify standardization
- Test data insertion with correct types

**Deliverables**:
- Data type validation report
- Any discrepancies found

## Phase 5: Deployment Script Updates (Future)

### 5.1 Review Current Deployment Process
**Objective**: Understand how migrations are deployed

**Tasks**:
- Review deployment scripts
- Document current process:
  - Dev → Test deployment
  - Test → Prod deployment
  - m_* table refresh process
  - Foreign key handling during deployment

**Deliverables**:
- Deployment process documentation
- Current script locations

### 5.2 Update Deployment Scripts
**Objective**: Ensure deployment scripts work with refactored migrations

**Tasks**:
- Update scripts to handle:
  - New master migration structure
  - Foreign key constraints
  - m_* table refresh
  - Data preservation
- Test deployment process
- Document changes

**Deliverables**:
- Updated deployment scripts
- Deployment test results
- Updated deployment documentation

## Success Criteria

1. ✅ All database changes are captured in migrations
2. ✅ All foreign keys are properly defined with correct onDelete rules
3. ✅ All data types are correct and standardized
4. ✅ Master migration matches Dev database exactly
5. ✅ Migrations are idempotent (work on fresh and existing databases)
6. ✅ All redundant migrations are removed
7. ✅ Remaining migrations work correctly
8. ✅ Fresh database test passes
9. ✅ Existing database test passes
10. ✅ Foreign key validation passes
11. ✅ Data type validation passes

## Timeline Estimate

- **Phase 1**: 2-3 days (analysis and documentation)
- **Phase 2**: 3-5 days (refactoring and testing)
- **Phase 3**: 1-2 days (cleanup)
- **Phase 4**: 2-3 days (testing and validation)
- **Phase 5**: 2-3 days (deployment scripts - future)

**Total**: ~10-16 days (excluding Phase 5)

## Risks and Mitigation

1. **Risk**: Breaking existing data during migration
   - **Mitigation**: Extensive testing on Dev, backups before changes

2. **Risk**: Missing foreign keys or incorrect rules
   - **Mitigation**: Comprehensive review in Phase 1, validation in Phase 4

3. **Risk**: Deployment issues in Test/Prod
   - **Mitigation**: Thorough testing, deployment script updates in Phase 5

4. **Risk**: Data type changes break existing code
   - **Mitigation**: Review in Phase 2.2, test thoroughly

## Next Steps

1. Review and approve this plan
2. Start Phase 1.1: Export Current Dev Database Schema
3. Proceed through phases sequentially
4. Regular checkpoints after each phase

