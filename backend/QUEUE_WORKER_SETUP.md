# Queue Worker Setup Guide

## Overview

Laravel queue workers process background jobs (plan generation, quality evaluation, etc.). They must be running for qRuns and plan generation to work.

## ⚠️ Critical: Queue Workers and Code Changes

**Queue workers load code into memory and DO NOT auto-reload when code changes.**

**After deploying code changes, you MUST restart queue workers:**
```bash
php artisan queue:restart
```

---

## Development Environment

### Check if Queue Worker is Running

```bash
# Check process
ps aux | grep "queue:work"

# Or check Laravel status
php artisan queue:work --once  # Process one job and exit (test)
```

### Start Queue Worker (Manual)

```bash
cd /path/to/backend
php artisan queue:work --tries=3 --timeout=600
```

**Flags:**
- `--tries=3` - Retry failed jobs 3 times
- `--timeout=600` - Job timeout (10 minutes for heavy plan generation)
- `--sleep=3` - Sleep 3 seconds between jobs (optional)
- `--queue=default` - Process specific queue (optional)

### Keep Worker Running (Screen/Tmux)

```bash
# Using screen
screen -S queue-worker
php artisan queue:work --tries=3 --timeout=600
# Press Ctrl+A, then D to detach

# Reattach later
screen -r queue-worker

# Using tmux
tmux new -s queue-worker
php artisan queue:work --tries=3 --timeout=600
# Press Ctrl+B, then D to detach

# Reattach later
tmux attach -t queue-worker
```

### Using Supervisor (Recommended for Server)

Create `/etc/supervisor/conf.d/laravel-queue.conf`:

```ini
[program:laravel-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/backend/artisan queue:work --sleep=3 --tries=3 --timeout=600
autostart=true
autorestart=true
stopasflimit=TERM
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/laravel-queue.log
stopwaitsecs=3600
```

**Then:**
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-queue:*
```

**Check status:**
```bash
sudo supervisorctl status
```

**Restart after code deployment:**
```bash
sudo supervisorctl restart laravel-queue:*
```

---

## Production Environment

### Automated Restart (Recommended)

Add to deployment pipeline (GitHub Actions, CI/CD):

```bash
# After deploying code
php artisan queue:restart

# If using Supervisor
sudo supervisorctl restart laravel-queue:*
```

### Manual Restart

```bash
# Graceful restart (finishes current jobs)
php artisan queue:restart

# Hard restart (with Supervisor)
sudo supervisorctl restart laravel-queue:*
```

---

## Troubleshooting

### Workers Not Processing Jobs

**Check 1: Is worker running?**
```bash
ps aux | grep "queue:work"
```

**Check 2: Check failed jobs**
```bash
php artisan queue:failed
```

**Check 3: Check queue table**
```bash
php artisan tinker
>>> DB::table('jobs')->count();  // Should be 0 if empty, >0 if pending
```

**Check 4: Process jobs manually**
```bash
php artisan queue:work --once  # Process one job
```

### Jobs Failing

**View failed jobs:**
```bash
php artisan queue:failed
```

**Retry failed jobs:**
```bash
php artisan queue:retry all
```

**Clear failed jobs:**
```bash
php artisan queue:flush
```

### Workers Using Old Code

**Symptom:** Deployed changes not reflected in job execution

**Solution:**
```bash
# Signal all workers to restart
php artisan queue:restart

# Or if using Supervisor
sudo supervisorctl restart laravel-queue:*
```

---

## Deployment Checklist

After deploying code that affects queued jobs:

- [ ] Run migrations if database changes
- [ ] Clear Laravel caches: `php artisan config:clear`
- [ ] **Restart queue workers:** `php artisan queue:restart`
- [ ] Verify workers restart: check process list or Supervisor status
- [ ] Test with a small job to ensure new code is running
- [ ] Monitor logs for errors

---

## Current Status: DEV Environment

### Issue

Queue workers may not be running on DEV server, which means:
- ❌ qRuns won't process
- ❌ Plan generation jobs won't execute
- ❌ Quality evaluation won't run

### Solution

**On DEV server, ensure a queue worker is running:**

**Option 1: Quick test**
```bash
ssh dev-server
cd /path/to/backend
php artisan queue:work --tries=3 --timeout=600
```

**Option 2: Persistent with Supervisor**
1. Install Supervisor (if not installed)
2. Create config file (see above)
3. Start workers via supervisorctl
4. Workers will auto-restart on crash and survive server reboots

---

## Modified Deployment Scripts

Both `deploy_dev.sh` and `deploy_prod.sh` now include:

```bash
# Restart queue workers
php artisan queue:restart
```

This signals existing workers to restart gracefully after finishing their current job.

**Note:** This assumes workers are already running. If no workers are running, you need to start them manually or via Supervisor.

---

## Quick Reference

| Task | Command |
|------|---------|
| Start worker | `php artisan queue:work` |
| Start with options | `php artisan queue:work --tries=3 --timeout=600` |
| Restart all workers | `php artisan queue:restart` |
| Process one job (test) | `php artisan queue:work --once` |
| Check failed jobs | `php artisan queue:failed` |
| Retry failed jobs | `php artisan queue:retry all` |
| Clear failed jobs | `php artisan queue:flush` |
| Supervisor restart | `sudo supervisorctl restart laravel-queue:*` |
| Supervisor status | `sudo supervisorctl status` |

---

## Important Notes

1. **`queue:restart` only works if workers are already running**
   - It sets a flag that workers check between jobs
   - Workers gracefully restart after finishing current job
   - Does NOT start workers if none are running

2. **For long-running jobs (plan generation):**
   - Use `--timeout=600` (10 minutes) or higher
   - Ensure `stopwaitsecs` in Supervisor is high enough

3. **Multiple workers for parallel processing:**
   - Use `numprocs=4` in Supervisor config
   - Or run multiple `queue:work` processes manually

4. **Monitor queue worker logs:**
   - Check `storage/logs/laravel.log`
   - Check Supervisor logs if using Supervisor

