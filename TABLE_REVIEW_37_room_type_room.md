# Table Review #37: room_type_room

## Current Schema (Dev DB)

### Columns
- `id` (int(11), NOT NULL, PRIMARY KEY, auto_increment) ⚠️ **Should be `unsignedInteger`**
- `room_type` (int(10) unsigned, NOT NULL, FK to `m_room_type.id`)
- `room` (int(10) unsigned, NOT NULL, FK to `room.id`)
- `event` (int(10) unsigned, NOT NULL, FK to `event.id`)

### Indexes
- `event` (index on `event`)
- `room` (index on `room`)
- `room_type` (index on `room_type`)

### Foreign Keys
- `room` → `room.id`: RESTRICT on update, **CASCADE** on delete ✅
- `event` → `event.id`: RESTRICT on update, **CASCADE** on delete ⚠️ **Master: no delete rule (defaults to RESTRICT)**
- `room_type` → `m_room_type.id`: ⚠️ **Not shown in Dev DB export, but exists in master migration**

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `room_type`: `unsignedInteger` (NOT NULL), FK to `m_room_type` (no delete rule = RESTRICT)
- `room`: `unsignedInteger` (NOT NULL), FK to `room` with `onDelete('cascade')` ✅
- `event`: `unsignedInteger` (NOT NULL), FK to `event` (no delete rule = RESTRICT) ⚠️ **Dev DB: CASCADE**
- Indexes: Not explicitly defined in master (Laravel auto-creates indexes for FKs)

## Usage
- Junction table for many-to-many relationship between `room` and `m_room_type`
- Used in `Room` and `MRoomType` models with `belongsToMany` relationship
- `event` column links the relationship to a specific event
- When a room is deleted, the relationship should be deleted (CASCADE)
- When an event is deleted, the relationship should be deleted (CASCADE)
- When a room type is deleted, the relationship should be deleted (CASCADE) or prevented (RESTRICT)?

## Questions for Review

1. **ID Type:**
   - Dev DB: `int(11)`, Master: `unsignedInteger` ✅ (master is correct)

2. **FK Delete Rules:**
   - `room` → `room.id`: CASCADE ✅ (matches Dev DB and master)
   - `event` → `event.id`: Dev DB shows CASCADE, master defaults to RESTRICT. Should it be CASCADE?
   - `room_type` → `m_room_type.id`: Master defaults to RESTRICT. Should it be CASCADE?

3. **Indexes:**
   - Dev DB has explicit indexes on `event`, `room`, `room_type`
   - Laravel auto-creates indexes for FK columns, so this is likely already handled
   - No unique constraint needed (a room can have multiple room types, a room type can be assigned to multiple rooms)

4. **Nullable Fields:**
   - All columns are NOT NULL ✅ (correct for junction table)

## Decisions ✅

- [x] **ID type: `unsignedInteger`** ✅ (already correct in master)
- [x] **All three FK delete rules: CASCADE** ✅
  - `room_type` → `m_room_type.id`: CASCADE
  - `room` → `room.id`: CASCADE
  - `event` → `event.id`: CASCADE
- [x] **Add explicit indexes** ✅
  - Index on `room_type`
  - Index on `room`
  - Index on `event`

## Implementation

Updated master migration:
- ID type is already `unsignedInteger` (correct)
- Changed all three FK delete rules to `onDelete('cascade')`
- Added explicit indexes on `room_type`, `room`, and `event`

