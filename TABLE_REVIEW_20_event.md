# Table Review #20: event

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `name` (varchar(100), nullable) ✅
- `slug` (varchar(255), nullable) ✅
- `event_explore` (smallint(6) unsigned, nullable) ✅
- `event_challenge` (smallint(6) unsigned, nullable) ✅
- `contao_id_explore` (int(11), nullable) ⚠️ **Missing in master migration**
- `contao_id_challenge` (int(11), nullable) ⚠️ **Missing in master migration**
- `regional_partner` (int(10) unsigned, NOT NULL, FK) ⚠️ **Dev DB: NOT NULL, Master: nullable**
- `level` (int(10) unsigned, NOT NULL, FK) ✅
- `season` (int(10) unsigned, NOT NULL, FK) ✅
- `date` (date, NOT NULL) ⚠️ **Dev DB: NOT NULL, Master: nullable**
- `days` (tinyint(4) unsigned, NOT NULL) ⚠️ **Dev DB: NOT NULL, Master: nullable**
- `link` (varchar(255), nullable) ✅
- `qrcode` (longtext, nullable) ✅
- `wifi_ssid` (varchar(255), nullable) ✅
- `wifi_password` (longtext, nullable) ✅
- `wifi_instruction` (text, nullable) ✅
- `wifi_qrcode` (longtext, nullable) ✅

### Foreign Keys
- `regional_partner` → `regional_partner.id`: RESTRICT on update, **NO ACTION on delete** ⚠️ **Dev DB: NO ACTION, Master: nullOnDelete()**
- `level` → `m_level.id`: (no explicit rule in Dev DB export, but master has FK)
- `season` → `m_season.id`: (no explicit rule in Dev DB export, but master has FK)

## Master Migration Current State

- Missing: `contao_id_explore`, `contao_id_challenge` (added in later migration `2025_10_16_074532_add_contao_ids_to_event_table.php`)
- `regional_partner`: nullable (Dev DB: NOT NULL)
- `date`: nullable (Dev DB: NOT NULL)
- `days`: nullable (Dev DB: NOT NULL)
- `regional_partner` FK: `nullOnDelete()` (Dev DB: NO ACTION)

## Questions for Review

1. **Missing Columns:**
   - Add `contao_id_explore` and `contao_id_challenge` to master migration? (They exist in Dev DB and are used in `ContaoController.php`)

2. **Nullable Fields:**
   - `regional_partner`: Dev DB is NOT NULL, master is nullable. Which is correct?
   - `date`: Dev DB is NOT NULL, master is nullable. Which is correct?
   - `days`: Dev DB is NOT NULL, master is nullable. Which is correct?

3. **Foreign Key Delete Rules:**
   - `regional_partner`: Dev DB shows NO ACTION, master uses `nullOnDelete()`. Which is correct?
   - `level` and `season`: Should these have explicit delete rules? (Currently no explicit rule in master)

4. **Data Types:**
   - `contao_id_explore` and `contao_id_challenge`: Currently `int(11)` in Dev DB. Should be `integer()` or `unsignedInteger()`?
   - All other types look correct per standards.

## Usage
- Core table referenced by many other tables (plan, team, room, slideshow, etc.)
- Used in `ContaoController.php` for tournament integration
- `contao_id_explore` and `contao_id_challenge` are used to link to external Contao system

## Decisions ✅

- [x] **Add `contao_id_explore` and `contao_id_challenge` to master migration** ✅
- [x] **Nullable status: All three (`regional_partner`, `date`, `days`) are NOT NULL** ✅
- [x] **FK delete rules: All three (`regional_partner`, `level`, `season`) are RESTRICT** ✅ (Updated from CASCADE)
- [x] **Data type for contao_id columns: `unsignedInteger`** ✅

## Implementation

Updated master migration:
- Added `contao_id_explore` and `contao_id_challenge` as `unsignedInteger()->nullable()`
- Changed `regional_partner`, `date`, and `days` to NOT NULL (removed `->nullable()`)
- Changed all three FK delete rules to `onDelete('restrict')`:
  - `regional_partner` → `regional_partner.id` (was `nullOnDelete()`, now RESTRICT)
  - `level` → `m_level.id` (now explicit RESTRICT)
  - `season` → `m_season.id` (now explicit RESTRICT)

