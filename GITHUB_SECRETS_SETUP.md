# GitHub Secrets Setup for Test Deployment

This document explains how to configure GitHub secrets for the test environment deployment.

## Required GitHub Secrets

You need to create the following secrets in your GitHub repository:

### Database Credentials

| Secret Name | Description | Example |
|-------------|-------------|---------|
| `DB_NAME` | Test database name | `flow_test_db` |
| `DB_USER` | Test database username | `flow_test_user` |
| `DB_PASSWORD` | Test database password | `secure_password_123` |
| `DEV_DB_NAME` | Development database name | `flow_dev_db` |
| `DEV_DB_USER` | Development database username | `flow_dev_user` |
| `DEV_DB_PASSWORD` | Development database password | `dev_password_456` |

### Optional Secrets

| Secret Name | Description | Default |
|-------------|-------------|---------|
| `DB_HOST` | Database host | `localhost` |
| `BACKEND_DIR` | Backend directory path | `/path/to/backend` |

## How to Add GitHub Secrets

1. Go to your GitHub repository
2. Click on **Settings** tab
3. In the left sidebar, click **Secrets and variables** → **Actions**
4. Click **New repository secret**
5. Enter the secret name and value
6. Click **Add secret**
7. Repeat for all required secrets

## Security Best Practices

- Use strong, unique passwords for each environment
- Never commit database credentials to the repository
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
