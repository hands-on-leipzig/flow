# Table Review #31: publication

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `event` (int(10) unsigned, NOT NULL, FK) ✅
- `level` (int(11), NOT NULL) ⚠️ **Dev DB: signed int(11), Master: integer()**
- `last_change` (timestamp, NOT NULL) ✅

### Indexes
- `publication_event_foreign` (index on `event`) ✅ (created by FK)

### Foreign Keys
- `event` → `event.id`: RESTRICT on update, **CASCADE on delete** ✅

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `event`: `unsignedInteger` (NOT NULL, FK) ✅
- `level`: `integer()` (signed, NOT NULL) ⚠️ **Dev DB: int(11), should check if unsigned makes sense**
- `last_change`: `timestamp()` (NOT NULL) ✅
- FK: `event` → `event.id` with `onDelete('cascade')` ✅

## Usage
- Stores publication level history for events (refactored to store history, not just current level)
- Used in `Publication` model with relationship to `Event`
- Used in `PublishController` for managing publication levels
- Used in `StatisticController` for timeline charts
- `last_change` tracks when the publication level changed

## Questions for Review

1. **Data Type for `level`:**
   - Dev DB: `int(11)` (signed)
   - Master: `integer()` (signed)
   - **Should `level` be `unsignedInteger` or `integer`?** (Publication levels are 1-4, so unsigned makes sense)

2. **FK Delete Rule:**
   - CASCADE is correct (if event is deleted, publication history should be deleted)

## Decisions ✅

- [x] **Change `level` to `unsignedInteger`** ✅
- [x] **FK delete rule: CASCADE** ✅ (already correct)

## Implementation

Updated master migration:
- Changed `level` from `integer()` to `unsignedInteger()` (publication levels are 1-4, so unsigned makes sense)
- FK delete rule already set to CASCADE ✅

