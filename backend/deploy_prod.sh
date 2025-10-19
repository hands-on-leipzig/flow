#!/bin/bash

# Production Environment Deployment Script
# This script performs a deployment to the production environment

set -e  # Exit on any error

echo "🚀 Starting Production Environment Deployment..."
echo "==============================================="
echo ""

# Configuration - using environment-specific .env file
BACKEND_DIR="${BACKEND_DIR:-/path/to/backend}"  # Can be overridden via environment variable
ENV_FILE="${ENV_FILE:-prod/.env}"  # Default to prod/.env, can be overridden

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

# Step 1: Run migrations (production-safe)
echo ""
echo "Step 1: Running migrations..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    print_status "Migrations completed"
else
    print_error "Migrations failed"
    exit 1
fi

# Step 2: Clear caches
echo ""
echo "Step 2: Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

print_status "Caches cleared"

# Step 3: Optimize for production
echo ""
echo "Step 3: Optimizing for production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

print_status "Production optimization completed"

# Step 4: Restart queue workers
echo ""
echo "Step 4: Restarting queue workers..."

# Signal queue workers to restart gracefully
php artisan queue:restart

if [ $? -eq 0 ]; then
    print_status "Queue workers signaled to restart"
else
    print_warning "Queue restart signal failed - workers may need manual restart"
fi

# Step 5: Final verification
echo ""
echo "Step 5: Final verification..."

# Check if application is responding
if curl -f -s http://localhost/api/health > /dev/null 2>&1; then
    print_status "Application health check passed"
else
    print_warning "Application health check failed - please verify manually"
fi

# Step 6: Summary
echo ""
echo "✅ Production Environment Deployment Complete!"
echo "==============================================="
echo ""
echo "Summary:"
echo "- Database migrations completed"
echo "- Caches cleared and optimized"
echo "- Application optimized for production"
echo "- Queue workers restarted"
echo ""
echo "Next steps:"
echo "1. Verify queue workers are running: supervisorctl status (or php artisan queue:work)"
echo "2. Verify application functionality"
echo "3. Check all critical endpoints"
echo "4. Monitor application logs"
echo ""
echo "Production environment is live and ready."
