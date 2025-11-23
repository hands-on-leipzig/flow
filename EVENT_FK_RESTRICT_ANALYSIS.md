# Event Foreign Key RESTRICT Analysis

## Requirement
Any relationship coming INTO `event` (i.e., foreign keys that reference `event.id`) must have a **RESTRICT** condition.

## Current Status

Found **11 foreign keys** that reference `event.id`:

| # | Table | Column | Line | Current Rule | Required Rule | Status |
|---|-------|--------|------|--------------|---------------|--------|
| 1 | `contao_public_rounds` | `event_id` | 281 | CASCADE ❌ | RESTRICT | ❌ **NEEDS FIX** |
| 2 | `slideshow` | `event` | 295 | CASCADE ❌ | RESTRICT | ❌ **NEEDS FIX** |
| 3 | `publication` | `event` | 328 | CASCADE ❌ | RESTRICT | ❌ **NEEDS FIX** |
| 4 | `user` | `selection_event` | 351 | SET NULL ❌ | RESTRICT | ❌ **NEEDS FIX** |
| 5 | `room` | `event` | 392 | CASCADE ❌ | RESTRICT | ❌ **NEEDS FIX** |
| 6 | `room_type_room` | `event` | 410 | CASCADE ❌ | RESTRICT | ❌ **NEEDS FIX** |
| 7 | `team` | `event` | 425 | CASCADE ❌ | RESTRICT | ❌ **NEEDS FIX** |
| 8 | `plan` | `event` | 440 | CASCADE ❌ | RESTRICT | ❌ **NEEDS FIX** |
| 9 | `s_one_link_access` | `event` | 489 | CASCADE ❌ | RESTRICT | ❌ **NEEDS FIX** |
| 10 | `event_logo` | `event` | 621 | CASCADE ❌ | RESTRICT | ❌ **NEEDS FIX** |
| 11 | `table_event` | `event` | 634 | CASCADE ❌ | RESTRICT | ❌ **NEEDS FIX** |

## Summary

**All 11 foreign keys** that reference `event.id` currently have **CASCADE** or **SET NULL** rules, but they should all be **RESTRICT**.

## Impact

If these are changed to RESTRICT:
- **Event deletion will be BLOCKED** if any of these tables have records referencing the event
- This provides **data protection** - prevents accidental deletion of events with related data
- Manual cleanup would be required before deleting an event

## Required Changes

All 11 foreign keys need to be updated from:
- `onDelete('cascade')` → `onDelete('restrict')`
- `onDelete('set null')` → `onDelete('restrict')`

## Files to Update

**File**: `backend/database/migrations/2025_01_01_000000_create_master_tables.php`

**Lines to change**:
1. Line 281: `contao_public_rounds.event_id`
2. Line 295: `slideshow.event`
3. Line 328: `publication.event`
4. Line 351: `user.selection_event`
5. Line 392: `room.event`
6. Line 410: `room_type_room.event`
7. Line 425: `team.event`
8. Line 440: `plan.event`
9. Line 489: `s_one_link_access.event`
10. Line 621: `event_logo.event`
11. Line 634: `table_event.event`

