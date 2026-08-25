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
# Note: SEED_MAIN_DATA parameter removed - no longer used (replaced by update_m_tables_from_json.php)

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

# Database backup (production only - before any changes)
if [ "$FIX_MIGRATION_RECORDS" == "true" ]; then
  echo "💾 Creating database backup before deployment..."
  BACKUP_DIR="$HOME/backups"
  mkdir -p "$BACKUP_DIR"
  BACKUP_FILE="$BACKUP_DIR/backup_$(date +%Y%m%d_%H%M%S).sql"
  
  # Get database credentials from Laravel config
  DB_HOST=$(php artisan tinker --execute="echo config('database.connections.mysql.host');" 2>/dev/null | grep -v "Tinker" | head -1 || echo "localhost")
  DB_DATABASE=$(php artisan tinker --execute="echo config('database.connections.mysql.database');" 2>/dev/null | grep -v "Tinker" | head -1)
  DB_USERNAME=$(php artisan tinker --execute="echo config('database.connections.mysql.username');" 2>/dev/null | grep -v "Tinker" | head -1)
  DB_PASSWORD=$(php artisan tinker --execute="echo config('database.connections.mysql.password');" 2>/dev/null | grep -v "Tinker" | head -1)
  
  if [ -n "$DB_DATABASE" ] && [ -n "$DB_USERNAME" ]; then
    echo "  Backing up database: $DB_DATABASE"
    if [ -n "$DB_PASSWORD" ]; then
      mysqldump -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" > "$BACKUP_FILE" 2>/dev/null || {
        echo "⚠️  WARNING: Database backup failed (may not have mysqldump access)"
        echo "  Continuing without backup..."
      }
    else
      mysqldump -h "$DB_HOST" -u "$DB_USERNAME" "$DB_DATABASE" > "$BACKUP_FILE" 2>/dev/null || {
        echo "⚠️  WARNING: Database backup failed (may not have mysqldump access)"
        echo "  Continuing without backup..."
      }
    fi
    
    if [ -f "$BACKUP_FILE" ] && [ -s "$BACKUP_FILE" ]; then
      BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
      echo "  ✓ Backup created: $BACKUP_FILE ($BACKUP_SIZE)"
      # Keep only last 10 backups
      ls -t "$BACKUP_DIR"/backup_*.sql 2>/dev/null | tail -n +11 | xargs rm -f 2>/dev/null || true
    else
      echo "  ⚠️  Backup file is empty or missing"
    fi
  else
    echo "  ⚠️  Could not determine database credentials, skipping backup"
  fi
fi

# Fix migration records (production only)
if [ "$FIX_MIGRATION_RECORDS" == "true" ]; then
  echo "🔧 Fixing migration records for existing tables..."
  php artisan tinker --execute="include 'database/scripts/fix_migration_records.php'; fixMigrationRecords();" || {
    echo "⚠️  WARNING: fix_migration_records.php failed, but continuing..."
  }
fi

# Check migration status before running (production only)
if [ "$VERIFY_MIGRATIONS" == "true" ]; then
  echo "📊 Checking migration status before running..."
  php artisan migrate:status || echo "ℹ️  Note: migrate:status may fail if migrations table doesn't exist yet"
fi

# Run migrations (MUST run before updating m-tables to ensure schema matches JSON)
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

# Update m_ tables from JSON (test and production only)
# This runs AFTER migrations to ensure schema matches JSON structure
if [ "$REFRESH_M_TABLES" == "true" ]; then
  echo "🔄 Updating m_ tables from JSON (FK checks ENABLED)..."
  # tinker often exits 0 even when PHP throws; capture output and require the success line.
  M_TABLES_OUT=$(php artisan tinker --execute="include 'database/scripts/update_m_tables_from_json.php'; updateMTablesFromJson('database/exports/main-tables-latest.json');" 2>&1)
  M_TABLES_EXIT=$?
  echo "$M_TABLES_OUT"
  if [ "$M_TABLES_EXIT" -ne 0 ] \
    || echo "$M_TABLES_OUT" | grep -qE 'Exception|SQLSTATE\[|ERROR: update_m_tables' \
    || ! echo "$M_TABLES_OUT" | grep -q 'All m-tables updated successfully'; then
    echo "❌ ERROR: update_m_tables_from_json.php failed"
    exit 1
  fi
  echo "✓ Master tables updated"
fi

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

# Restart queue workers (required after code deployment)
echo "🔄 Restarting queue workers..."
php artisan queue:restart || {
  echo "⚠️  WARNING: queue:restart failed (queue workers may not be running)"
  echo "If queue workers are managed by supervisor, they will auto-restart."
}
echo "✓ Queue workers restarted"

echo "✅ Deployment finalization completed successfully!"

