# Table Review #21: event_logo

## Current Schema (Dev DB)

### Columns
- `id` (int(11), NOT NULL, PRIMARY KEY, auto_increment) ⚠️ **Should be `unsignedInteger`**
- `event` (int(10) unsigned, nullable, FK) ⚠️ **Dev DB: nullable, Master: NOT NULL**
- `logo` (int(10) unsigned, nullable, FK) ⚠️ **Dev DB: nullable, Master: NOT NULL**
- `sort_order` (smallint(5) unsigned, NOT NULL, default: 0) ✅

### Indexes
- `just_one` (unique index on `event`, `logo`) ⚠️ **Missing in master migration**
- `logo` (index on `logo`) ⚠️ **Missing in master migration**

### Foreign Keys
- `event` → `event.id`: RESTRICT on update, RESTRICT on delete ✅
- `logo` → `logo.id`: RESTRICT on update, RESTRICT on delete ✅

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `event`: `unsignedInteger` (NOT NULL) ⚠️ **Dev DB: nullable**
- `logo`: `unsignedInteger` (NOT NULL) ⚠️ **Dev DB: nullable**
- `sort_order`: `unsignedSmallInteger` with default 0 ✅
- Missing unique index on (`event`, `logo`)
- Missing index on `logo`
- FK delete rules: Not explicit (defaults to RESTRICT, which matches Dev DB)

## Usage
- Junction table for many-to-many relationship between `event` and `logo`
- Used in `Event` and `Logo` models with `belongsToMany` relationship
- `sort_order` determines display order of logos per event
- Unique constraint prevents duplicate logo assignments per event

## Questions for Review

1. **Nullable Fields:**
   - `event` and `logo`: Dev DB shows nullable, master has NOT NULL. Which is correct?
   - **Note:** Junction tables typically have NOT NULL FKs, but Dev DB shows nullable. This might be intentional for flexibility.

2. **Unique Constraint:**
   - Dev DB has unique index `just_one` on (`event`, `logo`). Should this be added to master migration?
   - This prevents assigning the same logo to an event twice.

3. **Indexes:**
   - Dev DB has index on `logo` column. Should this be added for query performance?

4. **Data Types:**
   - `id`: Should be `unsignedInteger` (master is correct, Dev DB uses `int(11)`)
   - All other types look correct.

5. **FK Delete Rules:**
   - Both FKs are RESTRICT (correct for junction table - prevents orphaned records)

## Decisions ✅

- [x] **All columns NOT NULL** ✅ (already correct in master)
- [x] **sort_order default 0** ✅ (already correct)
- [x] **Add unique constraint on (`event`, `logo`)** ✅ (added as `just_one`)
- [x] **Do NOT add index on `logo` column** ✅
- [x] **FK delete rules: Both CASCADE** ✅
  - `event` → `event.id`: CASCADE
  - `logo` → `logo.id`: CASCADE
- [x] **ID type: `unsignedInteger`** ✅ (already correct in master)

## Implementation

Updated master migration:
- All columns remain NOT NULL (already correct)
- Added unique constraint `just_one` on (`event`, `logo`)
- Changed FK delete rules to `onDelete('cascade')` for both FKs
- ID type is already `unsignedInteger` (correct)

