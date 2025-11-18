# Publication Refactoring Plan

## Current State
- `publication` table has **one entry per event** (using `updateOrInsert`)
- When level changes, the existing row is updated (history is lost)
- `created_at` represents when the publication was first created
- `updated_at` represents when the level was last changed

## Desired State
- `publication` table stores **history** - each level change creates a new entry
- First entry implicitly carries the "created" information (via `created_at`)
- Multiple entries per event are allowed
- Current level = latest entry (by `created_at` or `id`)

## Database Schema Review

### Current Schema
```php
Schema::create('publication', function (Blueprint $table) {
    $table->unsignedInteger('id')->autoIncrement();
    $table->unsignedInteger('event');
    $table->integer('level');
    $table->timestamps(); // created_at, updated_at
    $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
});
```

### New Schema (After Migration)
```php
Schema::create('publication', function (Blueprint $table) {
    $table->unsignedInteger('id')->autoIncrement();
    $table->unsignedInteger('event');
    $table->integer('level');
    $table->timestamp('last_change'); // Replaces created_at and updated_at
    $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
});
```

### Required Changes
- ✅ No unique constraint on `event` (already allows multiple entries)
- ✅ Foreign key with `ON DELETE CASCADE` (already correct)
- ✅ **Migration needed**: Replace `created_at`/`updated_at` with `last_change`
- ⚠️ **Migration**: `2025_11_18_171710_update_publication_table_add_last_change.php`
  - Adds `last_change` column
  - Copies data from `updated_at` (or `created_at` if `updated_at` is null) to `last_change`
  - Removes `created_at` and `updated_at` columns

## Impact Analysis

### 1. Backend Controllers

#### A. `PublishController::setPublicationLevel()` (Lines 392-407)
**Current:** Uses `updateOrInsert()` - updates existing or creates new
```php
DB::table('publication')
    ->updateOrInsert(
        ['event' => $eventId],
        ['level' => $level, 'updated_at' => Carbon::now(),]
    );
```

**Change Required:**
- Always INSERT a new row (never update)
- Only insert if level actually changed (avoid duplicates)
- Check latest level before inserting
- Set `last_change` to current timestamp (Carbon::now())

**Impact:** HIGH - Core functionality for setting publication level

#### B. `PublishController::getPublicationLevel()` (Lines 365-389)
**Current:** Gets first entry, creates if missing
```php
$publication = DB::table('publication')
    ->where('event', $eventId)
    ->first();
```

**Change Required:**
- Get **latest** entry (by `last_change` DESC or `id` DESC)
- Still create initial entry if none exists
- Use `last_change` instead of `created_at`/`updated_at`

**Impact:** MEDIUM - Used by frontend to display current level

#### C. `PublishController::scheduleInformation()` (Lines 281-361)
**Current:** Gets first entry
```php
$publication = DB::table('publication')
    ->where('event', $eventId)
    ->select('level')
    ->first();
```

**Change Required:**
- Get **latest** entry (by `last_change` DESC or `id` DESC)
- Use `last_change` instead of `created_at`/`updated_at`

**Impact:** MEDIUM - Public API endpoint

#### D. `StatisticController::listPlans()` (Lines 22-31)
**Current:** Gets latest publication per event using subquery
```php
->leftJoin(DB::raw('(
    SELECT p.event, p.level, p.created_at, p.updated_at
    FROM publication p
    INNER JOIN (
        SELECT event, MAX(updated_at) as max_updated
        FROM publication
        GROUP BY event
    ) latest
    ON p.event = latest.event AND p.updated_at = latest.max_updated
) as pub'), 'pub.event', '=', 'event.id')
```

**Change Required:**
- Change `MAX(updated_at)` to `MAX(last_change)` (or `MAX(id)`)
- This already handles multiple entries correctly, just needs to use `last_change` instead of `updated_at`

**Impact:** MEDIUM - Statistics display

#### E. `StatisticController::timeline()` (Lines 593-640)
**Current:** Gets all publications ordered by `created_at`
```php
$publications = DB::table('publication')
    ->join('event', 'event.id', '=', 'publication.event')
    ->join('plan', 'plan.event', '=', 'event.id')
    ->where('plan.id', $planId)
    ->select('publication.level', 'publication.created_at', 'publication.updated_at')
    ->orderBy('publication.created_at')
    ->get();
```

**Change Required:**
- ✅ Already correct! Uses `last_change` for ordering (after migration)
- Remove `updated_at` from select (not needed, column removed)
- Use `last_change` for interval calculation

**Impact:** LOW - Already handles history correctly

#### F. `StatisticController::publicationTotals()` (Lines 437-456)
**Current:** Counts all publication entries
```php
$levels = DB::table('publication')
    ->select('level', DB::raw('COUNT(*) as count'))
    ->groupBy('level')
    ->pluck('count', 'level');
```

**Change Required:**
- Count only **latest** entries per event (one per event)
- Use subquery to get latest publication per event, then count levels

**Impact:** MEDIUM - Statistics totals box

#### G. `PlanController::destroy()` (Lines 460-466)
**Current:** Deletes all publications for event
```php
$pubDeleted = DB::table('publication')->where('event', $eventId)->delete();
```

**Change Required:**
- ✅ Already correct! Deletes all history entries (cascade would handle this too)

**Impact:** NONE - Already correct

### 2. Frontend Components

#### A. `Statistics.vue`
**Current:** Displays `publication_level`, `publication_date`, `publication_last_change`

**Change Required:**
- ✅ Already uses latest publication from backend
- No changes needed (backend provides latest)

**Impact:** NONE - Already correct

#### B. `OnlineAccessBox.vue`
**Current:** 
- `fetchPublicationLevel()` calls `/publish/level/{eventId}` (gets latest)
- `updatePublicationLevel()` calls `POST /publish/level/{eventId}` (sets level)

**Change Required:**
- ✅ Already works with latest level
- No changes needed (backend handles latest)

**Impact:** NONE - Already correct

#### C. `TimelineChart.vue`
**Current:** Receives `publication_intervals` from backend

**Change Required:**
- ✅ Already correct! Backend provides all intervals

**Impact:** NONE - Already correct

### 3. Models

#### A. `Publication.php`
**Current:** Basic Eloquent model

**Change Required:**
- Add scope method: `latestForEvent($eventId)` - returns latest publication for event
- Add scope method: `forEvent($eventId)` - returns all publications for event (ordered)
- Consider adding relationship: `Event::hasMany(Publication::class)`

**Impact:** LOW - Optional improvement

## Implementation Plan

### Phase 0: Database Migration
0. **Run migration `2025_11_18_171710_update_publication_table_add_last_change.php`**
   - Adds `last_change` column
   - Copies data from `updated_at` to `last_change`
   - Removes `created_at` and `updated_at` columns

### Phase 1: Backend Core Changes
1. **Update `PublishController::setPublicationLevel()`**
   - Check current latest level
   - Only insert if level changed
   - Always INSERT (never UPDATE)
   - Set `last_change` to Carbon::now()

2. **Update `PublishController::getPublicationLevel()`**
   - Get latest entry (by `last_change` DESC or `id` DESC)
   - Create initial entry if none exists
   - Set `last_change` to Carbon::now() when creating

3. **Update `PublishController::scheduleInformation()`**
   - Get latest entry (by `last_change` DESC or `id` DESC)

### Phase 2: Statistics Updates
4. **Update `StatisticController::listPlans()`**
   - Change `MAX(updated_at)` to `MAX(last_change)` in subquery
   - Update field references from `created_at`/`updated_at` to `last_change`

5. **Update `StatisticController::publicationTotals()`**
   - Count only latest entries per event

6. **Update `StatisticController::timeline()`**
   - Change `created_at` to `last_change` in select and ordering
   - Use `last_change` for interval calculation

### Phase 3: Model Improvements (Optional)
7. **Enhance `Publication.php` model**
   - Add scope methods for common queries
   - Add relationship to Event model

### Phase 4: Testing & Validation
8. **Test level changes**
   - Verify new entries are created
   - Verify latest level is retrieved correctly
   - Verify history is preserved

9. **Test statistics**
   - Verify totals count only latest entries
   - Verify timeline shows all intervals correctly

10. **Test frontend**
    - Verify publication level display works
    - Verify level changes work correctly

## Migration Strategy

### Schema Migration
- **Migration file**: `2025_11_18_171710_update_publication_table_add_last_change.php`
- Adds `last_change` column
- Copies data from `updated_at` (or `created_at` if `updated_at` is null) to `last_change`
- Removes `created_at` and `updated_at` columns

### Data Migration
- Existing single entries will become the "first" entry
- First entry's `last_change` will contain the value from `updated_at` (or `created_at`)
- New entries will be created going forward with `last_change` set to current timestamp

### Backward Compatibility
- All queries that get "current" level need to use latest entry
- Queries that need history need to use `last_change` ordering (replaces `created_at`)
- No breaking changes to API contracts (same data, different column name)

## Risk Assessment

### Low Risk
- Timeline chart (already handles multiple entries)
- Frontend components (already work with latest)
- Plan deletion (already deletes all entries)

### Medium Risk
- Statistics queries (need to ensure latest is used)
- Public API endpoints (need to return latest level)

### High Risk
- `setPublicationLevel()` - core functionality, must work correctly
- Need to prevent duplicate entries (same level, same timestamp)

## Edge Cases to Handle

1. **Same level set twice quickly**
   - Check if latest entry has same level before inserting
   - Avoid duplicate entries with same level

2. **No publication entry exists**
   - Create initial entry with level 1 (current behavior)

3. **Multiple entries with same timestamp**
   - Use `id` DESC as secondary sort (higher ID = more recent)
   - `last_change` will be set to Carbon::now() for each new entry

4. **Event deletion**
   - Cascade delete already handles this (FK constraint)

## Testing Checklist

- [ ] Setting publication level creates new entry
- [ ] Setting same level twice doesn't create duplicate
- [ ] Getting publication level returns latest
- [ ] Statistics show latest level per event
- [ ] Statistics totals count only latest entries
- [ ] Timeline shows all level changes correctly
- [ ] Frontend displays current level correctly
- [ ] Frontend can change level correctly
- [ ] Plan deletion removes all publication entries
- [ ] Public API returns latest level

## Notes

- The database schema already supports multiple entries per event
- **Schema change**: Replace `created_at`/`updated_at` with `last_change` column
- Most code already handles multiple entries (timeline, statistics subquery)
- Main change is in `setPublicationLevel()` - from UPDATE to INSERT
- Need to ensure all "get current level" queries use latest entry (by `last_change` DESC)
- All timestamp references need to change from `created_at`/`updated_at` to `last_change`

