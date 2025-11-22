# Table Review #42: table_event

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `event` (int(10) unsigned, NOT NULL, FK to `event.id`)
- `table_number` (int(11), NOT NULL, default: 1) ⚠️ **Master: no default**
- `table_name` (varchar(100), NOT NULL, default: 'Unnamed Table') ⚠️ **Master: no default**

### Indexes
- `fk_event` (index on `event`)

### Foreign Keys
- `event` → `event.id`: RESTRICT on update, **CASCADE** on delete ⚠️ **Master: no delete rule (defaults to RESTRICT)**

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `name`: `string('name', 100)` ⚠️ **Dev DB: This column does NOT exist!**
- `table_name`: `string('table_name', 100)` ✅ (but no default value)
- `table_number`: `integer('table_number')` ⚠️ **Dev DB: default 1**
- `event`: `unsignedInteger('event')` (NOT NULL) ✅
- FK: `event` → `event.id` (no delete rule = RESTRICT) ⚠️ **Dev DB: CASCADE**

## Later Migrations

1. `2025_10_16_082606_add_default_values_to_table_event.php`: Added default values to `name`, `table_name`, and `table_number`
   - But Dev DB doesn't have a `name` column, only `table_name`

## Usage
- Stores table names/numbers for events (e.g., Robot Game tables)
- One row per table per event
- `table_number`: Numeric identifier for the table (default: 1)
- `table_name`: Display name for the table (default: 'Unnamed Table')
- `event`: Foreign key to `event.id`
- Used in `Event` model with `hasMany` relationship
- When an event is deleted, table names should be deleted (CASCADE)

## Questions for Review

1. **Extra Column:**
   - Master migration has `name` column that doesn't exist in Dev DB
   - Should this column be removed from master migration?

2. **Default Values:**
   - `table_number`: Should have default 1 (matches Dev DB)
   - `table_name`: Should have default 'Unnamed Table' (matches Dev DB)

3. **FK Delete Rule:**
   - Dev DB: CASCADE on delete
   - Master: No delete rule (defaults to RESTRICT)
   - Should it be CASCADE to match Dev DB? (Makes sense: if event is deleted, table names should be deleted)

4. **Column Order:**
   - Dev DB order: `id`, `event`, `table_number`, `table_name`
   - Master order: `id`, `name`, `table_name`, `table_number`, `event`
   - Should master match Dev DB order?

## Decisions ✅

- [x] **Remove `name` column from master migration** ✅ (doesn't exist in Dev DB)
- [x] **No default for `table_number`** ✅
- [x] **No default for `table_name`** ✅
- [x] **FK delete rule: CASCADE** ✅ (to match Dev DB)

## Implementation

Updated master migration:
- Removed `name` column (doesn't exist in Dev DB)
- Kept `table_number` without default value
- Kept `table_name` without default value
- Changed FK delete rule from RESTRICT (default) to `onDelete('cascade')` to match Dev DB

