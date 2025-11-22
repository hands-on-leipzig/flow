# Table Review #43: team

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `first_program` (int(10) unsigned, NOT NULL)
- `name` (varchar(100), NOT NULL, default: 'Unnamed Team') ⚠️ **Master: no default**
- `event` (int(10) unsigned, NOT NULL, FK to `event.id`)
- `team_number_hot` (smallint(6) unsigned, NOT NULL) ⚠️ **Master: nullable, integer**
- `location` (varchar(255), nullable) ✅
- `organization` (varchar(255), nullable) ✅

### Indexes
- `team_event_foreign` (index on `event`)

### Foreign Keys
- `event` → `event.id`: RESTRICT on update, **CASCADE** on delete ⚠️ **Master: no delete rule (defaults to RESTRICT)**

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `name`: `string('name', 100)` (no default) ⚠️ **Dev DB: default 'Unnamed Team'**
- `event`: `unsignedInteger('event')` (NOT NULL) ✅
- `room`: `unsignedInteger('room')->nullable()` ⚠️ **Dev DB: This column does NOT exist!**
- `first_program`: `unsignedInteger('first_program')` (NOT NULL) ✅
- `team_number_hot`: `integer('team_number_hot')->nullable()` ⚠️ **Dev DB: NOT NULL, smallint(6) unsigned**
- `noshow`: `boolean('noshow')->default(false)` ⚠️ **Dev DB: This column does NOT exist!**
- `location`: `string('location', 255)->nullable()` ✅
- `organization`: `string('organization', 255)->nullable()` ✅
- FK: `event` → `event.id` (no delete rule = RESTRICT) ⚠️ **Dev DB: CASCADE**
- FK: `room` → `room.id` with `onDelete('set null')` ⚠️ **Dev DB: This FK does NOT exist!**
- FK: `first_program` → `m_first_program.id` (no delete rule = RESTRICT) ✅

## Later Migrations

1. `2025_10_26_105714_remove_unused_room_fields.php`: Removed `room` column from `team` table
2. `2025_10_27_073956_remove_noshow_from_team_table.php`: Removed `noshow` column from `team` table

## Usage
- Stores team information for events
- One row per team per event
- `name`: Team name (default: 'Unnamed Team')
- `event`: Foreign key to `event.id`
- `first_program`: Foreign key to `m_first_program.id` (Explore or Challenge)
- `team_number_hot`: Team number from HoT system (NOT NULL in Dev DB)
- `location`: Team location (nullable)
- `organization`: Team organization (nullable)
- Used in `TeamController` for managing teams
- Used in `Event` model with `hasMany` relationship
- When an event is deleted, teams should be deleted (CASCADE)

## Questions for Review

1. **Extra Columns:**
   - Master migration has `room` column that doesn't exist in Dev DB (removed in later migration)
   - Master migration has `noshow` column that doesn't exist in Dev DB (removed in later migration)
   - Should these columns be removed from master migration?

2. **Default Value:**
   - `name`: Should have default 'Unnamed Team' (matches Dev DB)

3. **Data Type:**
   - `team_number_hot`: Dev DB shows `smallint(6) unsigned` NOT NULL, master uses `integer()->nullable()`
   - Should it be `unsignedSmallInteger()` and NOT NULL to match Dev DB?

4. **FK Delete Rule:**
   - Dev DB: CASCADE on delete for `event`
   - Master: No delete rule (defaults to RESTRICT)
   - Should it be CASCADE to match Dev DB? (Makes sense: if event is deleted, teams should be deleted)

5. **Column Order:**
   - Dev DB order: `id`, `first_program`, `name`, `event`, `team_number_hot`, `location`, `organization`
   - Master order: `id`, `name`, `event`, `room`, `first_program`, `team_number_hot`, `noshow`, `location`, `organization`
   - Should master match Dev DB order?

## Decisions ✅

- [x] **Remove `room` column from master migration** ✅ (doesn't exist in Dev DB)
- [x] **Remove `noshow` column from master migration** ✅ (doesn't exist in Dev DB)
- [x] **No default for `name`** ✅
- [x] **Keep `team_number_hot` type as is (`integer`), but make it NOT NULL** ✅
- [x] **FK `first_program` → `m_first_program.id` RESTRICT** ✅ (explicit)
- [x] **FK `event` → `event.id` CASCADE** ✅

## Implementation

Updated master migration:
- Removed `room` column (doesn't exist in Dev DB)
- Removed `noshow` column (doesn't exist in Dev DB)
- Kept `name` without default value
- Changed `team_number_hot` from nullable to NOT NULL (kept as `integer()`)
- Changed FK delete rule for `event` from RESTRICT (default) to `onDelete('cascade')`
- Made FK delete rule for `first_program` explicit `onDelete('restrict')`

