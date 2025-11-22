# Table Review #15: activity

## Status: ✅ Ready to Complete (Migration Updated)

## Comparison: Dev Database vs Master Migration

### Columns

| Column | Dev DB | Migration | Status | Notes |
|--------|--------|-----------|--------|-------|
| `id` | `int(10) unsigned` NOT NULL | `unsignedInteger` | ✅ Match | |
| `activity_group` | `int(10) unsigned` NOT NULL | `unsignedInteger('activity_group')` | ✅ Match | |
| `start` | `datetime` NOT NULL | `datetime('start')` | ✅ Match | |
| `end` | `datetime` NOT NULL | `datetime('end')` | ✅ Match | |
| `room_type` | `int(10) unsigned` NULLABLE | `unsignedInteger('room_type')->nullable()` | ✅ Match | |
| `jury_lane` | `tinyint(4) unsigned` NULLABLE | `unsignedTinyInteger('jury_lane')->nullable()` | ✅ Match | |
| `jury_team` | `int(10) unsigned` NULLABLE | `unsignedInteger('jury_team')->nullable()` | ✅ Match | |
| `table_1` | `tinyint(4) unsigned` NULLABLE | `unsignedTinyInteger('table_1')->nullable()` | ✅ Match | |
| `table_1_team` | `int(10) unsigned` NULLABLE | `unsignedInteger('table_1_team')->nullable()` | ✅ Match | |
| `table_2` | `tinyint(4) unsigned` NULLABLE | `unsignedTinyInteger('table_2')->nullable()` | ✅ Match | |
| `table_2_team` | `int(10) unsigned` NULLABLE | `unsignedInteger('table_2_team')->nullable()` | ✅ Match | |
| `activity_type_detail` | `int(10) unsigned` NOT NULL | `unsignedInteger('activity_type_detail')` | ✅ Match | |
| `plan_extra_block` | **MISSING** | **REMOVED** | ✅ Match | Removed from migration |
| `extra_block` | `int(10) unsigned` NULLABLE | `unsignedInteger('extra_block')->nullable()` | ✅ Match | |

### Foreign Keys

| FK Column | References | Dev DB Delete Rule | Migration | Status |
|-----------|------------|-------------------|-----------|--------|
| `activity_group` | `activity_group.id` | CASCADE | `->onDelete('cascade')` | ✅ Match |
| `room_type` | `m_room_type.id` | **MISSING** | `->onDelete('cascade')` | ✅ Match | Added CASCADE |
| `activity_type_detail` | `m_activity_type_detail.id` | **MISSING** | `->onDelete('cascade')` | ✅ Match | Added CASCADE |
| `plan_extra_block` | `plan_extra_block.id` | **MISSING** | **REMOVED** | ✅ Match | Removed with column |
| `extra_block` | `extra_block.id` | SET NULL | `->nullOnDelete()` | ✅ Match |

## Issues Found

1. ✅ **Column `plan_extra_block`**: Removed from migration
2. ✅ **FK `room_type`**: Added explicit CASCADE
3. ✅ **FK `activity_type_detail`**: Added explicit CASCADE
4. ✅ **FK `plan_extra_block`**: Removed with column

## Decisions Made

1. ✅ **`plan_extra_block` column**: Removed from migration
2. ✅ **FK `room_type` delete rule**: Added explicit CASCADE
3. ✅ **FK `activity_type_detail` delete rule**: Added explicit CASCADE
4. ✅ **FK `plan_extra_block`**: Removed (column removed)

**Note**: Dev DB doesn't have FKs for `room_type` and `activity_type_detail`. Migration will add them with CASCADE on next run.

