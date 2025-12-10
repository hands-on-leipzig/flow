# News Table Migration Plan

## Overview

Move `m_news` table out of the master tables (`m_*`) system so that:
1. News lives per environment (dev, test, production each have their own news)
2. News can be created/deleted in any environment (not just dev)
3. News is no longer refreshed/recreated during master table refresh operations

## Current Architecture Issues

### Current State
- `m_news` is a master table (prefixed with `m_`)
- Created in `2025_01_01_000000_create_master_tables.php` (always recreated)
- Also has separate migration `2025_10_21_120706_create_m_news_table.php`
- Included in `refresh_m_tables.php` script (gets dropped/recreated)
- Included in master table export/import system
- Discovered by `MainTablesController::discoverMTables()` (all `m_*` tables)
- UI restricts creation to dev environment only

### Problems
- News created in dev gets wiped when master tables are refreshed in test/prod
- Cannot create news directly in test/production
- News must be transported via master tables export/import (impractical)
- News data is environment-specific but treated as shared reference data

## Migration Strategy

### Phase 1: Database Schema Changes

#### 1.1 Create New Migration: Rename Table
**File**: `backend/database/migrations/YYYY_MM_DD_HHMMSS_rename_m_news_to_news.php`

**Actions**:
- Rename table `m_news` → `news` (preserve all data)
- **Remove** `updated_at` column
- Update foreign key in `news_user` table to reference `news.id` instead of `m_news.id`
- Ensure data preservation during migration

**Key Points**:
- Use `RENAME TABLE` to preserve data
- Drop old foreign key, rename table, drop `updated_at` column, recreate foreign key
- Handle case where table might not exist yet (for fresh installs, master migration will create it)

#### 1.2 Update Master Tables Migration
**File**: `backend/database/migrations/2025_01_01_000000_create_master_tables.php`

**Actions**:
- **Replace** the `m_news` table creation block (lines 42-53) with `news` table
- **Remove** `updated_at` column (not needed)
- **Update** the foreign key in `news_user` table (line 365) to reference `news.id` instead of `m_news.id`
- Keep the pattern: `if (!Schema::hasTable('news'))` to preserve data (like other non-master tables)

**Code to Replace**:
```php
// OLD (lines 42-53):
// Create m_news table (always recreate m_ tables)
if (Schema::hasTable('m_news')) {
    Schema::dropIfExists('m_news');
}
Schema::create('m_news', function (Blueprint $table) {
    $table->unsignedInteger('id')->autoIncrement();
    $table->string('title', 255);
    $table->text('text');
    $table->string('link', 500)->nullable();
    $table->timestamp('created_at')->useCurrent();
    $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
});

// NEW:
// Create news table (only if it doesn't exist - preserve data, NOT a master table)
if (!Schema::hasTable('news')) {
    Schema::create('news', function (Blueprint $table) {
        $table->unsignedInteger('id')->autoIncrement();
        $table->string('title', 255);
        $table->text('text');
        $table->string('link', 500)->nullable();
        $table->timestamp('created_at')->useCurrent();
    });
}
```

**Also Update** `news_user` foreign key (line 365):
```php
// OLD:
$table->foreign('news_id')->references('id')->on('m_news')->onDelete('cascade');

// NEW:
$table->foreign('news_id')->references('id')->on('news')->onDelete('cascade');
```

**Important**: The master migration contains ALL tables (master and non-master). The `news` table should be created with the "preserve data" pattern (`if (!Schema::hasTable('news'))`) like other non-master tables, NOT the "always recreate" pattern used for m_ tables.

#### 1.3 Update Separate News Migration
**File**: `backend/database/migrations/2025_10_21_120706_create_m_news_table.php`

**Actions**:
- **Option A**: Mark as deprecated, add comment that it's replaced by master migration update
- Add deprecation notice at top of file explaining it's been replaced
- Keep migration file for historical reference

**Code to Add**:
```php
/**
 * @deprecated This migration is deprecated.
 * The m_news table has been renamed to 'news' and is now created in 
 * the master migration (2025_01_01_000000_create_master_tables.php).
 * This migration is kept for historical reference only.
 */
```

#### 1.4 Create Rename Migration
**File**: `backend/database/migrations/YYYY_MM_DD_HHMMSS_rename_m_news_to_news.php`

**Actions**:
- Rename table `m_news` → `news` (preserve all data)
- Drop `updated_at` column if it exists
- Update foreign key in `news_user` table to reference `news.id` instead of `m_news.id`
- Handle case where table might not exist yet (fresh installs)

**Migration Code**:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only rename if m_news exists (for existing databases)
        if (Schema::hasTable('m_news')) {
            // Drop foreign key from news_user first
            if (Schema::hasTable('news_user')) {
                try {
                    $databaseName = DB::connection()->getDatabaseName();
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME 
                        FROM information_schema.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = ? 
                        AND TABLE_NAME = 'news_user' 
                        AND REFERENCED_TABLE_NAME = 'm_news'
                    ", [$databaseName]);
                    
                    foreach ($foreignKeys as $fk) {
                        DB::statement("ALTER TABLE `news_user` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    }
                } catch (\Throwable $e) {
                    // Ignore if foreign key doesn't exist
                }
            }
            
            // Rename table
            DB::statement('RENAME TABLE `m_news` TO `news`');
            
            // Remove updated_at column if it exists
            if (Schema::hasColumn('news', 'updated_at')) {
                Schema::table('news', function (Blueprint $table) {
                    $table->dropColumn('updated_at');
                });
            }
            
            // Recreate foreign key in news_user
            if (Schema::hasTable('news_user')) {
                Schema::table('news_user', function (Blueprint $table) {
                    $table->foreign('news_id')->references('id')->on('news')->onDelete('cascade');
                });
            }
        }
    }

    public function down(): void
    {
        // Reverse: rename news back to m_news
        if (Schema::hasTable('news')) {
            // Drop foreign key first
            if (Schema::hasTable('news_user')) {
                try {
                    $databaseName = DB::connection()->getDatabaseName();
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME 
                        FROM information_schema.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = ? 
                        AND TABLE_NAME = 'news_user' 
                        AND REFERENCED_TABLE_NAME = 'news'
                    ", [$databaseName]);
                    
                    foreach ($foreignKeys as $fk) {
                        DB::statement("ALTER TABLE `news_user` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
            
            // Add updated_at column back
            if (!Schema::hasColumn('news', 'updated_at')) {
                Schema::table('news', function (Blueprint $table) {
                    $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->after('created_at');
                });
            }
            
            // Rename back
            DB::statement('RENAME TABLE `news` TO `m_news`');
            
            // Recreate foreign key
            if (Schema::hasTable('news_user')) {
                Schema::table('news_user', function (Blueprint $table) {
                    $table->foreign('news_id')->references('id')->on('m_news')->onDelete('cascade');
                });
            }
        }
    }
};
```

### Phase 2: Update Scripts and Controllers

#### 2.1 Update `refresh_m_tables.php`
**File**: `backend/database/scripts/refresh_m_tables.php`

**Changes**:
- **Remove** `m_news` from the list of tables to drop
- **Remove** `'2025_10_21_120706_create_m_news_table'` from `$mTableMigrations` array (line 84)
- **Remove** the special handling for `news_user` foreign key to `m_news` (lines 127-145)
- **Update** comment/documentation to clarify `news` is NOT a master table

**Lines to Modify**:
- Line 84: Remove `'2025_10_21_120706_create_m_news_table'`
- Lines 127-145: Remove or update the `news_user` foreign key drop logic (may still need it for other reasons)

#### 2.2 Update `MainTablesController`
**File**: `backend/app/Http/Controllers/Api/MainTablesController.php`

**Changes**:
- **Modify** `discoverMTables()` method to **exclude** `news` table
- Add explicit exclusion: `if ($tableName === 'news') continue;`

**Code Change**:
```php
private function discoverMTables(): array
{
    // ... existing code ...
    foreach ($tables as $table) {
        $tableName = $table->$tableKey;
        if (str_starts_with($tableName, 'm_')) {
            // Exclude news table (was m_news, now regular table)
            if ($tableName === 'news') {
                continue;
            }
            $mTableNames[] = $tableName;
        }
    }
    // ... rest of code ...
}
```

#### 2.3 Update Export Commands
**Files**:
- `backend/app/Console/Commands/ExportMainData.php`
- `backend/app/Console/Commands/ExportMainDataToCsv.php`

**Changes**:
- **Exclude** `news` (and `m_news` for backward compatibility) from exports
- Update `discoverMTables()` method or add explicit exclusion

**Code Pattern**:
```php
$mTableNames = [];
foreach ($tables as $table) {
    $tableName = $table->$tableKey;
    if (str_starts_with($tableName, 'm_')) {
        // Exclude news table
        if ($tableName === 'news' || $tableName === 'm_news') {
            continue;
        }
        $mTableNames[] = $tableName;
    }
}
```

#### 2.4 Update Database Scripts
**Files to Check/Update**:
- `backend/database/scripts/export_main_data.php` - Exclude news
- `backend/database/scripts/deploy_test_environment.php` - Don't populate news
- `backend/database/scripts/fresh_test_database.php` - Don't clear news
- `backend/database/scripts/compare_schema_to_master.php` - Exclude news
- `backend/database/scripts/generate_sync_migration.php` - Exclude news
- `backend/database/scripts/generate_sync_migration_simple.php` - Exclude news
- `backend/database/scripts/check_migrations.php` - Update if needed
- `backend/database/scripts/check_extra_tables.php` - Exclude news
- `backend/database/scripts/verify_schema_complete.php` - Exclude news

**Pattern**: Add `news` to exclusion lists or update table discovery logic

#### 2.5 Update Foreign Key Migrations
**Files**:
- `backend/database/migrations/2025_11_14_080000_add_missing_foreign_keys_and_fixes.php`
- `backend/database/migrations/2025_10_21_120956_create_news_user_table.php`

**Changes**:
- **Update** all foreign key definitions for `news_user.news_id` to reference `news.id` instead of `m_news.id`
- Update references in both migrations

**Code Changes**:
```php
// OLD (in both files):
$table->foreign('news_id')->references('id')->on('m_news')->onDelete('cascade');
// AND
AND REFERENCED_TABLE_NAME = 'm_news'

// NEW:
$table->foreign('news_id')->references('id')->on('news')->onDelete('cascade');
// AND
AND REFERENCED_TABLE_NAME = 'news'
```

**Specific Locations**:
- `2025_10_21_120956_create_news_user_table.php`: Lines 39, 57, 69
- `2025_11_14_080000_add_missing_foreign_keys_and_fixes.php`: Line 170 (if exists)

### Phase 3: Update Models

#### 3.1 Update `MNews` Model
**File**: `backend/app/Models/MNews.php`

**Changes**:
- **Option A**: Rename model to `News` (selected)
- **Update** `protected $table = 'news';` (was `'m_news'`)
- **Remove** `updated_at` from `$fillable` and casts (if present)
- **Update** model to not use timestamps or only use `created_at`

**Action**: Rename file and class to `News`

**New File**: `backend/app/Models/News.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    protected $table = 'news';
    
    public $timestamps = true; // Only created_at, updated_at column removed from schema
    
    const UPDATED_AT = null; // Explicitly disable updated_at since column doesn't exist
    
    protected $fillable = [
        'title',
        'text',
        'link',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        // No updated_at cast
    ];

    /**
     * Get the users who have read this news.
     */
    public function readByUsers()
    {
        return $this->belongsToMany(User::class, 'news_user', 'news_id', 'user_id')
            ->withPivot('read_at')
            ->withTimestamps();
    }
}
```

**Note**: `const UPDATED_AT = null;` explicitly tells Laravel not to try to update the `updated_at` column since it doesn't exist in the database schema.

#### 3.2 Update Model References
**Files to Update**:
- `backend/app/Http/Controllers/Api/NewsController.php` - Change `MNews` to `News`
- `backend/app/Models/NewsUser.php` - Update relationship
- Any other files using `MNews`

**Search Pattern**: `grep -r "MNews" backend/`

### Phase 4: Update Frontend

#### 4.1 Remove Dev-Only Restriction
**File**: `frontend/src/components/molecules/SystemNews.vue`

**Changes**:
- **Remove** `isDevEnvironment` prop requirement for create button
- **Remove** `isDevEnvironment` check from button click handler
- **Remove** disabled state and "nur Dev" text
- **Update** button to always be enabled (for admins)

**Lines to Modify**:
- Line 110: Remove `@click="isDevEnvironment && (showCreateForm = true)"`
- Line 111: Remove `:disabled="!isDevEnvironment"`
- Line 112: Remove title tooltip about dev-only
- Lines 114-116: Remove conditional classes based on `isDevEnvironment`
- Line 119: Remove `(nur Dev)` span

**New Code**:
```vue
<button
  v-if="!showCreateForm"
  @click="showCreateForm = true"
  class="font-semibold py-2 px-4 rounded-lg transition-colors duration-200 bg-blue-600 hover:bg-blue-700 text-white"
>
  ➕ Neue News erstellen
</button>
```

#### 4.2 Update Admin Component
**File**: `frontend/src/components/Admin.vue`

**Changes**:
- **Remove** `isDevEnvironment` prop passing to `SystemNews` component
- Line 389: Change from `:is-dev-environment="isDevEnvironment"` to remove prop entirely

**New Code**:
```vue
<SystemNews />
```

#### 4.3 Remove News from Main Tables Admin
**File**: `frontend/src/components/molecules/MainTablesAdmin.vue`

**Changes**:
- **Remove** hardcoded `m_news` entry from the master tables list
- Line 254: Remove or comment out the entry

**Code to Remove**:
```javascript
{ name: 'm_news', displayName: 'News', recordCount: 0 },
```

**Reason**: News is no longer a master table, so it shouldn't appear in the master tables admin interface. News management is handled separately in the System News component.

### Phase 5: Update Documentation

#### 5.1 Update Deployment Guide
**File**: `backend/DEPLOYMENT_GUIDE.md`

**Changes**:
- **Remove** `m_news` from "Main Tables (Will Be Refreshed)" section
- **Add** `news` to "Data Tables (Will Be Preserved)" section
- Update any references to news table management

#### 5.2 Update Deep Dive Document
**File**: `SYSTEMS_NEWS_DEEP_DIVE.md`

**Changes**:
- Update table name references from `m_news` to `news`
- Update model name from `MNews` to `News`
- Update architecture section to reflect per-environment storage
- Remove references to master table system

### Phase 6: Testing Checklist

#### 6.1 Database Migration Tests
- [ ] Migration runs successfully on fresh database (news created by master migration)
- [ ] Migration preserves existing data when renaming (m_news → news)
- [ ] `updated_at` column is removed from existing tables
- [ ] Foreign keys are correctly updated (news_user.news_id → news.id)
- [ ] Master migration creates news table correctly (without updated_at)
- [ ] Rollback works correctly (if needed)

#### 6.2 Backend Tests
- [ ] News can be created in any environment
- [ ] News can be deleted in any environment
- [ ] News is NOT included in master table exports
- [ ] News is NOT dropped during `refresh_m_tables`
- [ ] NewsController works with new model/table name
- [ ] Unread news detection still works
- [ ] Mark as read functionality works

#### 6.3 Frontend Tests
- [ ] Create button is enabled in all environments (for admins)
- [ ] News list displays correctly
- [ ] News creation form works
- [ ] News deletion works
- [ ] News modal appears for users
- [ ] Mark as read works

#### 6.4 Integration Tests
- [ ] Master table refresh doesn't affect news
- [ ] Master table export doesn't include news
- [ ] Master table import doesn't overwrite news
- [ ] News persists across deployments

## Migration Execution Order

### Step 1: Prepare (No Breaking Changes)
1. Update `MainTablesController` to exclude `news`/`m_news`
2. Update export commands to exclude news
3. Update scripts to exclude news
4. Test that news still works with old table name

### Step 2: Create New Model (Backward Compatible)
1. Create new `News` model alongside `MNews`
2. Update `NewsController` to use both (for transition)
3. Test that both work

### Step 3: Database Migration
1. Update master migration (replace m_news with news, remove updated_at)
2. Create rename migration (m_news → news, drop updated_at)
3. Test migrations on dev database
4. Run migrations on dev

### Step 4: Update Code References
1. Update all `MNews` references to `News`
2. Update all `m_news` table references to `news`
3. Remove old `MNews` model
4. Test all functionality

### Step 5: Update Master Tables
1. Remove `m_news` from `create_master_tables.php`
2. Update `refresh_m_tables.php`
3. Test master table refresh (news should persist)

### Step 6: Update Frontend
1. Remove dev-only restrictions
2. Test in all environments
3. Verify admin can create news everywhere

### Step 7: Deploy
1. Deploy to test environment
2. Verify news persists after master table refresh
3. Create test news in test environment
4. Deploy to production
5. Verify production news is preserved

## Rollback Plan

If issues occur:

1. **Database Rollback**:
   - Rename `news` back to `m_news`
   - Update foreign keys back
   - Restore `m_news` in master tables migration

2. **Code Rollback**:
   - Revert model name to `MNews`
   - Revert table references
   - Restore dev-only UI restrictions

3. **Script Rollback**:
   - Restore news to master table discovery
   - Restore export inclusion

## Risk Assessment

### Low Risk
- Model/table name changes (well-isolated)
- Frontend UI changes (simple removal of restrictions)

### Medium Risk
- Database migration (data preservation critical)
- Foreign key updates (must be atomic)
- Master table refresh script (must not drop news)

### High Risk
- Production deployment (news data is important)
- Master table refresh in production (could accidentally drop news)

### Mitigation
- Test thoroughly in dev/test first
- Backup production database before migration
- Verify news table is excluded from all master table operations
- Monitor logs during deployment
- Have rollback plan ready

## Files Summary

### Files to Create
1. `backend/database/migrations/YYYY_MM_DD_HHMMSS_rename_m_news_to_news.php` (rename + drop updated_at)
2. `backend/app/Models/News.php` (rename from MNews, remove updated_at references)

### Files to Modify
1. `backend/database/migrations/2025_01_01_000000_create_master_tables.php` - Replace m_news with news (remove updated_at, update foreign key)
2. `backend/database/migrations/2025_10_21_120706_create_m_news_table.php` - Add deprecation notice (Option A)
3. `backend/database/migrations/2025_10_21_120956_create_news_user_table.php` - Update foreign key references
3. `backend/database/scripts/refresh_m_tables.php` - Exclude news
4. `backend/app/Http/Controllers/Api/MainTablesController.php` - Exclude news
5. `backend/app/Http/Controllers/Api/NewsController.php` - Update model
6. `backend/app/Models/NewsUser.php` - Update relationship
7. `backend/app/Console/Commands/ExportMainData.php` - Exclude news
8. `backend/app/Console/Commands/ExportMainDataToCsv.php` - Exclude news
9. `backend/database/scripts/*.php` - Multiple scripts to update
10. `frontend/src/components/molecules/SystemNews.vue` - Remove dev restriction
11. `frontend/src/components/molecules/MainTablesAdmin.vue` - Remove m_news entry
12. `frontend/src/components/Admin.vue` - Remove prop
13. `backend/DEPLOYMENT_GUIDE.md` - Update docs
14. `SYSTEMS_NEWS_DEEP_DIVE.md` - Update docs

### Files to Delete (Optional)
1. `backend/app/Models/MNews.php` - After migration complete

## Estimated Effort

- **Database Migrations**: 2-3 hours
- **Backend Code Updates**: 3-4 hours
- **Script Updates**: 2-3 hours
- **Frontend Updates**: 1 hour
- **Testing**: 4-6 hours
- **Documentation**: 1-2 hours
- **Total**: 13-19 hours

## Success Criteria

1. ✅ News table exists as `news` (not `m_news`)
2. ✅ News persists across master table refreshes
3. ✅ News can be created/deleted in any environment (by admins)
4. ✅ News is excluded from master table exports/imports
5. ✅ All existing news data is preserved
6. ✅ All functionality works as before
7. ✅ No references to `m_news` remain in codebase (except migration history)

