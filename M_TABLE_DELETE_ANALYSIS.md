# M_Table Delete Analysis - Foreign Keys from Non-M_ Tables to M_ Tables

## Purpose
This document lists all foreign key relationships from **non-m_ tables** to **m_ tables** to review what happens when an entry in an m_table is attempted to be deleted.

## Foreign Key Relationships

| # | Source Table | Source Column | Target M_ Table | Target Column | Line | Delete Rule | Impact When M_ Entry Deleted |
|---|--------------|---------------|-----------------|---------------|------|-------------|------------------------------|
| 1 | `event` | `level` | `m_level` | `id` | 267 | RESTRICT ✅ | ❌ **BLOCKS** deletion of `m_level` entry if any events reference it |
| 2 | `event` | `season` | `m_season` | `id` | 268 | RESTRICT ✅ | ❌ **BLOCKS** deletion of `m_season` entry if any events reference it |
| 3 | `news_user` | `news_id` | `m_news` | `id` | 365 | CASCADE ⚠️ | ✅ **DELETES** `news_user` entries when `m_news` entry is deleted |
| 4 | `room_type_room` | `room_type` | `m_room_type` | `id` | 408 | CASCADE ⚠️ | ✅ **DELETES** `room_type_room` entries when `m_room_type` entry is deleted |
| 5 | `team` | `first_program` | `m_first_program` | `id` | 426 | RESTRICT ✅ | ❌ **BLOCKS** deletion of `m_first_program` entry if any teams reference it |
| 6 | `plan_param_value` | `parameter` | `m_parameter` | `id` | 519 | CASCADE ⚠️ | ✅ **DELETES** `plan_param_value` entries when `m_parameter` entry is deleted |
| 7 | `extra_block` | `insert_point` | `m_insert_point` | `id` | 558 | CASCADE ⚠️ | ✅ **DELETES** `extra_block` entries when `m_insert_point` entry is deleted |
| 8 | `activity_group` | `activity_type_detail` | `m_activity_type_detail` | `id` | 570 | CASCADE ⚠️ | ✅ **DELETES** `activity_group` entries when `m_activity_type_detail` entry is deleted |
| 9 | `activity` | `room_type` | `m_room_type` | `id` | 593 | CASCADE ⚠️ | ✅ **DELETES** `activity` entries when `m_room_type` entry is deleted |
| 10 | `activity` | `activity_type_detail` | `m_activity_type_detail` | `id` | 594 | CASCADE ⚠️ | ✅ **DELETES** `activity` entries when `m_activity_type_detail` entry is deleted |

## Summary by Delete Rule

### RESTRICT (3 relationships) - Blocks Deletion ✅
- **#1**: `event.level` → `m_level.id` - Prevents deletion of level if events use it
- **#2**: `event.season` → `m_season.id` - Prevents deletion of season if events use it
- **#5**: `team.first_program` → `m_first_program.id` - Prevents deletion of first_program if teams use it

### CASCADE (7 relationships) - Auto-Deletes Dependent Data ⚠️
- **#3**: `news_user.news_id` → `m_news.id` - Deletes news_user entries
- **#4**: `room_type_room.room_type` → `m_room_type.id` - Deletes room_type_room entries
- **#6**: `plan_param_value.parameter` → `m_parameter.id` - Deletes plan_param_value entries
- **#7**: `extra_block.insert_point` → `m_insert_point.id` - Deletes extra_block entries
- **#8**: `activity_group.activity_type_detail` → `m_activity_type_detail.id` - Deletes activity_group entries (which cascades to activity)
- **#9**: `activity.room_type` → `m_room_type.id` - Deletes activity entries
- **#10**: `activity.activity_type_detail` → `m_activity_type_detail.id` - Deletes activity entries

## Detailed Analysis by M_ Table

### `m_level`
- **#1**: `event.level` → RESTRICT ✅
  - **Impact**: Cannot delete `m_level` entry if any events reference it
  - **Status**: ✅ Correct - protects master data

### `m_season`
- **#2**: `event.season` → RESTRICT ✅
  - **Impact**: Cannot delete `m_season` entry if any events reference it
  - **Status**: ✅ Correct - protects master data

### `m_news`
- **#3**: `news_user.news_id` → CASCADE ⚠️
  - **Impact**: Deleting `m_news` entry will delete all `news_user` entries that reference it
  - **Status**: ⚠️ Review needed - is this desired behavior?

### `m_room_type`
- **#4**: `room_type_room.room_type` → CASCADE ⚠️
  - **Impact**: Deleting `m_room_type` entry will delete all `room_type_room` entries that reference it
  - **Status**: ⚠️ Review needed - is this desired behavior?
- **#9**: `activity.room_type` → CASCADE ⚠️
  - **Impact**: Deleting `m_room_type` entry will delete all `activity` entries that reference it
  - **Status**: ⚠️ Review needed - is this desired behavior?

### `m_first_program`
- **#5**: `team.first_program` → RESTRICT ✅
  - **Impact**: Cannot delete `m_first_program` entry if any teams reference it
  - **Status**: ✅ Correct - protects master data

### `m_parameter`
- **#6**: `plan_param_value.parameter` → CASCADE ⚠️
  - **Impact**: Deleting `m_parameter` entry will delete all `plan_param_value` entries that reference it
  - **Status**: ⚠️ Review needed - is this desired behavior?

### `m_insert_point`
- **#7**: `extra_block.insert_point` → CASCADE ⚠️
  - **Impact**: Deleting `m_insert_point` entry will delete all `extra_block` entries that reference it
  - **Status**: ⚠️ Review needed - is this desired behavior?

### `m_activity_type_detail`
- **#8**: `activity_group.activity_type_detail` → CASCADE ⚠️
  - **Impact**: Deleting `m_activity_type_detail` entry will delete all `activity_group` entries (which cascades to `activity` entries)
  - **Status**: ⚠️ Review needed - is this desired behavior?
- **#10**: `activity.activity_type_detail` → CASCADE ⚠️
  - **Impact**: Deleting `m_activity_type_detail` entry will delete all `activity` entries that reference it
  - **Status**: ⚠️ Review needed - is this desired behavior?

## Recommendations

### ✅ RESTRICT Rules (Correct)
- **#1, #2, #5**: These correctly protect master data (`m_level`, `m_season`, `m_first_program`) from being deleted while referenced by events/teams.

### ⚠️ CASCADE Rules (Review Needed)
The following CASCADE rules may need to be changed to RESTRICT depending on business requirements:

- **#3**: `news_user.news_id` → `m_news.id` - Should news deletion cascade to user read records?
- **#4**: `room_type_room.room_type` → `m_room_type.id` - Should room_type deletion cascade to room assignments?
- **#6**: `plan_param_value.parameter` → `m_parameter.id` - Should parameter deletion cascade to plan parameter values?
- **#7**: `extra_block.insert_point` → `m_insert_point.id` - Should insert_point deletion cascade to extra_blocks?
- **#8**: `activity_group.activity_type_detail` → `m_activity_type_detail.id` - Should activity_type_detail deletion cascade to activity_groups (and activities)?
- **#9**: `activity.room_type` → `m_room_type.id` - Should room_type deletion cascade to activities?
- **#10**: `activity.activity_type_detail` → `m_activity_type_detail.id` - Should activity_type_detail deletion cascade to activities?

## Notes

- All RESTRICT rules are appropriate for master data protection.
- CASCADE rules may be intentional for data cleanup, but should be reviewed to ensure they match business requirements.
- Some m_ tables have multiple relationships (e.g., `m_room_type` has 2, `m_activity_type_detail` has 2).

