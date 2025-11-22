# Table Review #22: extra_block

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `plan` (int(10) unsigned, NOT NULL, FK) ✅
- `first_program` (int(10) unsigned, nullable) ✅
- `name` (varchar(50), nullable) ✅
- `description` (text, nullable) ✅
- `link` (varchar(255), nullable) ✅
- `insert_point` (int(10), nullable) ⚠️ **Dev DB: signed int, should check if FK to `m_insert_point.id`**
- `buffer_before` (int(10), nullable) ⚠️ **Dev DB: signed int, could be unsigned**
- `duration` (int(10), nullable) ⚠️ **Dev DB: signed int, could be unsigned**
- `buffer_after` (int(10), nullable) ⚠️ **Dev DB: signed int, could be unsigned**
- `start` (datetime, nullable) ✅
- `end` (datetime, nullable) ✅
- `room` (int(10) unsigned, nullable, FK) ✅
- `active` (tinyint(1), nullable) ⚠️ **Dev DB: nullable, Master: default(true) but not explicitly nullable**

### Indexes
- `room` (index on `room`) ✅ (created by FK)
- `extra_block_plan_foreign` (index on `plan`) ✅ (created by FK)

### Foreign Keys
- `plan` → `plan.id`: RESTRICT on update, **CASCADE on delete** ✅
- `room` → `room.id`: NO ACTION on update, **SET NULL on delete** ⚠️ **Dev DB: SET NULL, Master: no explicit rule (defaults to RESTRICT)**

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `plan`: `unsignedInteger` (NOT NULL) ✅
- `first_program`: `unsignedInteger()->nullable()` ✅
- `insert_point`: `integer()->nullable()` ⚠️ **Should this be FK to `m_insert_point.id`? Should it be unsigned?**
- `buffer_before`, `duration`, `buffer_after`: `integer()->nullable()` ⚠️ **Should these be unsigned?**
- `room`: `unsignedInteger()->nullable()` ✅
- `active`: `boolean()->default(true)` ⚠️ **Should be explicitly nullable?**
- FK `plan` → `plan.id`: CASCADE ✅
- FK `room` → `room.id`: No explicit rule (defaults to RESTRICT) ⚠️ **Dev DB: SET NULL**

## Usage
- Used for custom "free blocks" in schedules
- Referenced by `ExtraBlock` model with relationships to `plan`, `insertPoint` (m_insert_point), and `room`
- `insert_point` is used in `ExtraBlock::insertPoint()` relationship, suggesting it should be a FK
- Used in `FreeBlockGenerator` and `ActivityWriter` for schedule generation

## Questions for Review

1. **Foreign Key for `insert_point`:**
   - Dev DB shows `insert_point` as `int(10)` (signed), but it references `m_insert_point.id` which is unsigned
   - Should `insert_point` be `unsignedInteger` and have an explicit FK to `m_insert_point.id`?
   - Model shows relationship: `insertPoint()` → `MInsertPoint`

2. **Integer Types:**
   - `buffer_before`, `duration`, `buffer_after`: Currently signed `integer()`. Should these be `unsignedInteger()` since they represent time/duration values?

3. **FK Delete Rule for `room`:**
   - Dev DB: SET NULL on delete (if room is deleted, extra_block.room becomes NULL)
   - Master: No explicit rule (defaults to RESTRICT)
   - Which is correct? SET NULL makes sense - if a room is deleted, the extra block can still exist but without room assignment.

4. **Nullable `active`:**
   - Dev DB: `active` is nullable
   - Master: `boolean()->default(true)` but not explicitly nullable
   - Should be `boolean()->nullable()->default(true)`?

## Decisions ✅

- [x] **Add FK for `insert_point` → `m_insert_point.id` with CASCADE** ✅
- [x] **Change `insert_point` to `unsignedInteger`** ✅
- [x] **Change `buffer_before`, `duration`, `buffer_after` to `unsignedInteger`** ✅
- [x] **Change FK delete rule for `room` to SET NULL** ✅
- [x] **Make `active` NOT NULL with default 0** ✅

## Implementation

Updated master migration:
- Changed `insert_point` to `unsignedInteger()->nullable()` and added FK to `m_insert_point.id` with `onDelete('cascade')`
- Changed `buffer_before`, `duration`, `buffer_after` to `unsignedInteger()->nullable()`
- Changed FK delete rule for `room` to `nullOnDelete()` (SET NULL)
- Changed `active` to `boolean()->default(false)` (NOT NULL, default 0)

