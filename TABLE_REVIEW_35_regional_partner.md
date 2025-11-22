# Table Review #35: regional_partner

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `name` (varchar(100), NOT NULL) ✅
- `region` (varchar(50), NOT NULL) ⚠️ **Dev DB: NOT NULL, varchar(50), Master: nullable, varchar(100)**
- `dolibarr_id` (varchar(10), nullable) ⚠️ **Dev DB: varchar(10), Master: integer()**

### Indexes
- No indexes (no FKs, no unique constraints)

### Foreign Keys
- No foreign keys (this is a reference/master table)

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `name`: `string('name', 100)` ✅
- `region`: `string('region', 100)->nullable()` ⚠️ **Dev DB: NOT NULL, varchar(50)**
- `dolibarr_id`: `integer('dolibarr_id')->nullable()` ⚠️ **Dev DB: varchar(10), nullable**

## Usage
- Reference/master table for regional partners
- Referenced by many tables: `event`, `logo`, `user` (via `user_regional_partner`), `user` (selection_regional_partner)
- Used in `RegionalPartner` model with relationships to `Event` and `Logo`
- Used in `UserRegionalPartnerController` for managing user-regional partner relations

## Questions for Review

1. **Nullable `region`:**
   - Dev DB: NOT NULL
   - Master: nullable
   - **Which is correct?** (Dev DB shows NOT NULL, so it should be required)

2. **Column Length for `region`:**
   - Dev DB: varchar(50)
   - Master: varchar(100)
   - **Which is correct?** (Dev DB shows 50)

3. **Data Type for `dolibarr_id`:**
   - Dev DB: varchar(10)
   - Master: integer()
   - **Which is correct?** (Dev DB shows varchar, suggesting it might contain non-numeric values or leading zeros)

## Decisions ✅

- [x] **`region`: NOT NULL** ✅
- [x] **`region` length: Keep as is (100)** ✅
- [x] **`dolibarr_id`: Keep as is (integer)** ✅

## Implementation

Updated master migration:
- Changed `region` from nullable to NOT NULL (removed `->nullable()`)
- Kept `region` length at 100 (as in master)
- Kept `dolibarr_id` as `integer()` (as in master)

