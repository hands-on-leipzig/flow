# Table Review #44: team_plan

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `team` (int(10) unsigned, NOT NULL, FK to `team.id`)
- `plan` (int(10) unsigned, NOT NULL, FK to `plan.id`)
- `team_number_plan` (int(10), NOT NULL) ⚠️ **Master: nullable**
- `room` (int(10) unsigned, nullable, FK to `room.id`)
- `noshow` (tinyint(1), NOT NULL, default: 0) ⚠️ **Master: missing**

### Indexes
- `plan` (index on `plan`)
- `team` (index on `team`)
- `room` (index on `room`)

### Foreign Keys
- `team` → `team.id`: RESTRICT on update, **CASCADE** on delete ⚠️ **Master: no delete rule (defaults to RESTRICT)**
- `plan` → `plan.id`: RESTRICT on update, **CASCADE** on delete ✅
- `room` → `room.id`: SET NULL on update, **SET NULL** on delete ⚠️ **Master: no delete rule (defaults to RESTRICT)**
- ⚠️ **Note:** Dev DB shows duplicate FK for `room` (`team_plan_ibfk_3` and `team_plan_ibfk_4`) - likely a database inconsistency

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `team`: `unsignedInteger('team')` (NOT NULL) ✅
- `plan`: `unsignedInteger('plan')` (NOT NULL) ✅
- `team_number_plan`: `integer('team_number_plan')->nullable()` ⚠️ **Dev DB: NOT NULL**
- `room`: `unsignedInteger('room')->nullable()` ✅
- Missing `noshow` column ⚠️ **Dev DB: tinyint(1) NOT NULL default 0**
- FK: `team` → `team.id` (no delete rule = RESTRICT) ⚠️ **Dev DB: CASCADE**
- FK: `plan` → `plan.id` with `onDelete('cascade')` ✅
- FK: `room` → `room.id` (no delete rule = RESTRICT) ⚠️ **Dev DB: SET NULL**

## Later Migrations

1. `2025_10_27_074001_add_noshow_to_team_plan_table.php`: Added `noshow` column as `boolean()->default(false)`

## Usage
- Junction table linking teams to plans
- One row per team per plan
- `team_number_plan`: Team number within the plan (NOT NULL in Dev DB)
- `room`: Room assignment for the team in this plan (nullable)
- `noshow`: Whether the team is a no-show for this plan (default: false)
- Used in `PlanController` for syncing team assignments to plans
- Used in `TeamPlan` model with `belongsTo` relationships to `Team` and `Plan`
- When a team is deleted, team_plan entries should be deleted (CASCADE)
- When a plan is deleted, team_plan entries should be deleted (CASCADE)
- When a room is deleted, room assignment should be set to NULL (SET NULL)

## Questions for Review

1. **Missing Column:**
   - `noshow`: Exists in Dev DB but missing in master migration (added in later migration)
   - Should be added to master migration?

2. **Nullable Field:**
   - `team_number_plan`: Dev DB shows NOT NULL, master has nullable
   - Should it be NOT NULL to match Dev DB?

3. **FK Delete Rules:**
   - `team` → `team.id`: Dev DB shows CASCADE, master defaults to RESTRICT
   - `room` → `room.id`: Dev DB shows SET NULL, master defaults to RESTRICT
   - Should these be updated to match Dev DB?

4. **Data Types:**
   - `noshow`: Dev DB shows `tinyint(1)`, migration uses `boolean()` (equivalent) ✅
   - `team_number_plan`: Dev DB shows `int(10)`, master uses `integer()` (equivalent) ✅

## Decisions ✅

- [x] **Add `noshow` column to master migration** ✅ (`boolean()->default(false)`)
- [x] **Make `team_number_plan` NOT NULL** ✅ (to match Dev DB)
- [x] **FK `team` → CASCADE on delete** ✅
- [x] **FK `plan` → CASCADE on delete** ✅ (already correct)
- [x] **FK `room` → SET NULL on delete** ✅

## Implementation

Updated master migration:
- Added `noshow` column as `boolean()->default(false)`
- Changed `team_number_plan` from nullable to NOT NULL
- Changed FK delete rule for `team` from RESTRICT (default) to `onDelete('cascade')`
- Changed FK delete rule for `room` from RESTRICT (default) to `onDelete('set null')`
- FK delete rule for `plan` already had `onDelete('cascade')` ✅

