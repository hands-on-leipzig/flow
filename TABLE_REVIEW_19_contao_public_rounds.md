# Table Review #19: contao_public_rounds

## Current Schema (Dev DB)

### Columns
- `event_id` (int(10) unsigned, NOT NULL, PRIMARY KEY) → FK to `event.id`
- `vr1` (tinyint(1), NOT NULL, default: 1)
- `vr2` (tinyint(1), NOT NULL, default: 0)
- `vr3` (tinyint(1), NOT NULL, default: 0)
- `vf` (tinyint(1), NOT NULL, default: 0)
- `hf` (tinyint(1), NOT NULL, default: 0)

### Foreign Keys
- `event_id` → `event.id`: RESTRICT on update, **CASCADE on delete**

## Usage
- Used in `ContaoController.php` to control which tournament rounds are publicly visible
- Flags control visibility of: VR1, VR2, VR3, VF, HF rounds

## Questions for Review

1. **Data Types:**
   - `event_id`: Currently `int(10) unsigned`. Should be `unsignedInteger` (length 10) per standards?
   - Boolean columns (`vr1`, `vr2`, etc.): `tinyint(1)` is correct for booleans?

2. **Foreign Key:**
   - Delete rule: Currently CASCADE. Is this correct? (If event is deleted, these settings should be deleted too - makes sense)

3. **Master Migration:**
   - Table is NOT currently in master migration. Should it be added?

4. **Nullable:**
   - All columns are NOT NULL. Is this correct?

## Decisions ✅

- [x] **Add to master migration** ✅
- [x] **FK delete rule: CASCADE** ✅
- [x] **Data types adjusted to standards** ✅
  - `event_id`: `unsignedInteger` (length 10 is default)
  - Boolean columns: `boolean()` (Laravel maps to `tinyint(1)`)

## Implementation

Added to master migration after `event` table:
- `event_id`: `unsignedInteger` (primary key)
- Boolean flags: `boolean()` with appropriate defaults
- FK: `event_id` → `event.id` with `onDelete('cascade')`

