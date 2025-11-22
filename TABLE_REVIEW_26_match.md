# Table Review #26: match

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `plan` (int(10) unsigned, NOT NULL, FK) ✅
- `round` (int(11), NOT NULL) ⚠️ **Signed integer, could be unsigned**
- `match_no` (int(11), NOT NULL) ⚠️ **Signed integer, could be unsigned**
- `table_1` (int(11), NOT NULL) ⚠️ **Signed integer, could be unsigned**
- `table_2` (int(11), NOT NULL) ⚠️ **Signed integer, could be unsigned**
- `table_1_team` (int(11), NOT NULL) ⚠️ **Signed integer, could be unsigned**
- `table_2_team` (int(11), NOT NULL) ⚠️ **Signed integer, could be unsigned**

### Indexes
- `match_plan_foreign` (index on `plan`) ✅ (created by FK)

### Foreign Keys
- `plan` → `plan.id`: RESTRICT on update, **CASCADE on delete** ✅

## Master Migration Current State

**Note:** `match` table is NOT in master migration. It's created by separate migration `2025_10_14_042537_create_match_table.php`.

The separate migration defines:
- `id`: `unsignedInteger` ✅
- `plan`: `unsignedInteger` ✅
- `round`, `match_no`, `table_1`, `table_2`, `table_1_team`, `table_2_team`: `integer()` (signed) ⚠️
- FK: `plan` → `plan.id` with `onDelete('cascade')` ✅

## Usage
- Stores match entries for Robot Game (Challenge) plans
- Used by `MatchEntry` model
- Used in `ChallengeGenerator`, `MatchRotationService`, and preview/export controllers
- Represents matches with two teams playing on two tables per match

## Questions for Review

1. **Master Migration:**
   - Should `match` table be added to master migration, or stay as separate migration?
   - **Note:** It's a relatively new table (created Oct 2025), so separate migration might be intentional

2. **Integer Types:**
   - All integer fields except `plan` are signed `int(11)` in Dev DB
   - Separate migration uses `integer()` (signed)
   - Should these be `unsignedInteger()` since they represent positive values (round numbers, match numbers, table numbers, team numbers)?

3. **FK Delete Rule:**
   - CASCADE is correct (if plan is deleted, matches should be deleted)

## Decisions ✅

- [x] **Add `match` table to master migration** ✅
- [x] **Change integer fields to `unsignedInteger`** ✅

## Implementation

Added to master migration after `plan_param_value`:
- `id`: `unsignedInteger` ✅
- `plan`: `unsignedInteger` (FK) ✅
- `round`, `match_no`, `table_1`, `table_2`, `table_1_team`, `table_2_team`: Changed from `integer()` to `unsignedInteger()` ✅
- FK: `plan` → `plan.id` with `onDelete('cascade')` ✅
- Added to `down()` method for rollback

