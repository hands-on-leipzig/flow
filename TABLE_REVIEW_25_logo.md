# Table Review #25: logo

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `regional_partner` (int(10) unsigned, NOT NULL, FK) ⚠️ **Dev DB: NOT NULL, Master: nullable**
- `path` (varchar(255), NOT NULL) ✅
- `title` (varchar(255), nullable) ⚠️ **Dev DB: varchar(255), Master: varchar(100)**
- `link` (varchar(255), nullable) ⚠️ **Dev DB: varchar(255), Master: varchar(500)**

### Indexes
- `regional_partner` (index on `regional_partner`) ✅ (created by FK)

### Foreign Keys
- `regional_partner` → `regional_partner.id`: RESTRICT on update, RESTRICT on delete ⚠️ **Master: no explicit rule**

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `title`: `string('title', 100)->nullable()` ⚠️ **Dev DB: varchar(255)**
- `link`: `string('link', 500)->nullable()` ⚠️ **Dev DB: varchar(255)**
- `path`: `string('path', 255)` ✅
- `event`: `unsignedInteger('event')->nullable()` ⚠️ **NOT in Dev DB!**
- `regional_partner`: `unsignedInteger('regional_partner')->nullable()` ⚠️ **Dev DB: NOT NULL**
- FK: `event` → `event.id` ⚠️ **NOT in Dev DB!**
- FK: `regional_partner` → `regional_partner.id` (no explicit rule) ⚠️ **Dev DB: RESTRICT**

## Usage
- Used for storing logo files associated with regional partners
- Linked to events through `event_logo` junction table (many-to-many)
- Used in `LogoController` and `LogoPolicy` for CRUD operations
- Model shows relationship to `regional_partner` and `events` (via `event_logo`)

## Questions for Review

1. **Extra Column in Master:**
   - Master migration has `event` column and FK, but Dev DB does NOT have this column
   - The relationship to events is handled through `event_logo` junction table
   - **Should `event` column and FK be removed from master migration?**

2. **Column Lengths:**
   - `title`: Master has 100, Dev DB has 255. Which is correct?
   - `link`: Master has 500, Dev DB has 255. Which is correct?

3. **Nullable `regional_partner`:**
   - Dev DB: NOT NULL
   - Master: nullable
   - **Which is correct?** (Model fillable includes it, suggesting it's required)

4. **FK Delete Rule:**
   - Dev DB: RESTRICT
   - Master: No explicit rule (defaults to RESTRICT)
   - **Should be made explicit?**

## Decisions ✅

- [x] **Remove `event` column and FK from master migration** ✅
- [x] **Keep `title` and `link` lengths as is** ✅ (Master: 100, 500)
- [x] **Keep nullable status as is** ✅ (Master: nullable for title/link)
- [x] **`regional_partner` FK: CASCADE, NOT NULL** ✅

## Implementation

Updated master migration:
- Removed `event` column and FK (relationship handled via `event_logo` junction table)
- Kept `title` length at 100 and `link` length at 500 (as in master)
- Changed `regional_partner` to NOT NULL (removed `->nullable()`)
- Changed FK delete rule for `regional_partner` to `onDelete('cascade')`

