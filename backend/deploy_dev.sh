#!/bin/bash

# Development Environment Deployment Script
# This script performs a deployment to the development environment

set -e  # Exit on any error

echo "🚀 Starting Development Environment Deployment..."
echo "==============================================="
echo ""

# Configuration - using environment-specific .env file
BACKEND_DIR="${BACKEND_DIR:-/path/to/backend}"  # Can be overridden via environment variable
ENV_FILE="${ENV_FILE:-dev/.env}"  # Default to dev/.env, can be overridden

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

# Check required environment variables
required_vars=("DB_NAME" "DB_USER" "DB_PASSWORD")
for var in "${required_vars[@]}"; do
    if [ -z "${!var}" ]; then
        print_error "Required environment variable '$var' is not set"
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

# Step 2: Run migrations
echo ""
echo "Step 2: Running migrations..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    print_status "Migrations completed"
else
    print_error "Migrations failed"
    exit 1
fi

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
echo "✅ Development Environment Deployment Complete!"
echo "==============================================="
echo ""
echo "Summary:"
echo "- Database purged and migrated"
echo "- Master tables populated"
echo "- Test events created"
echo ""
echo "Next steps:"
echo "1. Test the application functionality"
echo "2. Verify all endpoints work correctly"
echo "3. Check user authentication"
echo ""
echo "Development environment is ready for testing."
