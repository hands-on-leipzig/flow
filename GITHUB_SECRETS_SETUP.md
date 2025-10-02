# Environment Variables Setup for Deployment

This document explains how to configure environment variables for deployment across different environments.

## Branch Structure

| Branch | Environment | Purpose |
|--------|-------------|---------|
| `main` | Development | Active development and testing |
| `test` | Test | Pre-production testing and validation |
| `production` | Production | Live production environment |

## Environment-Specific Configuration

Each environment has its own directory with a `.env` file at the top level:

```
server/
├── flow-dev/
│   ├── .env              # Development environment
│   ├── public/           # Web root (dev.flow.hands-on-technology.org)
│   └── ...
├── flow-test/
│   ├── .env              # Test environment  
│   ├── public/           # Web root (test.flow.hands-on-technology.org)
│   └── ...
└── flow-prod/
    ├── .env              # Production environment
    ├── public/           # Web root (flow.hands-on-technology.org)
    └── ...
```

## Required Environment Variables

### Database Credentials

| Variable Name | Description | Example |
|---------------|-------------|---------|
| `DB_NAME` | Database name | `flow_dev_db` |
| `DB_USER` | Database username | `flow_dev_user` |
| `DB_PASSWORD` | Database password | `secure_password_123` |
| `DB_HOST` | Database host | `localhost` |
| `DB_PORT` | Database port | `3306` |

### Additional Variables (Test Environment)

| Variable Name | Description | Example |
|---------------|-------------|---------|
| `DEV_DB_NAME` | Development database name | `flow_dev_db` |
| `DEV_DB_USER` | Development database username | `flow_dev_user` |
| `DEV_DB_PASSWORD` | Development database password | `dev_password_456` |

## Environment File Setup

### Development Environment (`flow-dev/.env`):
```bash
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=flow_dev_db
DB_USERNAME=flow_dev_user
DB_PASSWORD=dev_password_123
```

### Test Environment (`flow-test/.env`):
```bash
APP_ENV=testing
APP_DEBUG=false
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=flow_test_db
DB_USERNAME=flow_test_user
DB_PASSWORD=test_password_123
DEV_DB_NAME=flow_dev_db
DEV_DB_USER=flow_dev_user
DEV_DB_PASSWORD=dev_password_456
```

### Production Environment (`flow-prod/.env`):
```bash
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=mysql
DB_HOST=prod-db-server.com
DB_PORT=3306
DB_DATABASE=flow_prod_db
DB_USERNAME=flow_prod_user
DB_PASSWORD=prod_password_123
```

## Deployment Workflows

### GitHub Actions Workflows

| Workflow File | Trigger Branches | Environments | Purpose |
|---------------|------------------|--------------|---------|
| `deploy.yml` | `main`, `test`, `production` | Development, Test, Production | Complete deployment pipeline |

### Deployment Scripts

| Script | Environment | Purpose |
|--------|-------------|---------|
| `deploy_dev.sh` | Development | Development deployment |
| `deploy_test.sh` | Test | Test environment setup |
| `deploy_prod.sh` | Production | Production deployment |

### Manual Deployment

You can also run deployments manually:

```bash
# Development (in flow-dev directory)
cd /path/to/flow-dev
./deploy_dev.sh

# Test (in flow-test directory)
cd /path/to/flow-test
./deploy_test.sh

# Production (in flow-prod directory)
cd /path/to/flow-prod
./deploy_prod.sh
```

## Security Best Practices

- Use strong, unique passwords for each environment
- Never commit database credentials to the repository
- Use environment-specific `.env` files
- Enable manual approval for production deployments
- Monitor deployment logs and application health
- Regularly rotate passwords
- Use different credentials for dev, test, and production
- Consider using database connection pooling or managed database services

## Testing the Setup

After adding all secrets, you can test the deployment by:

1. Going to the **Actions** tab in your GitHub repository
2. Selecting the **Deploy to Test Environment** workflow
3. Clicking **Run workflow**
4. Monitoring the deployment logs

## Troubleshooting

### Common Issues

1. **"Required GitHub secret 'X' is not set"**
   - Make sure you've added all required secrets
   - Check that secret names match exactly (case-sensitive)

2. **Database connection failed**
   - Verify database credentials are correct
   - Check that the database server is accessible
   - Ensure the database exists

3. **Permission denied errors**
   - Check that database users have appropriate permissions
   - Verify file system permissions for the backend directory

### Verification Commands

You can verify your setup by running these commands locally:

```bash
# Test database connection
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1;" "$DB_NAME"

# Test dev database connection
mysql -h "$DB_HOST" -u "$DEV_DB_USER" -p"$DEV_DB_PASSWORD" -e "SELECT 1;" "$DEV_DB_NAME"

# Test Laravel connection
cd backend
php artisan tinker
>>> DB::connection()->getPdo();
```

## Deployment Process

The deployment process includes:

1. **Database Purge**: Clears all data from test database
2. **Migration**: Runs Laravel migrations to update schema
3. **Master Data Import**: Imports master tables from dev database
4. **Test Data Creation**: Creates three test events
5. **Verification**: Checks that everything is working correctly

## Manual Deployment

If you need to run the deployment manually:

```bash
# Set environment variables
export DB_NAME="your_test_db"
export DB_USER="your_test_user"
export DB_PASSWORD="your_test_password"
export DEV_DB_NAME="your_dev_db"
export DEV_DB_USER="your_dev_user"
export DEV_DB_PASSWORD="your_dev_password"

# Run deployment
./backend/deploy_test.sh
```

## Support

If you encounter issues with the deployment:

1. Check the GitHub Actions logs for detailed error messages
2. Verify all secrets are correctly configured
3. Test database connections manually
4. Check that all required dependencies are installed

