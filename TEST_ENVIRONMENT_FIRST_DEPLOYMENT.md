# First Test Environment Deployment Plan

## Overview

This document outlines the plan for the first deployment to the test environment using the new deployment script.

## Key Differences: Test vs Dev

| Feature | Dev | Test |
|---------|-----|------|
| **Trigger** | Push to `main` | Push to `test` branch |
| **URL** | https://dev.flow.hands-on-technology.org | https://test.flow.hands-on-technology.org |
| **Refresh M-Tables** | ❌ `false` | ✅ `true` |
| **Fix Migration Records** | ❌ `false` | ❌ `false` |
| **Verify Migrations** | ❌ `false` | ❌ `false` |
| **Seed Main Data** | ❌ `false` | ❌ `false` (replaced by m-tables update) |
| **Database Backup** | ❌ No | ❌ No (only production) |

## What Will Happen on First Test Deployment

### 1. Pre-Deployment Steps
- ✅ Backend tests run (with warnings allowed)
- ✅ Frontend tests run (with warnings allowed)
- ✅ Frontend build
- ✅ Backend dependencies installed

### 2. Code Deployment
- ✅ Rsync backend code to `~/deploy-flow-test-temp/`
- ✅ Copy frontend dist to Laravel public directory
- ✅ Move to `~/public_html/flow-test/`

### 3. Database Operations (NEW for Test)
- ✅ **Run migrations** (`php artisan migrate --force`)
- ✅ **Update m-tables from JSON** (`update_m_tables_from_json.php`)
  - Uses `database/exports/main-tables-latest.json`
  - FK checks **ENABLED** (data integrity maintained)
  - Incremental updates (INSERT/UPDATE/DELETE)
  - Handles dependency order automatically
  - **Replaces MainDataSeeder** - no seeding needed

### 4. Post-Deployment
- ✅ Clear caches (config, routes, views)
- ✅ Create storage symlink
- ✅ Restart queue workers
- ✅ Health check
- ✅ Send notification

## Prerequisites Checklist

Before deploying to test:

- [ ] **GitHub Secret**: `VITE_API_BASE_URL_TEST` is configured
  - Should be: `https://test.flow.hands-on-technology.org/api`
  - Check: GitHub → Settings → Secrets and variables → Actions

- [ ] **JSON Export File**: `backend/database/exports/main-tables-latest.json` exists
  - This file should be up-to-date with dev database
  - Verify it's committed to the repository

- [ ] **Test Database**: Accessible and ready
  - Test database connection works
  - Database user has necessary permissions
  - Database is in a known state (backup recommended)

- [ ] **Test Branch**: Up to date with `main`
  - Ensure test branch has all latest changes
  - Consider: merge `main` into `test` before first deployment

## Potential Issues & Considerations

### 1. M-Tables Update from JSON
- **Risk**: If JSON file is outdated, test DB will get outdated data
- **Mitigation**: Ensure JSON is exported from dev before deployment
- **Check**: Verify JSON file timestamp/commit date

### 2. Foreign Key Constraints
- **Risk**: RESTRICT FKs might block deletions if data is referenced
- **Mitigation**: Script handles this with "fail fast" approach
- **Action**: Monitor logs for FK violations

### 3. MainDataSeeder Removed ✅
- **Status**: `MainDataSeeder` has been replaced by `update_m_tables_from_json.php`
- **Action**: Seeding is disabled for both test and production environments
- **Reason**: The JSON update script handles INSERT/UPDATE/DELETE more comprehensively

### 4. Database State
- **Risk**: Test DB might have different schema than expected
- **Mitigation**: Migrations run first to ensure schema matches
- **Action**: Verify migrations complete successfully

### 5. Data Loss
- **Risk**: M-tables update will DELETE records not in JSON
- **Mitigation**: This is expected behavior (test should match dev)
- **Action**: Ensure test DB doesn't have critical data that needs preserving

## Recommended First Deployment Steps

### Step 1: Prepare Test Branch
```bash
# Ensure test branch is up to date
git checkout test
git pull origin test
git merge main  # Merge latest from main
git push origin test
```

### Step 2: Verify Prerequisites
- [ ] Check GitHub secret `VITE_API_BASE_URL_TEST` exists
- [ ] Verify JSON file exists: `backend/database/exports/main-tables-latest.json`
- [ ] Check test database is accessible
- [ ] Consider backing up test database (if it has important data)

### Step 3: Make a Small Test Change
```bash
# Create a small, safe change to trigger deployment
git checkout test
# Make a minimal change (e.g., add a comment)
git commit -m "Test: First deployment to test environment with new script"
git push origin test
```

### Step 4: Monitor Deployment
- Watch GitHub Actions → Workflow run
- Monitor each step:
  - ✅ Pre-deployment tests
  - ✅ Build steps
  - ✅ Code deployment
  - ✅ **Migrations** (NEW - watch for issues)
  - ✅ **M-tables update** (NEW - watch for FK violations)
  - ✅ **Main data seeding** (NEW - watch for errors)
  - ✅ Health check
  - ✅ Notification

### Step 5: Verify Application
- Visit https://test.flow.hands-on-technology.org
- Test key functionality:
  - Login/authentication
  - Data loading (verify m-tables data is present)
  - Create/edit operations
  - Verify no errors in browser console

### Step 6: Verify Database
- Connect to test database
- Check m-tables have data:
  ```sql
  SELECT COUNT(*) FROM m_activity_type;
  SELECT COUNT(*) FROM m_level;
  SELECT COUNT(*) FROM m_season;
  -- etc.
  ```
- Verify FK relationships are intact
- Check for any orphaned records

## Rollback Plan

If deployment fails:

1. **Check GitHub Actions logs** for specific error
2. **Identify the failing step**:
   - Migration failure → Check schema differences
   - M-tables update failure → Check JSON file or FK violations
   - Seeding failure → Check MainDataSeeder
3. **Fix the issue** in code
4. **Redeploy** or revert test branch to previous commit

## Success Criteria

Deployment is successful if:
- ✅ All workflow steps complete without errors
- ✅ Health check passes
- ✅ Application loads and functions correctly
- ✅ M-tables contain expected data
- ✅ No FK constraint violations
- ✅ No orphaned records in database

## Next Steps After Success

Once test deployment is successful:
1. Document any issues encountered
2. Update this plan with lessons learned
3. Proceed to production deployment (when ready)

## MainDataSeeder Removed ✅

**Status:** `MainDataSeeder` has been replaced by `update_m_tables_from_json.php`

**Changes Made:**
- ✅ Test environment: `seed_main_data: false`
- ✅ Production environment: `seed_main_data: false`
- ✅ Seeding step removed from deployment process

**Reason:**
- `update_m_tables_from_json.php` handles INSERT, UPDATE, DELETE (complete sync)
- `MainDataSeeder` only handled INSERT, UPDATE (no deletions)
- The JSON update script is more comprehensive and maintains data integrity with FK checks

## Questions to Resolve

1. ~~**MainDataSeeder redundancy**: Should we skip seeding if m-tables are updated from JSON?~~ ✅ **RESOLVED - Seeding disabled**
2. **Test data preservation**: Should we preserve any test-specific data?
3. **Backup strategy**: Should we create backups before test deployments too?

