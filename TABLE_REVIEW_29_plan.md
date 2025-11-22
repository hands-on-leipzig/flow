# Table Review #29: plan

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `name` (varchar(100), NOT NULL) ✅
- `event` (int(10) unsigned, NOT NULL, FK) ✅
- `created` (timestamp, nullable) ✅
- `last_change` (timestamp, nullable) ✅
- `generator_status` (varchar(50), nullable) ✅

### Indexes
- `plan_event_foreign` (index on `event`) ✅ (created by FK)

### Foreign Keys
- `event` → `event.id`: RESTRICT on update, **NO ACTION on delete** ⚠️ **Dev DB: NO ACTION, Master: no explicit rule (defaults to RESTRICT)**

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `name`: `string('name', 100)` ✅
- `event`: `unsignedInteger` (NOT NULL, FK) ✅
- `level`: `unsignedInteger` ⚠️ **NOT in Dev DB! (removed in migration `2025_10_13_152816_remove_level_and_first_program_from_plan_table.php`)**
- `first_program`: `unsignedInteger` ⚠️ **NOT in Dev DB! (removed in migration `2025_10_13_152816_remove_level_and_first_program_from_plan_table.php`)**
- `created`: `timestamp()->nullable()` ✅
- `last_change`: `timestamp()->nullable()` ✅
- `generator_status`: `string('generator_status', 50)->nullable()` ✅
- FK: `event` → `event.id` (no explicit rule, defaults to RESTRICT) ⚠️ **Dev DB: NO ACTION**
- FK: `level` → `m_level.id` ⚠️ **Should be removed (column doesn't exist in Dev DB)**
- FK: `first_program` → `m_first_program.id` ⚠️ **Should be removed (column doesn't exist in Dev DB)**

## Usage
- Core table for storing plan data
- Referenced by many tables: `activity_group`, `plan_param_value`, `team_plan`, `match`, `extra_block`, `q_plan`, `s_generator`
- Used in `Plan` model with relationships to `event`, `parameters`, `activityGroups`, `qPlan`

## Questions for Review

1. **Removed Columns:**
   - `level` and `first_program` were removed from Dev DB in migration `2025_10_13_152816_remove_level_and_first_program_from_plan_table.php`
   - Should these columns and their FKs be removed from master migration?

2. **FK Delete Rule:**
   - Dev DB shows NO ACTION on delete for `event` FK
   - Master has no explicit rule (defaults to RESTRICT)
   - Which is correct? NO ACTION and RESTRICT are similar but NO ACTION is deferred.

## Decisions ✅

- [x] **Remove `level` and `first_program` columns and their FKs from master migration** ✅
- [x] **FK delete rule for `event`: CASCADE** ✅

## Implementation

Updated master migration:
- Removed `level` column and FK to `m_level.id`
- Removed `first_program` column and FK to `m_first_program.id`
- Changed FK delete rule for `event` to `onDelete('cascade')`

