# Database Deployment Guide

This guide outlines the process for deploying database changes from dev to test and production environments.

## Overview

The deployment process involves:
1. **Structure Migration**: Sync database schema with dev
2. **Main Table Refresh**: Update main tables with fresh data
3. **Data Preservation**: Keep existing data in non-main tables

## Prerequisites

- Access to dev, test, and production databases
- Laravel migration system available
- Backup of production data (recommended)

## Step 1: Test Environment Deployment

### 1.1 Run Structure Migration

```bash
# On test server
cd /path/to/backend
php artisan migrate
```

This will:
- Create `s_generator` table
- Add missing columns (`m_room_type.level`, `activity.plan_extra_block`)
- Remove obsolete columns (`event.enddate`)
- Update foreign key constraints

### 1.2 Refresh Main Tables

```bash
# Run the main table refresh script
php artisan tinker
>>> include 'database/scripts/refresh_main_tables.php';
>>> refreshMainTables();
```

### 1.3 Import Main Data from Dev

```bash
# Export main tables from dev
mysqldump -u dev_user -p dev_database \
  --tables m_activity_type m_activity_type_detail m_first_program \
  m_insert_point m_level m_parameter m_role m_room_type \
  m_room_type_group m_season m_supported_plan m_visibility \
  --no-create-info --single-transaction > main_tables.sql

# Import to test
mysql -u test_user -p test_database < main_tables.sql
```

### 1.4 Verify Test Environment

- Check that all tables exist
- Verify foreign key constraints
- Test application functionality
- Ensure data integrity

## Step 2: Production Environment Deployment

### 2.1 Backup Production Data

```bash
# Create full backup
mysqldump -u prod_user -p prod_database > prod_backup_$(date +%Y%m%d_%H%M%S).sql

# Create backup of specific tables (optional)
mysqldump -u prod_user -p prod_database \
  --tables activity activity_group event event_logo extra_block \
  logo plan plan_extra_block plan_param_value regional_partner \
  room room_type_room table_event team team_plan user user_regional_partner \
  > prod_data_backup_$(date +%Y%m%d_%H%M%S).sql
```

### 2.2 Run Structure Migration

```bash
# On production server
cd /path/to/backend
php artisan migrate
```

### 2.3 Refresh Main Tables

```bash
# Run the main table refresh script
php artisan tinker
>>> include 'database/scripts/refresh_main_tables.php';
>>> refreshMainTables();
```

### 2.4 Import Main Data from Dev

```bash
# Use the same main_tables.sql from step 1.3
mysql -u prod_user -p prod_database < main_tables.sql
```

### 2.5 Verify Production Environment

- Check that all tables exist
- Verify foreign key constraints
- Test application functionality
- Monitor for any issues

## Rollback Plan

If issues occur, you can rollback using:

```bash
# Rollback the migration
php artisan migrate:rollback

# Restore from backup if needed
mysql -u prod_user -p prod_database < prod_backup_YYYYMMDD_HHMMSS.sql
```

## Main Tables (Will Be Refreshed)

These tables will be truncated and refreshed with dev data:
- `m_activity_type`
- `m_activity_type_detail`
- `m_first_program`
- `m_insert_point`
- `m_level`
- `m_parameter`
- `m_role`
- `m_room_type`
- `m_room_type_group`
- `m_season`
- `m_supported_plan`
- `m_visibility`

## Data Tables (Will Be Preserved)

These tables will keep their existing data:
- `activity`
- `activity_group`
- `event`
- `event_logo`
- `extra_block`
- `logo`
- `plan`
- `plan_extra_block`
- `plan_param_value`
- `regional_partner`
- `room`
- `room_type_room`
- `table_event`
- `team`
- `team_plan`
- `user`
- `user_regional_partner`

## Troubleshooting

### Common Issues

1. **Foreign Key Constraint Errors**
   - Check that referenced tables exist
   - Verify data integrity before migration

2. **Column Type Mismatches**
   - Check Laravel migration logs
   - Verify column definitions match

3. **Data Loss Concerns**
   - Always backup before migration
   - Test on staging environment first

### Verification Commands

```bash
# Check table structure
php artisan tinker
>>> Schema::getColumnListing('activity');
>>> Schema::getColumnListing('s_generator');

# Check foreign keys
>>> DB::select('SHOW CREATE TABLE activity');
>>> DB::select('SHOW CREATE TABLE s_generator');
```

## Notes

- The migration is designed to be safe and preserve existing data
- Master tables are refreshed to ensure consistency with dev
- All changes are reversible through the migration rollback
- Test thoroughly before production deployment
