# Table Review #32: q_plan

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `plan` (int(10) unsigned, nullable, FK) ✅
- `q_run` (int(10) unsigned, nullable, FK) ✅
- `name` (varchar(100), NOT NULL) ✅
- `last_change` (timestamp, nullable) ✅
- `c_teams` (int(11), NOT NULL) ⚠️ **Signed integer, could be unsigned**
- `r_tables` (int(11), NOT NULL) ⚠️ **Signed integer, could be unsigned**
- `j_lanes` (int(11), NOT NULL) ⚠️ **Signed integer, could be unsigned**
- `j_rounds` (int(11), NOT NULL) ⚠️ **Signed integer, could be unsigned**
- `r_asym` (tinyint(1), NOT NULL, default: 0) ✅
- `r_robot_check` (tinyint(1), NOT NULL, default: 0) ✅
- `r_duration_robot_check` (int(11), NOT NULL, default: 0) ⚠️ **Signed integer, could be unsigned**
- `c_duration_transfer` (int(11), NOT NULL) ⚠️ **Signed integer, could be unsigned**
- `q1_ok_count` (int(11), nullable) ⚠️ **Signed integer, could be unsigned**
- `q2_ok_count` (int(11), nullable) ⚠️ **Signed integer, could be unsigned**
- `q2_1_count` (int(11), nullable) ⚠️ **Signed integer, could be unsigned**
- `q2_2_count` (int(11), nullable) ⚠️ **Signed integer, could be unsigned**
- `q2_3_count` (int(11), nullable) ⚠️ **Signed integer, could be unsigned**
- `q2_score_avg` (decimal(5,2), nullable) ✅
- `q3_ok_count` (int(11), nullable) ⚠️ **Signed integer, could be unsigned**
- `q3_1_count` (int(11), nullable) ⚠️ **Signed integer, could be unsigned**
- `q3_2_count` (int(11), nullable) ⚠️ **Signed integer, could be unsigned**
- `q3_3_count` (int(11), nullable) ⚠️ **Signed integer, could be unsigned**
- `q3_score_avg` (decimal(5,2), nullable) ✅
- `q4_ok_count` (int(11), nullable) ⚠️ **Signed integer, could be unsigned**
- `q5_idle_avg` (decimal(8,2), nullable) ✅
- `q5_idle_stddev` (decimal(8,2), nullable) ✅
- `q6_duration` (int(11), nullable) ⚠️ **Signed integer, could be unsigned**
- `calculated` (tinyint(1), NOT NULL, default: 0) ✅

### Indexes
- `q_plan_plan_foreign` (index on `plan`) ✅ (created by FK)
- `q_plan_q_run_foreign` (index on `q_run`) ✅ (created by FK)

### Foreign Keys
- `plan` → `plan.id`: RESTRICT on update, **CASCADE on delete** ✅
- `q_run` → `q_run.id`: RESTRICT on update, **CASCADE on delete** ✅

## Master Migration Current State

**Note:** Master migration has an **OUTDATED** `q_plan` table definition that doesn't match Dev DB:
- Master has: `id`, `name`, `event`, `level` (with FKs to `event` and `m_level`)
- Dev DB has: Completely different schema with quality metrics (see columns above)

The table was completely recreated in separate migration `2025_10_13_150204_update_q_run_table_schema.php` and later modified by `2025_10_19_142450_add_q2_q3_distribution_columns_to_q_plan_table.php`.

The separate migration defines:
- Most columns match, but some are missing: `last_change`, `q2_1_count`, `q2_2_count`, `q2_3_count`, `q3_1_count`, `q3_2_count`, `q3_3_count`, `q6_duration`
- Integer columns are `integer()` (signed) ⚠️
- FK delete rules: Not explicit in separate migration (defaults to RESTRICT) ⚠️ **Dev DB: CASCADE**

## Usage
- Stores quality evaluation results for plans
- Used in `QPlan` model with relationships to `Plan` and `QRun`
- Used in quality evaluation services and controllers
- Contains metrics for Q1-Q6 quality criteria

## Questions for Review

1. **Master Migration:**
   - Should `q_plan` table be added to master migration, or stay as separate migration?
   - **Note:** It's a relatively new table (created Oct 2025), so separate migration might be intentional

2. **Integer Types:**
   - All integer columns are signed `int(11)` in Dev DB
   - Separate migration uses `integer()` (signed)
   - Should these be `unsignedInteger()` since they represent positive values (counts, durations, etc.)?

3. **FK Delete Rules:**
   - Dev DB shows CASCADE for both FKs
   - Separate migration has no explicit rules (defaults to RESTRICT)
   - Should both FKs have explicit CASCADE?

4. **Missing Columns:**
   - Separate migration is missing: `last_change`, `q2_1_count`, `q2_2_count`, `q2_3_count`, `q3_1_count`, `q3_2_count`, `q3_3_count`, `q6_duration`
   - These might have been added in later migrations

## Decisions ✅

- [x] **Add correct `q_plan` table to master migration** ✅ (replacing outdated version)
- [x] **Change integer fields to `unsignedInteger`** ✅
- [x] **Both FK delete rules: CASCADE, `plan` NOT NULL** ✅
- [x] **Use all columns from Dev DB** ✅

## Implementation

Updated master migration:
- Replaced outdated `q_plan` definition with complete current schema
- Changed all integer fields to `unsignedInteger()` (counts, durations, etc. are positive values)
- Changed `plan` from nullable to NOT NULL
- Added all columns from Dev DB including: `last_change`, `q2_1_count`, `q2_2_count`, `q2_3_count`, `q3_1_count`, `q3_2_count`, `q3_3_count`, `q6_duration`
- Both FKs with `onDelete('cascade')`:
  - `plan` → `plan.id`: CASCADE, NOT NULL
  - `q_run` → `q_run.id`: CASCADE, nullable

