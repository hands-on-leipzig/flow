# Deploying the New Deployment Script

## Overview

This document explains:
1. How the new deployment script will be deployed
2. How to restore the old version if needed
3. Which logs to provide for troubleshooting

---

## Part 1: How Deploying the New Script Works

### The Deployment Process

When you merge the PR containing the new deployment script:

1. **PR is merged to `main` branch**
   - The new workflow files (`.github/workflows/deploy.yml` and `deploy-reusable.yml`) are now in the repository
   - The old workflow files are replaced

2. **GitHub Actions automatically uses the new workflow**
   - GitHub Actions reads workflow files from the repository
   - The **next push to `main`** will trigger the **NEW** deployment workflow
   - No manual intervention needed - it's automatic

3. **First deployment with new script**
   - When you push to `main` after merging the PR, the new workflow runs
   - It uses the new reusable workflow structure
   - It calls the new `deploy-finalize.sh` script
   - It uses the new `update_m_tables_from_json.php` script

### Important: The "Chicken and Egg" Problem

**Question:** How do we deploy the new deployment script using the deployment script itself?

**Answer:** GitHub Actions workflows are **versioned in the repository**. When you merge the PR:
- The workflow files in the repository are updated
- The **next time** the workflow is triggered (by a push), GitHub Actions reads the **new** workflow files
- There's no need to "deploy" the workflow itself - it's automatically active once merged

### Step-by-Step Deployment

```bash
# 1. Create PR with new deployment script
git checkout -b deploy-script-improvements
# ... make changes ...
git push origin deploy-script-improvements
# Create PR on GitHub

# 2. Review and merge PR to main
# (On GitHub UI: Merge pull request)

# 3. After merge, the new workflow is active
# The next push to main will use the new workflow

# 4. Test the new workflow
git checkout main
git pull
# Make a small change and push
git commit --allow-empty -m "Test new deployment workflow"
git push origin main

# 5. Monitor the deployment
# Go to GitHub Actions tab
# Watch the workflow run with the new structure
```

### What Happens on First Deployment

When the new workflow runs for the first time:

1. **Workflow starts** (using new `deploy.yml`)
2. **Calls reusable workflow** (using new `deploy-reusable.yml`)
3. **Runs pre-deployment tests** (NEW feature)
4. **Builds and deploys** (same as before)
5. **Calls deploy-finalize.sh** (NEW extracted script)
6. **Runs migrations** (same as before)
7. **Updates m-tables** (using NEW `update_m_tables_from_json.php`)
8. **Restarts queue workers** (NEW feature)
9. **Runs health check** (NEW feature)
10. **Sends notifications** (NEW feature)

### Safety Features

The new deployment script includes several safety features:

1. **Pre-deployment tests** - Catches issues before deployment
2. **Database backup** (production) - Automatic backup before changes
3. **Health checks** - Verifies deployment succeeded
4. **Transaction-based m-table updates** - Rollback on error
5. **FK validation** - Ensures data integrity

---

## Part 2: How to Restore the Old Version

### Option 1: Revert the PR (Recommended)

If you need to quickly restore the old deployment script:

```bash
# 1. Find the merge commit
git log --oneline --grep="deploy" | head -5

# 2. Revert the merge commit
git revert -m 1 <merge-commit-hash>

# 3. Push the revert
git push origin main

# 4. The old workflow is now active again
# Next deployment will use the old workflow
```

**Pros:**
- Clean git history
- Easy to track what happened
- Can re-apply later if needed

**Cons:**
- Requires a new commit
- Takes a few minutes to restore

### Option 2: Manual File Restoration

If you need immediate restoration:

```bash
# 1. Checkout the old version of workflow files
git checkout <commit-before-PR> -- .github/workflows/deploy.yml

# 2. Restore old deploy-finalize.sh (if it was inline in workflow)
# You'll need to extract it from the old workflow file

# 3. Commit and push
git add .github/workflows/deploy.yml
git commit -m "Restore old deployment workflow"
git push origin main
```

**Pros:**
- Immediate restoration
- Can be done quickly

**Cons:**
- Messy git history
- Need to manually extract old script
- Harder to track

### Option 3: Create a Rollback PR

Create a new PR that restores the old files:

```bash
# 1. Create new branch
git checkout -b rollback-deployment-script

# 2. Restore old files from git history
git show <old-commit>:.github/workflows/deploy.yml > .github/workflows/deploy.yml

# 3. Commit and create PR
git add .github/workflows/deploy.yml
git commit -m "Rollback: Restore old deployment workflow"
git push origin rollback-deployment-script
# Create PR on GitHub
```

**Pros:**
- Reviewable change
- Can discuss with team
- Clean process

**Cons:**
- Takes longer (needs PR review)

### Recommended Approach

**For quick rollback:** Use Option 1 (revert the PR)
**For careful rollback:** Use Option 3 (create rollback PR)

---

## Part 3: Troubleshooting - Which Logs to Provide

If the new deployment script has issues, provide these logs in order of importance:

### 1. GitHub Actions Workflow Logs (Most Important)

**Location:** GitHub → Actions tab → Select the failed workflow run

**What to provide:**
- The entire workflow log (download as text file)
- Or screenshot of the error section
- Or copy/paste the error messages

**How to access:**
1. Go to your repository on GitHub
2. Click "Actions" tab
3. Find the failed workflow run
4. Click on it to see details
5. Click on the failed job (usually "deploy")
6. Expand the failed step
7. Copy the error output

**Key sections to look for:**
- Red error messages
- Stack traces
- Exit codes (non-zero)
- Failed step names

### 2. Server-Side Deployment Logs

**Location:** Server where deployment runs

**What to provide:**
- Output from `deploy-finalize.sh`
- Laravel logs (if available)
- Any error messages from PHP/MySQL

**How to access:**
```bash
# SSH into the server
ssh user@server

# Check deployment logs (if they're saved)
# The script outputs to stdout, so check:
# - GitHub Actions logs (captures SSH output)
# - Or check Laravel logs
cd ~/public_html/flow-dev  # or flow-test, flow-prod
tail -100 storage/logs/laravel.log
```

**Key information:**
- Error messages from `deploy-finalize.sh`
- Migration errors
- M-table update errors
- Queue restart errors

### 3. Pre-Deployment Test Logs

**Location:** GitHub Actions workflow logs

**What to provide:**
- Test output (if tests failed)
- Test errors and stack traces

**How to access:**
- Same as #1 (GitHub Actions logs)
- Look for "Run backend tests" or "Run frontend tests" step

### 4. Health Check Logs

**Location:** GitHub Actions workflow logs

**What to provide:**
- Health check output
- HTTP response codes
- Retry attempts

**How to access:**
- Same as #1 (GitHub Actions logs)
- Look for "Verify deployment health" step

### 5. Database-Related Logs

**Location:** Various

**What to provide:**
- Migration errors
- M-table update errors
- Database connection errors
- FK constraint violations

**How to access:**
- GitHub Actions logs (captures PHP/Laravel output)
- Laravel logs on server
- MySQL error logs (if accessible)

### 6. Notification Logs

**Location:** GitHub Actions workflow logs

**What to provide:**
- Notification errors (usually non-critical)
- Deployment status creation errors

**How to access:**
- Same as #1 (GitHub Actions logs)
- Look for "Notify deployment" steps

---

## Log Priority for Different Issues

### Issue: Deployment Fails Immediately

**Provide:**
1. GitHub Actions workflow logs (full output)
2. The specific step that failed
3. Error message and stack trace

### Issue: Tests Fail

**Provide:**
1. Test output from "Run backend tests" or "Run frontend tests" step
2. Test failure messages
3. Stack traces

### Issue: Migration Fails

**Provide:**
1. Migration output from `deploy-finalize.sh`
2. Migration error messages
3. `migrate:status` output (if available)
4. Database connection details (if connection issue)

### Issue: M-Table Update Fails

**Provide:**
1. Output from `update_m_tables_from_json.php`
2. FK constraint violation messages
3. JSON file validation errors
4. Schema mismatch errors

### Issue: Health Check Fails

**Provide:**
1. Health check output (retry attempts)
2. HTTP response codes
3. Application logs (if app is running but unhealthy)
4. Server logs (if app isn't starting)

### Issue: Queue Worker Restart Fails

**Provide:**
1. Queue restart output
2. Supervisor logs (if using supervisor)
3. Queue worker process status

---

## Quick Troubleshooting Checklist

When reporting an issue, include:

- [ ] **GitHub Actions workflow run URL** (most important!)
- [ ] **Failed step name** (e.g., "Run migrations", "Update m-tables")
- [ ] **Error message** (copy/paste the exact error)
- [ ] **Exit code** (if shown, e.g., "exit code 1")
- [ ] **Environment** (dev/test/production)
- [ ] **Commit hash** that triggered the deployment
- [ ] **Screenshots** of the error (if helpful)
- [ ] **Relevant log excerpts** (don't need full logs if error is clear)

---

## Example: Providing Logs

### Good Log Report

```
Issue: Migration fails on production deployment

GitHub Actions Run: https://github.com/org/repo/actions/runs/123456789
Failed Step: "Run migrations"
Environment: production
Commit: abc1234

Error message:
❌ ERROR: Migrations failed!
SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'new_column'

Full log excerpt:
[Step output]
🔄 Running migrations...
Migrating: 2025_12_01_120000_add_new_column
SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'new_column'
```

### Bad Log Report

```
Issue: Deployment doesn't work
```

---

## Prevention: Testing Before Production

Before deploying to production:

1. **Test on dev first**
   - Merge PR to `main`
   - Push a test commit
   - Verify deployment works

2. **Test on test environment**
   - Push to `test` branch
   - Verify deployment works
   - Test application functionality

3. **Then deploy to production**
   - Create release
   - Monitor deployment
   - Verify health check passes

---

## Summary

### Deploying the New Script

1. Merge PR to `main`
2. New workflow is automatically active
3. Next push triggers new workflow
4. Monitor first deployment carefully

### Restoring Old Version

1. **Quick:** Revert the PR merge commit
2. **Careful:** Create rollback PR
3. Old workflow becomes active on next push

### Providing Logs

1. **Most important:** GitHub Actions workflow logs (full output)
2. **Include:** Failed step, error message, exit code
3. **Context:** Environment, commit hash, what you were trying to do

---

## Questions?

If you encounter issues:
1. Check GitHub Actions logs first
2. Look for red error messages
3. Copy the exact error message
4. Note which step failed
5. Provide all of this when asking for help

