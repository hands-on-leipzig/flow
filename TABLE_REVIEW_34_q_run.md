# Table Review #34: q_run

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `name` (varchar(100), NOT NULL) ✅
- `comment` (text, nullable) ✅
- `selection` (text, nullable) ✅
- `started_at` (timestamp, nullable) ✅
- `finished_at` (timestamp, nullable) ✅
- `status` (varchar(20), NOT NULL, default: 'pending') ✅
- `host` (varchar(100), nullable) ✅
- `qplans_total` (int(11), NOT NULL, default: 0) ⚠️ **Signed integer, could be unsigned**
- `qplans_calculated` (int(11), NOT NULL, default: 0) ⚠️ **Signed integer, could be unsigned**

### Indexes
- No indexes (no FKs)

### Foreign Keys
- No foreign keys

## Master Migration Current State

**Note:** Master migration has an **OUTDATED** `q_run` table definition that doesn't match Dev DB:
- Master has: `id`, `q_plan`, `team`, `start`, `end` (with FKs to `q_plan` and `team`)
- Dev DB has: Completely different schema (see columns above)

The table was completely recreated in separate migration `2025_10_13_150204_update_q_run_table_schema.php` with the correct schema.

The separate migration defines:
- All columns match Dev DB ✅
- `qplans_total` and `qplans_calculated`: `integer()` (signed) ⚠️ **Dev DB: signed, but could be unsigned**

## Usage
- Stores quality evaluation run information
- Used in `QRun` model with relationship to `QPlan` (hasMany)
- Used in `QualityController` and `ExecuteQPlanJob` for managing quality evaluation runs
- Tracks status, timing, and statistics for quality evaluation batches

## Questions for Review

1. **Master Migration:**
   - Should `q_run` table be added to master migration, or stay as separate migration?
   - **Note:** It's a relatively new table (created Oct 2025), so separate migration might be intentional

2. **Integer Types:**
   - `qplans_total` and `qplans_calculated`: Currently signed `int(11)` in Dev DB
   - Separate migration uses `integer()` (signed)
   - Should these be `unsignedInteger()` since they represent counts (positive values)?

## Decisions ✅

- [x] **Add correct `q_run` table to master migration** ✅ (replacing outdated version)
- [x] **Change `qplans_total` and `qplans_calculated` to `unsignedInteger`** ✅

## Implementation

Updated master migration:
- Replaced outdated `q_run` definition with complete current schema from Dev DB
- Changed `qplans_total` and `qplans_calculated` from `integer()` to `unsignedInteger()` (counts are positive values)
- Added all columns from Dev DB: `name`, `comment`, `selection`, `started_at`, `finished_at`, `status`, `host`, `qplans_total`, `qplans_calculated`

