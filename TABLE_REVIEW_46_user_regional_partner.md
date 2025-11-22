# Table Review #46: user_regional_partner

## Current Schema (Dev DB)

### Columns
- `id` (int(11), NOT NULL, PRIMARY KEY, auto_increment) ⚠️ **Should be `unsignedInteger`**
- `user` (int(10) unsigned, NOT NULL, FK to `user.id`)
- `regional_partner` (int(10) unsigned, NOT NULL, FK to `regional_partner.id`)

### Indexes
- `regional_partner` (index on `regional_partner`)
- `user` (index on `user`)

### Foreign Keys
- `user` → `user.id`: RESTRICT on update, **CASCADE** on delete ⚠️ **Master: no delete rule (defaults to RESTRICT)**
- `regional_partner` → `regional_partner.id`: RESTRICT on update, **CASCADE** on delete ⚠️ **Master: no delete rule (defaults to RESTRICT)**

## Master Migration Current State

- `id`: `unsignedInteger` ✅ (correct, Dev DB shows `int(11)` but should be `unsignedInteger`)
- `user`: `unsignedInteger('user')` (NOT NULL) ✅
- `regional_partner`: `unsignedInteger('regional_partner')` (NOT NULL) ✅
- FK: `user` → `user.id` (no delete rule = RESTRICT) ⚠️ **Dev DB: CASCADE**
- FK: `regional_partner` → `regional_partner.id` (no delete rule = RESTRICT) ⚠️ **Dev DB: CASCADE**

## Usage
- Junction table for many-to-many relationship between `user` and `regional_partner`
- One row per user-regional partner relationship
- Used in `User` model with `belongsToMany` relationship
- Used in `UserRegionalPartnerController` for managing user-regional partner assignments
- When a user is deleted, their regional partner assignments should be deleted (CASCADE)
- When a regional partner is deleted, user assignments should be deleted (CASCADE)

## Questions for Review

1. **ID Type:**
   - Dev DB: `int(11)`, Master: `unsignedInteger` ✅ (master is correct)

2. **FK Delete Rules:**
   - `user` → `user.id`: Dev DB shows CASCADE, master defaults to RESTRICT
   - `regional_partner` → `regional_partner.id`: Dev DB shows CASCADE, master defaults to RESTRICT
   - Should both be CASCADE to match Dev DB? (Makes sense: if user or regional partner is deleted, the relationship should be deleted)

3. **Indexes:**
   - Dev DB has explicit indexes on `user` and `regional_partner`
   - Laravel auto-creates indexes for FK columns, so this is likely already handled

4. **Nullable Fields:**
   - All columns are NOT NULL ✅ (correct for junction table)

## Decisions ✅

- [x] **FK `user` → CASCADE on delete** ✅
- [x] **FK `regional_partner` → CASCADE on delete** ✅

## Implementation

Updated master migration:
- Changed FK delete rule for `user` from RESTRICT (default) to `onDelete('cascade')` to match Dev DB
- Changed FK delete rule for `regional_partner` from RESTRICT (default) to `onDelete('cascade')` to match Dev DB

