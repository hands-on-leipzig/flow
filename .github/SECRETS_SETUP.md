# GitHub Secrets Setup for Deployment

This document outlines the required GitHub secrets for the deployment workflow to work across all environments.

## Required Secrets

### Existing Secrets (Already Configured)
- `SSH_KNOWN_HOST` - SSH known hosts entry for the server
- `SSH_KEY` - Private SSH key for server access
- `SSH_USER` - SSH username for server access
- `SSH_HOST` - SSH hostname/IP for server access
- `VITE_API_BASE_URL_DEV` - API base URL for development environment

### New Secrets Required

#### Test Environment
- `VITE_API_BASE_URL_TEST` - API base URL for test environment
  - Example: `https://test.flow.hands-on-technology.org/api`

#### Production Environment
- `VITE_API_BASE_URL_PROD` - API base URL for production environment
  - Example: `https://flow.hands-on-technology.org/api`

## How to Add Secrets

1. Go to your GitHub repository
2. Navigate to **Settings** → **Secrets and variables** → **Actions**
3. Click **New repository secret**
4. Add each secret with the appropriate value

## Environment URLs

- **Development**: `https://dev.flow.hands-on-technology.org`
- **Test**: `https://test.flow.hands-on-technology.org`
- **Production**: `https://flow.hands-on-technology.org`

## Server Directories

- **Development**: `~/public_html/flow-dev/`
- **Test**: `~/public_html/flow-test/`
- **Production**: `~/public_html/flow-prod/`

## Deployment Triggers

- **Development**: Push to `main` branch
- **Test**: Push to `test` branch
- **Production**: Push to `production` branch

## Database Migrations

All deployments now include:
1. **Database migrations** (`php artisan migrate --force`)
2. **Main table refresh** (for test and production only)
3. **Cache clearing** (config, routes, views)
4. **Storage linking**

## Main Table Refresh

The test and production deployments automatically refresh main tables after migration:
- Truncates all main tables (`m_*`)
- Preserves data in non-main tables
- Ensures consistency with development data

## Rollback

If a deployment fails, you can:
1. Revert the branch to the previous commit
2. Push the revert to trigger a new deployment
3. Or manually run `php artisan migrate:rollback` on the server
