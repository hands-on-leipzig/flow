# Rollout Plan: New Deployment Script

## Phase 1: Create PR for New Script ✅

**Goal:** Merge the new deployment script into the repository

**What to include in PR:**
- `.github/workflows/deploy.yml` (refactored orchestrator)
- `.github/workflows/deploy-reusable.yml` (reusable workflow)
- `backend/scripts/deploy-finalize.sh` (extracted deployment script)
- `backend/database/scripts/update_m_tables_from_json.php` (incremental m-tables update)
- `backend/database/migrations/2025_01_01_000000_create_master_tables.php` (master migration)
- Schema sync tools (5 PHP scripts)
- Schema sync documentation (3 MD files)
- Deployment documentation (4 MD files)

**PR Title:** `Refactor deployment workflow and add m-tables incremental update`

**PR Description:** (Use template from BRANCH_OVERVIEW_AND_PR_PLAN.md)

**After merge:**
- New workflow files are in the repository
- Workflow is **active** but hasn't run yet
- Next push to `main` will trigger the new workflow

---

## Phase 2: Verify in GitHub ✅

**Goal:** Confirm the new workflow is present and ready

**Checklist:**
- [ ] Go to GitHub → Actions tab
- [ ] Verify `.github/workflows/deploy.yml` exists in repository
- [ ] Verify `.github/workflows/deploy-reusable.yml` exists in repository
- [ ] Check that workflow file syntax is valid (no red errors)
- [ ] Verify all files are in the repository

**What to look for:**
- Workflow files are present
- No syntax errors shown
- Ready to trigger on next push

**Time:** 2-5 minutes

---

## Phase 3: Create Mini Test PR ✅

**Goal:** Trigger the new deployment workflow with a small, safe change

**What to do:**
1. Create a small, non-critical change
2. Push to `main` branch
3. This triggers the new deployment workflow
4. Monitor the deployment

**Suggested test changes:**
- Add a comment to a file
- Update README with a note
- Add a small log message
- Or use: `git commit --allow-empty -m "Test new deployment workflow"`

**What to monitor:**
- GitHub Actions → Watch the workflow run
- Check each step:
  - ✅ Pre-deployment tests (NEW)
  - ✅ Build steps
  - ✅ Deployment steps
  - ✅ Health check (NEW)
  - ✅ Notifications (NEW)
- Verify deployment succeeds
- Test application manually (verify it works)

**Success criteria:**
- ✅ Workflow runs without errors
- ✅ All steps complete successfully
- ✅ Health check passes
- ✅ Application works correctly
- ✅ No regressions

**If successful:**
- ✅ New deployment script is working!
- ✅ Can proceed to test environment next

**If issues:**
- Review GitHub Actions logs
- Fix the issue
- Create fix PR
- Test again

**Time:** 15-30 minutes (monitoring)

---

## Summary

### Step 1: Create PR
- Include all new deployment files
- Review and merge
- **Result:** New workflow is in repository

### Step 2: Verify in GitHub
- Check workflow files exist
- Verify syntax is correct
- **Result:** Confirmed ready

### Step 3: Test with Mini PR
- Make small change
- Push to `main`
- Monitor deployment
- **Result:** Verified working

---

## Next Steps After Dev Success

Once dev deployment is successful:

1. **Test on test environment**
   - Push to `test` branch
   - Monitor deployment
   - Verify it works

2. **Deploy to production**
   - Create release
   - Monitor closely
   - Verify it works

---

## Quick Reference

**PR Files:** See BRANCH_OVERVIEW_AND_PR_PLAN.md for complete list

**Monitoring:** GitHub → Actions → Select workflow run → View logs

**Recovery:** Revert PR if needed (see DEPLOYING_THE_DEPLOYMENT_SCRIPT.md)

