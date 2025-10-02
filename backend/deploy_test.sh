#!/bin/bash

# Test Environment Deployment Script
# This script performs a complete deployment to the test environment

set -e  # Exit on any error

echo "🚀 Starting Test Environment Deployment..."
echo "=========================================="
echo ""

# Configuration - using environment-specific .env file
BACKEND_DIR="${BACKEND_DIR:-/path/to/backend}"  # Can be overridden via environment variable
ENV_FILE="${ENV_FILE:-.env}"  # Default to .env in current directory (flow-test, flow-dev, flow-prod)

# Load environment variables from the specified .env file
if [ -f "$ENV_FILE" ]; then
    echo "Loading environment variables from: $ENV_FILE"
    export $(grep -v '^#' "$ENV_FILE" | xargs)
else
    echo "Warning: Environment file $ENV_FILE not found, using system environment variables"
fi

# Fallback to environment variables if not set from .env file
DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME}"
DB_USER="${DB_USER}"
DB_PASSWORD="${DB_PASSWORD}"
DEV_DB_NAME="${DEV_DB_NAME}"
DEV_DB_USER="${DEV_DB_USER}"
DEV_DB_PASSWORD="${DEV_DB_PASSWORD}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}✓${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "${RED}❌${NC} $1"
}

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Check prerequisites
echo "Checking prerequisites..."

# Check required GitHub secrets
required_secrets=("DB_NAME" "DB_USER" "DB_PASSWORD" "DEV_DB_NAME" "DEV_DB_USER" "DEV_DB_PASSWORD")
for secret in "${required_secrets[@]}"; do
    if [ -z "${!secret}" ]; then
        print_error "Required GitHub secret '$secret' is not set"
        exit 1
    fi
done

if ! command_exists mysql; then
    print_error "MySQL client not found. Please install mysql-client."
    exit 1
fi

if ! command_exists php; then
    print_error "PHP not found. Please install PHP."
    exit 1
fi

if [ ! -d "$BACKEND_DIR" ]; then
    print_error "Backend directory not found: $BACKEND_DIR"
    print_error "Please update the BACKEND_DIR variable in this script."
    exit 1
fi

print_status "Prerequisites check passed"

# Change to backend directory
cd "$BACKEND_DIR"

# Step 1: Run Laravel deployment script
echo ""
echo "Step 1: Running Laravel deployment script..."
php artisan tinker << 'EOF'
include 'database/scripts/deploy_test_environment.php';
deployTestEnvironment();
EOF

if [ $? -eq 0 ]; then
    print_status "Laravel deployment script completed"
else
    print_error "Laravel deployment script failed"
    exit 1
fi

# Step 2: Populate master tables from dev database
echo ""
echo "Step 2: Populating master tables from dev database..."

# Create temporary file for master tables export
TEMP_FILE="/tmp/master_tables_$(date +%Y%m%d_%H%M%S).sql"

echo "Exporting master tables from dev database..."
mysqldump -h "$DB_HOST" -u "$DEV_DB_USER" -p"$DEV_DB_PASSWORD" \
    --tables m_activity_type m_activity_type_detail m_first_program \
    m_insert_point m_level m_parameter m_role m_room_type \
    m_room_type_group m_season m_supported_plan m_visibility \
    --no-create-info --single-transaction \
    "$DEV_DB_NAME" > "$TEMP_FILE"

if [ $? -eq 0 ]; then
    print_status "Master tables exported from dev database"
else
    print_error "Failed to export master tables from dev database"
    exit 1
fi

echo "Importing master tables to test database..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$TEMP_FILE"

if [ $? -eq 0 ]; then
    print_status "Master tables imported to test database"
else
    print_error "Failed to import master tables to test database"
    exit 1
fi

# Clean up temporary file
rm -f "$TEMP_FILE"

# Step 3: Final verification
echo ""
echo "Step 3: Final verification..."

# Run verification script
php artisan tinker << 'EOF'
include 'database/scripts/deploy_test_environment.php';
verifyDeployment();
EOF

if [ $? -eq 0 ]; then
    print_status "Verification completed"
else
    print_warning "Verification had issues - please check manually"
fi

# Step 4: Summary
echo ""
echo "✅ Test Environment Deployment Complete!"
echo "=========================================="
echo ""
echo "Summary:"
echo "- Database purged and migrated"
echo "- Master tables populated from dev database"
echo "- Three test events created:"
echo "  1. RPT Demo - Nur Explore (Regional Partner A)"
echo "  2. RPT Demo - Nur Challenge (Regional Partner A)"
echo "  3. RPT Demo (Regional Partner B - Combined)"
echo ""
echo "Next steps:"
echo "1. Test the application functionality"
echo "2. Verify all endpoints work correctly"
echo "3. Check user authentication"
echo ""
echo "Test users will be created automatically on first login with 'flow-tester' role."
