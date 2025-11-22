# Table Review #16: activity_group

## Status: ✅ Ready to Complete (Migration Updated)

## Comparison: Dev Database vs Master Migration

### Columns

| Column | Dev DB | Migration | Status |
|--------|--------|-----------|--------|
| `id` | `int(10) unsigned` NOT NULL | `unsignedInteger` | ✅ Match |
| `activity_type_detail` | `int(10) unsigned` NOT NULL | `unsignedInteger('activity_type_detail')` | ✅ Match |
| `plan` | `int(10) unsigned` NOT NULL | `unsignedInteger('plan')` | ✅ Match |

### Foreign Keys

| FK Column | References | Dev DB Delete Rule | Migration | Status |
|-----------|------------|-------------------|-----------|--------|
| `activity_type_detail` | `m_activity_type_detail.id` | **MISSING** | `->onDelete('cascade')` | ✅ Match | Added CASCADE |
| `plan` | `plan.id` | CASCADE | `->onDelete('cascade')` | ✅ Match |

## Issues Found

1. ✅ **FK `activity_type_detail`**: Added explicit CASCADE

## Decisions Made

1. ✅ **FK `activity_type_detail` delete rule**: CASCADE (explicit)

**Note**: Dev DB doesn't show this FK. Migration will add it with CASCADE on next run.

