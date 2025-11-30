#!/bin/bash
# Deployment finalization script
# This script handles the server-side deployment steps after code has been rsynced

set -e  # Exit on error

# Variables passed from GitHub Actions
TEMP_DIR="$1"
PUBLIC_DIR="$2"
REFRESH_M_TABLES="$3"
FIX_MIGRATION_RECORDS="$4"
VERIFY_MIGRATIONS="$5"
SEED_MAIN_DATA="$6"

echo "🚀 Starting deployment finalization..."
echo "  Temp directory: ~/$TEMP_DIR"
echo "  Public directory: ~/public_html/$PUBLIC_DIR"

# Move files from temp to public directory
echo "📦 Moving files to public directory..."
rsync -av --delete \
  --exclude='.env' \
  --exclude='.htaccess' \
  --exclude='storage/' \
  ~/$TEMP_DIR/ ~/public_html/$PUBLIC_DIR/

cd ~/public_html/$PUBLIC_DIR

# Ensure storage directories exist
echo "📁 Creating storage directories..."
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/public

# Set permissions
echo "🔐 Setting permissions..."
find storage/framework -type d -exec chmod 775 {} \; 2>/dev/null || true
find storage/app -type d -exec chmod 775 {} \; 2>/dev/null || true

# Clear Laravel caches
echo "🧹 Clearing Laravel caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Verify JSON export file exists (for test and production)
if [ "$REFRESH_M_TABLES" == "true" ]; then
  if [ ! -f "database/exports/main-tables-latest.json" ]; then
    echo "❌ ERROR: main-tables-latest.json not found in database/exports/"
    ls -la database/exports/ || echo "database/exports/ directory does not exist"
    exit 1
  fi
  echo "✓ JSON export file found"
fi

# Fix migration records (production only)
if [ "$FIX_MIGRATION_RECORDS" == "true" ]; then
  echo "🔧 Fixing migration records for existing tables..."
  php artisan tinker --execute="include 'database/scripts/fix_migration_records.php'; fixMigrationRecords();" || {
    echo "⚠️  WARNING: fix_migration_records.php failed, but continuing..."
  }
fi

# Drop and refresh m_ tables (test and production only)
if [ "$REFRESH_M_TABLES" == "true" ]; then
  echo "🗑️  Dropping m_ tables..."
  php artisan tinker --execute="include 'database/scripts/refresh_m_tables.php'; refreshMTables();" || {
    echo "❌ ERROR: refresh_m_tables.php failed"
    exit 1
  }
  echo "✓ Master tables refreshed"
fi

# Check migration status before running (production only)
if [ "$VERIFY_MIGRATIONS" == "true" ]; then
  echo "📊 Checking migration status before running..."
  php artisan migrate:status || echo "ℹ️  Note: migrate:status may fail if migrations table doesn't exist yet"
fi

# Run migrations
echo "🔄 Running migrations..."
php artisan migrate --force || {
  echo "❌ ERROR: Migrations failed!"
  if [ "$VERIFY_MIGRATIONS" == "true" ]; then
    echo "📊 Checking migration status after failure..."
    php artisan migrate:status || true
  fi
  exit 1
}
echo "✓ Migrations completed successfully"

# Verify migrations (production only)
if [ "$VERIFY_MIGRATIONS" == "true" ]; then
  echo "✅ Verifying migrations..."
  php artisan migrate:status || echo "ℹ️  Note: migrate:status command output above"
  echo ""
  echo "📊 Counting migration records in database..."
  php artisan tinker --execute="
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    if (Schema::hasTable('migrations')) {
      \$count = DB::table('migrations')->count();
      echo 'Migration records in database: ' . \$count . PHP_EOL;
      if (\$count > 0) {
        echo 'Sample migration records:' . PHP_EOL;
        DB::table('migrations')->orderBy('id', 'desc')->limit(5)->get()->each(function(\$m) {
          echo '  - ' . \$m->migration . ' (batch: ' . \$m->batch . ')' . PHP_EOL;
        });
      } else {
        echo 'WARNING: Migrations table is empty!' . PHP_EOL;
      }
    } else {
      echo 'ERROR: migrations table does NOT exist!' . PHP_EOL;
    }
  " || {
    echo "❌ ERROR: Failed to check migration records"
    exit 1
  }
  
  echo ""
  echo "📊 Verifying table count..."
  TABLE_COUNT=$(php artisan tinker --execute="
    \$tables = DB::select('SHOW TABLES');
    \$tableKey = 'Tables_in_' . DB::connection()->getDatabaseName();
    echo count(\$tables);
  " 2>/dev/null | grep -E '^[0-9]+$' || echo "0")
  echo "Current table count: $TABLE_COUNT"
  echo "Expected: ~45 tables (dev/test have 45)"
  if [ "$TABLE_COUNT" -lt "40" ]; then
    echo "❌ ERROR: Table count is too low! Only $TABLE_COUNT tables found, expected ~45."
    echo "This indicates migrations did not run successfully."
    echo "Listing all tables:"
    php artisan tinker --execute="
      \$tables = DB::select('SHOW TABLES');
      \$tableKey = 'Tables_in_' . DB::connection()->getDatabaseName();
      foreach (\$tables as \$table) {
        echo '  - ' . \$table->\$tableKey . PHP_EOL;
      }
    " || true
    exit 1
  else
    echo "✓ Table count verification passed ($TABLE_COUNT tables)"
  fi
fi

# Create storage symlink
echo "🔗 Creating storage symlink..."
php artisan storage:link
echo "✓ Storage symlink created"

# Populate master data (test and production only)
if [ "$SEED_MAIN_DATA" == "true" ]; then
  echo "🌱 Running MainDataSeeder..."
  php artisan db:seed --class=MainDataSeeder --force || {
    echo "❌ ERROR: MainDataSeeder failed"
    echo "Checking if JSON file exists:"
    ls -la database/exports/main-tables-latest.json
    echo "Checking JSON file content (first 100 chars):"
    head -c 100 database/exports/main-tables-latest.json
    exit 1
  }
  echo "✓ Master data seeded successfully"
fi

echo "✅ Deployment finalization completed successfully!"

