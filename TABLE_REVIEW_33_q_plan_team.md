# Table Review #33: q_plan_team

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `q_plan` (int(10) unsigned, NOT NULL, FK) ✅
- `team` (int(10) unsigned, NOT NULL, FK) ✅
- `q1_ok` (tinyint(1), NOT NULL, default: 0) ✅
- `q1_transition_1_2` (decimal(8,2), NOT NULL, default: 0.00) ✅
- `q1_transition_2_3` (decimal(8,2), NOT NULL, default: 0.00) ✅
- `q1_transition_3_4` (decimal(8,2), NOT NULL, default: 0.00) ✅
- `q1_transition_4_5` (decimal(8,2), NOT NULL, default: 0.00) ✅
- `q2_ok` (tinyint(1), NOT NULL, default: 0) ✅
- `q2_tables` (int(11), NOT NULL, default: 0) ⚠️ **Signed integer, could be unsigned**
- `q3_ok` (tinyint(1), NOT NULL, default: 0) ✅
- `q3_teams` (int(11), NOT NULL, default: 0) ⚠️ **Signed integer, could be unsigned**
- `q4_ok` (tinyint(1), NOT NULL, default: 0) ✅
- `q5_idle_0_1` (int(11), NOT NULL, default: 0) ⚠️ **Signed integer, could be unsigned**
- `q5_idle_1_2` (int(11), NOT NULL, default: 0) ⚠️ **Signed integer, could be unsigned**
- `q5_idle_2_3` (int(11), NOT NULL, default: 0) ⚠️ **Signed integer, could be unsigned**
- `q5_idle_avg` (decimal(8,2), NOT NULL, default: 0.00) ✅

### Indexes
- `q_plan_team_q_plan_foreign` (index on `q_plan`) ✅ (created by FK)
- `q_plan_team_team_foreign` (index on `team`) ✅ (created by FK)

### Foreign Keys
- `q_plan` → `q_plan.id`: RESTRICT on update, **CASCADE on delete** ✅
- `team` → `team.id`: RESTRICT on update, **CASCADE on delete** ✅

## Master Migration Current State

**Note:** Master migration has a **SIMPLIFIED** `q_plan_team` table definition that doesn't match Dev DB:
- Master has: `id`, `q_plan`, `team` (with FKs)
- Dev DB has: Complete schema with all quality metric columns (see columns above)

The table was completely recreated in separate migration `2025_10_13_150204_update_q_run_table_schema.php` with the full schema.

The separate migration defines:
- `team`: `integer()` (signed) ⚠️ **Dev DB: unsigned**
- `q2_tables`, `q3_teams`, `q5_idle_*`: `integer()` (signed) ⚠️ **Dev DB: signed, but could be unsigned**
- FK: `q_plan` → `q_plan.id` (no explicit rule) ⚠️ **Dev DB: CASCADE**
- FK: `team` → `team.id` (exists in separate migration, but no explicit rule) ⚠️ **Dev DB: CASCADE**

## Usage
- Stores per-team quality evaluation results for a quality plan
- Used in `QPlanTeam` model with relationship to `QPlan`
- Used in `QualityEvaluatorService` for calculating quality metrics

## Questions for Review

1. **Integer Types:**
   - `team`: Dev DB is `unsigned`, separate migration uses `integer()` (signed)
   - `q2_tables`, `q3_teams`, `q5_idle_*`: Dev DB is signed `int(11)`, but these represent counts/durations (positive values)
   - Should these be `unsignedInteger()`?

2. **FK Delete Rules:**
   - Dev DB shows CASCADE for both FKs
   - Separate migration has no explicit rules (defaults to RESTRICT)
   - Should both FKs have explicit CASCADE?
   - Should `team` FK be added to master migration?

3. **Master Migration:**
   - Need to check if master migration has the correct definition or if it needs updating

## Decisions ✅

- [x] **Replace simplified definition with complete Dev DB schema** ✅
- [x] **Change integer fields to `unsignedInteger`** ✅
- [x] **Both FK delete rules: CASCADE (explicit)** ✅

## Implementation

Updated master migration:
- Replaced simplified `q_plan_team` definition with complete current schema
- Changed all integer fields to `unsignedInteger()`:
  - `team`: `unsignedInteger` (was `integer()` in separate migration)
  - `q2_tables`, `q3_teams`, `q5_idle_0_1`, `q5_idle_1_2`, `q5_idle_2_3`: `unsignedInteger`
- Added all quality metric columns from Dev DB
- `team` column: `unsignedInteger` with NO FK (just an integer reference)
- FK with explicit `onDelete('cascade')`:
  - `q_plan` → `q_plan.id`: CASCADE

