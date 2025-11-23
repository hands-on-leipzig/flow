# Event Table Foreign Keys Analysis (Outgoing)

## Requirement
Foreign keys IN the `event` table (pointing to higher-level/master tables) must have a **RESTRICT** condition to prevent deletion of master data while events reference them.

## Current Status

The `event` table has **3 foreign keys** pointing to other tables:

| # | Column | References | Line | Current Rule | Required Rule | Status |
|---|--------|------------|------|--------------|---------------|--------|
| 1 | `regional_partner` | `regional_partner.id` | 266 | RESTRICT ✅ | RESTRICT | ✅ **CORRECT** |
| 2 | `level` | `m_level.id` | 267 | RESTRICT ✅ | RESTRICT | ✅ **CORRECT** |
| 3 | `season` | `m_season.id` | 268 | RESTRICT ✅ | RESTRICT | ✅ **CORRECT** |

## Verification

**File**: `backend/database/migrations/2025_01_01_000000_create_master_tables.php`

**Lines 266-268**:
```php
$table->foreign('regional_partner')->references('id')->on('regional_partner')->onDelete('restrict');
$table->foreign('level')->references('id')->on('m_level')->onDelete('restrict');
$table->foreign('season')->references('id')->on('m_season')->onDelete('restrict');
```

## Summary

✅ **All 3 foreign keys in the `event` table already have RESTRICT!**

## Impact

With RESTRICT in place:
- ✅ **Cannot delete `regional_partner`** if any events reference it
- ✅ **Cannot delete `m_level`** if any events reference it
- ✅ **Cannot delete `m_season`** if any events reference it

This provides **data integrity protection** - prevents accidental deletion of master data that is still in use by events.

## Conclusion

**Status**: ✅ **PERFECT - No Changes Needed!**

All foreign keys in the `event` table correctly use RESTRICT to protect master data from being deleted while referenced by events.

