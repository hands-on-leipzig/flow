# Table Review #14: m_visibility

## Status: ✅ Ready to Complete (Migration Updated)

## Comparison: Dev Database vs Master Migration

### Columns

| Column | Dev DB | Master Migration | Status |
|--------|--------|------------------|--------|
| `id` | `int(10) unsigned` NOT NULL | `unsignedInteger` | ✅ Match |
| `activity_type_detail` | `int(10) unsigned` NULLABLE | `unsignedInteger('activity_type_detail')->nullable()` | ✅ Match |
| `role` | `int(10) unsigned` NULLABLE | `unsignedInteger('role')->nullable()` | ✅ Match |

### Foreign Keys

| FK Column | References | Dev DB Delete Rule | Migration | Status |
|-----------|------------|-------------------|-----------|--------|
| `activity_type_detail` | `m_activity_type_detail.id` | RESTRICT → CASCADE | `->onDelete('cascade')` | ✅ Match |
| `role` | `m_role.id` | RESTRICT → CASCADE | `->onDelete('cascade')` | ✅ Match |

## Issues Found

1. ✅ **FK `activity_type_detail`**: Changed to CASCADE
2. ✅ **FK `role`**: Changed to CASCADE

## Decisions Made

1. ✅ **FK `activity_type_detail` delete rule**: CASCADE (explicit)
2. ✅ **FK `role` delete rule**: CASCADE (explicit)

**Note**: Dev DB currently has RESTRICT. Migration will change to CASCADE on next run.

