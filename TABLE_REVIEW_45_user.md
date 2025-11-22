# Table Review #45: user

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `nick` (varchar(255), nullable) ✅
- `subject` (varchar(255), nullable) ⚠️ **Missing in master migration**
- `name` (varchar(255), nullable) ⚠️ **Missing in master migration**
- `email` (varchar(255), nullable) ⚠️ **Missing in master migration**
- `dolibarr_id` (int(11), nullable) ✅
- `lang` (varchar(10), nullable) ✅
- `last_login` (timestamp, nullable) ✅
- `selection_regional_partner` (int(10) unsigned, nullable, FK to `regional_partner.id`)
- `selection_event` (int(10) unsigned, nullable, FK to `event.id`)

### Indexes
- `selection_event` (index on `selection_event`)
- `selection_regional_partner` (index on `selection_regional_partner`)

### Foreign Keys
- `selection_event` → `event.id`: RESTRICT on update, RESTRICT on delete ✅
- `selection_regional_partner` → `regional_partner.id`: RESTRICT on update, RESTRICT on delete ✅

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `nick`: `string('nick', 255)->nullable()` ✅
- `subject`: `string('subject', 255)->nullable()` ✅
- Missing `name` column ⚠️ **Dev DB: varchar(255) nullable**
- Missing `email` column ⚠️ **Dev DB: varchar(255) nullable**
- `dolibarr_id`: `integer('dolibarr_id')->nullable()` ✅
- `lang`: `string('lang', 10)->nullable()` ✅
- `last_login`: `timestamp('last_login')->nullable()` ✅
- `selection_regional_partner`: `unsignedInteger('selection_regional_partner')->nullable()` ✅
- `selection_event`: `unsignedInteger('selection_event')->nullable()` ✅
- FK: `selection_regional_partner` → `regional_partner.id` (no delete rule = RESTRICT) ✅
- FK: `selection_event` → `event.id` (no delete rule = RESTRICT) ✅

## Usage
- Stores user account information
- `nick`: User nickname (nullable)
- `subject`: User subject/identifier from JWT (nullable)
- `name`: User full name (nullable)
- `email`: User email address (nullable)
- `dolibarr_id`: Reference to external Dolibarr system (nullable)
- `lang`: User language preference (nullable)
- `last_login`: Timestamp of last login (nullable)
- `selection_regional_partner`: Currently selected regional partner (nullable, FK)
- `selection_event`: Currently selected event (nullable, FK)
- Used in `User` model (extends `Authenticatable`)
- Used in `UserRegionalPartnerController` for managing user-regional partner relationships
- Used in `NewsUser` model for tracking read news items
- FKs are RESTRICT to prevent deletion of referenced records (appropriate for user selections)

## Questions for Review

1. **Missing Columns:**
   - `name`: Missing in master migration (exists in Dev DB and model `$fillable`)
   - `email`: Missing in master migration (exists in Dev DB and model `$fillable`)
   - Should these be added to master migration?

2. **FK Delete Rules:**
   - Both FKs are RESTRICT (matches Dev DB) ✅
   - This is appropriate: if a regional partner or event is deleted, user selections should be prevented (not cascade)

3. **Data Types:**
   - All types match between Dev DB and master ✅
   - `dolibarr_id`: Dev DB shows `int(11)`, master uses `integer()` (equivalent) ✅

4. **Indexes:**
   - Dev DB has indexes on `selection_event` and `selection_regional_partner`
   - Laravel auto-creates indexes for FK columns, so this is likely already handled

## Decisions ✅

- [x] **Add `subject` column to master migration** ✅ (already present, verified)
- [x] **Add `name` column to master migration** ✅ (`string('name', 255)->nullable()`)
- [x] **Add `email` column to master migration** ✅ (`string('email', 255)->nullable()`)
- [x] **FK `selection_regional_partner` and `selection_event` SET NULL on delete** ✅

## Implementation

Updated master migration:
- `subject` column already present ✅
- Added `name` column as `string('name', 255)->nullable()`
- Added `email` column as `string('email', 255)->nullable()`
- Changed FK delete rule for `selection_regional_partner` from RESTRICT (default) to `onDelete('set null')`
- Changed FK delete rule for `selection_event` from RESTRICT (default) to `onDelete('set null')`

