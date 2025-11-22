# Table Review #38: s_generator

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `plan` (int(10) unsigned, **nullable**, FK to `plan.id`)
- `start` (timestamp, nullable)
- `end` (timestamp, nullable)
- `mode` (varchar(255), nullable)

### Indexes
- `s_generator_plan_foreign` (index on `plan`) ⚠️ **Dev DB: single column index, Migration: composite `['plan', 'start']`**

### Foreign Keys
- `plan` → `plan.id`: RESTRICT on update, **SET NULL** on delete ⚠️ **Migration: CASCADE**

## Master Migration Current State

**Note:** `s_generator` is NOT in the master migration. It's defined in a separate migration file: `2025_09_10_061841_create_s_generator_table.php`

From the migration file:
- `id`: `unsignedInteger` ✅
- `plan`: `unsignedInteger` (NOT NULL) ⚠️ **Dev DB: nullable, later migration made it nullable**
- `start`: `timestamp()->nullable()` ✅
- `end`: `timestamp()->nullable()` ✅
- `mode`: `string()->nullable()` ✅ (defaults to varchar(255), matches Dev DB)
- FK: `plan` → `plan.id` with `onDelete('cascade')` ⚠️ **Dev DB: SET NULL**
- Index: Composite index on `['plan', 'start']` ⚠️ **Dev DB: only index on `plan`**
- No timestamps (model uses `$timestamps = false`) ✅

## Later Migrations

1. `2025_11_15_083300_make_s_generator_plan_nullable.php`: Made `plan` nullable and changed FK delete rule to SET NULL
2. `2025_11_19_145457_remove_unused_timestamps_from_s_generator_table.php`: Removed `created_at` and `updated_at` if they existed

## Usage
- Statistics table tracking generator runs per plan
- One row per generator execution
- `start` and `end` timestamps track execution duration
- `mode` indicates if run was via 'job' or 'direct'
- `plan` is nullable to preserve history even if plan is deleted (SET NULL on delete)
- Used in `StatisticController` for timeline charts showing generator runs per day
- Used in `PlanGeneratorService` to log generator start/end times

## Questions for Review

1. **Should `s_generator` be added to master migration?**
   - Currently it's in a separate migration file
   - For consistency, should it be in the master migration?

2. **FK Delete Rule:**
   - Migration has CASCADE, but later migration changed to SET NULL (matches Dev DB)
   - SET NULL makes sense to preserve generator history even if plan is deleted
   - Should master migration reflect SET NULL?

3. **Index:**
   - Migration has composite index on `['plan', 'start']`
   - Dev DB only shows index on `plan`
   - Which is correct? Composite index would be better for queries filtering by plan and sorting by start time

4. **Nullable Fields:**
   - `plan`: Should be nullable (SET NULL on delete) ✅
   - `start`, `end`, `mode`: All nullable ✅

5. **Data Types:**
   - All types match ✅
   - `mode` length: Migration defaults to 255, Dev DB shows 255 ✅

## Decisions ✅

- [x] **Add `s_generator` table to master migration** ✅
- [x] **plan FK: CASCADE, NOT NULL** ✅
  - `plan` → `plan.id`: CASCADE on delete
  - `plan` column: NOT NULL (not nullable)
- [x] **Single index on `plan`** ✅ (not composite)
- [x] **mode explicitly `string('mode', 255)`** ✅

## Implementation

Added `s_generator` table to master migration:
- `id`: `unsignedInteger` ✅
- `plan`: `unsignedInteger` (NOT NULL), FK to `plan.id` with `onDelete('cascade')`
- `start`: `timestamp()->nullable()` ✅
- `end`: `timestamp()->nullable()` ✅
- `mode`: `string('mode', 255)->nullable()` ✅
- Single index on `plan` column
- No timestamps (model uses `$timestamps = false`) ✅

**Note:** This differs from the current Dev DB state where `plan` is nullable with SET NULL. The decision is to use CASCADE with NOT NULL, which means generator history will be deleted when a plan is deleted.

