# Table Review #36: room

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `event` (int(10) unsigned, NOT NULL, FK) ✅
- `name` (varchar(100), NOT NULL, default: 'Unnamed Room') ⚠️ **Dev DB: default 'Unnamed Room', Master: no default**
- `navigation_instruction` (text, nullable) ✅
- `sequence` (int(11), NOT NULL, default: 0) ⚠️ **Missing in master migration, signed integer, could be unsigned**
- `is_accessible` (tinyint(1), NOT NULL, default: 1) ⚠️ **Missing in master migration**

### Indexes
- `event` (index on `event`) ✅ (created by FK)
- `room_event_sequence_index` (index on `event`, `sequence`) ⚠️ **Missing in master migration**

### Foreign Keys
- `event` → `event.id`: RESTRICT on update, **CASCADE on delete** ⚠️ **Dev DB: CASCADE, Master: no explicit rule**

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `name`: `string('name', 100)` ⚠️ **Dev DB: default 'Unnamed Room'**
- `room_type`: `unsignedInteger('room_type')->nullable()` ⚠️ **NOT in Dev DB! (removed in migration `2025_10_26_105714_remove_unused_room_fields.php`)**
- `event`: `unsignedInteger('event')` ✅
- `navigation_instruction`: `text('navigation_instruction')->nullable()` ✅
- Missing: `sequence` and `is_accessible` ⚠️
- FK: `room_type` → `m_room_type.id` ⚠️ **Should be removed (column doesn't exist)**
- FK: `event` → `event.id` (no explicit rule) ⚠️ **Dev DB: CASCADE**

## Usage
- Stores room information for events
- Used in `Room` model with relationships to `Event` and `MRoomType` (via `room_type_room` junction table)
- Used in `RoomController` for managing rooms
- `sequence` is used for ordering rooms
- `is_accessible` indicates if room is accessible (used in PDF exports)

## Questions for Review

1. **Removed Column:**
   - `room_type` column and FK were removed from Dev DB in migration `2025_10_26_105714_remove_unused_room_fields.php`
   - Should these be removed from master migration?

2. **Missing Columns:**
   - `sequence`: Added in migration `2025_10_26_111426_add_sequence_to_room_table.php`
   - `is_accessible`: Added in a later migration
   - Should these be added to master migration?

3. **Default Value:**
   - `name`: Dev DB has default 'Unnamed Room', master has no default
   - Should default be added?

4. **Integer Type:**
   - `sequence`: Currently signed `int(11)` in Dev DB
   - Should it be `unsignedInteger()`?

5. **FK Delete Rule:**
   - Dev DB shows CASCADE for `event` FK
   - Master has no explicit rule (defaults to RESTRICT)
   - Should be CASCADE?

6. **Missing Index:**
   - Dev DB has composite index on (`event`, `sequence`)
   - Should this be added?

## Decisions ✅

- [x] **Remove `room_type` column and FK from master migration** ✅
- [x] **Add `sequence` and `is_accessible` columns to master migration** ✅
- [x] **No default for `name`, keep NOT NULL** ✅ (already correct)
- [x] **Change `sequence` to `unsignedInteger`** ✅
- [x] **Add explicit CASCADE delete rule for `event` FK** ✅
- [x] **Add composite index on (`event`, `sequence`)** ✅

## Implementation

Updated master migration:
- Removed `room_type` column and FK to `m_room_type.id`
- Added `sequence` column as `unsignedInteger()->default(0)`
- Added `is_accessible` column as `boolean()->default(true)`
- Kept `name` as NOT NULL without default (as in master)
- Added composite index `room_event_sequence_index` on (`event`, `sequence`)
- Changed FK delete rule for `event` to `onDelete('cascade')`

