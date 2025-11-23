# Event Cascade Delete Analysis (Master Migration Only)

## Question: What happens when an event is deleted?

Analysis based **ONLY** on `backend/database/migrations/2025_01_01_000000_create_master_tables.php`

## Cascade Delete Dependency Graph

```
event (DELETED)
│
├─► contao_public_rounds [CASCADE] ──► DELETED
│   └─ Line 281: foreign('event_id')->onDelete('cascade')
│
├─► slideshow [CASCADE] ──► DELETED
│   └─ Line 295: foreign('event')->onDelete('cascade')
│   │
│   └─► slide [CASCADE] ──► DELETED
│       └─ Line 314: foreign('slideshow_id')->onDelete('cascade')
│
├─► publication [CASCADE] ──► DELETED
│   └─ Line 328: foreign('event')->onDelete('cascade')
│
├─► user.selection_event [SET NULL] ──► (set to NULL, not deleted)
│   └─ Line 351: foreign('selection_event')->onDelete('set null')
│
├─► room [CASCADE] ──► DELETED
│   └─ Line 392: foreign('event')->onDelete('cascade')
│   │
│   └─► room_type_room.room [CASCADE] ──► DELETED
│       └─ Line 409: foreign('room')->onDelete('cascade')
│
├─► room_type_room [CASCADE] ──► DELETED
│   └─ Line 410: foreign('event')->onDelete('cascade')
│
├─► team [CASCADE] ──► DELETED
│   └─ Line 425: foreign('event')->onDelete('cascade')
│   │
│   └─► team_plan.team [CASCADE] ──► DELETED
│       └─ Line 503: foreign('team')->onDelete('cascade')
│
├─► plan [CASCADE] ──► DELETED
│   └─ Line 440: foreign('event')->onDelete('cascade')
│   │
│   └─► (Full plan cascade chain - see PLAN_CASCADE_DELETE_ANALYSIS.md)
│       ├─► s_generator
│       ├─► team_plan
│       ├─► plan_param_value
│       ├─► match
│       ├─► extra_block
│       ├─► activity_group → activity
│       └─► q_plan → q_plan_team
│
├─► s_one_link_access [CASCADE] ──► DELETED
│   └─ Line 489: foreign('event')->onDelete('cascade')
│
├─► event_logo [CASCADE] ──► DELETED
│   └─ Line 621: foreign('event')->onDelete('cascade')
│
└─► table_event [CASCADE] ──► DELETED
    └─ Line 634: foreign('event')->onDelete('cascade')
```

## Detailed Analysis

### Level 1: Direct Dependencies (FK to event.id)

| Table | Column | Line | Delete Rule | Status |
|-------|--------|------|-------------|--------|
| `contao_public_rounds` | `event_id` | 281 | CASCADE ✅ | ✅ Will be deleted |
| `slideshow` | `event` | 295 | CASCADE ✅ | ✅ Will be deleted |
| `publication` | `event` | 328 | CASCADE ✅ | ✅ Will be deleted |
| `user` | `selection_event` | 351 | SET NULL ✅ | ✅ Set to NULL (doesn't block) |
| `room` | `event` | 392 | CASCADE ✅ | ✅ Will be deleted |
| `room_type_room` | `event` | 410 | CASCADE ✅ | ✅ Will be deleted |
| `team` | `event` | 425 | CASCADE ✅ | ✅ Will be deleted |
| `plan` | `event` | 440 | CASCADE ✅ | ✅ Will be deleted |
| `s_one_link_access` | `event` | 489 | CASCADE ✅ | ✅ Will be deleted |
| `event_logo` | `event` | 621 | CASCADE ✅ | ✅ Will be deleted |
| `table_event` | `event` | 634 | CASCADE ✅ | ✅ Will be deleted |

### Level 2: Dependencies of Level 1 Tables

#### From `slideshow`:
| Table | Column | Line | Delete Rule | Status |
|-------|--------|------|-------------|--------|
| `slide` | `slideshow_id` | 314 | CASCADE ✅ | ✅ Will be deleted |

#### From `room`:
| Table | Column | Line | Delete Rule | Status |
|-------|--------|------|-------------|--------|
| `room_type_room` | `room` | 409 | CASCADE ✅ | ✅ Will be deleted |

#### From `team`:
| Table | Column | Line | Delete Rule | Status |
|-------|--------|------|-------------|--------|
| `team_plan` | `team` | 503 | CASCADE ✅ | ✅ Will be deleted |

#### From `plan`:
| Table | Column | Line | Delete Rule | Status |
|-------|--------|------|-------------|--------|
| (See PLAN_CASCADE_DELETE_ANALYSIS.md for full chain) | | | | |
| `s_generator` | `plan` | 454 | CASCADE ✅ | ✅ Will be deleted |
| `team_plan` | `plan` | 504 | CASCADE ✅ | ✅ Will be deleted |
| `plan_param_value` | `plan` | 518 | CASCADE ✅ | ✅ Will be deleted |
| `match` | `plan` | 535 | CASCADE ✅ | ✅ Will be deleted |
| `extra_block` | `plan` | 557 | CASCADE ✅ | ✅ Will be deleted |
| `activity_group` | `plan` | 571 | CASCADE ✅ | ✅ Will be deleted |
| `q_plan` | `plan` | 673 | CASCADE ✅ | ✅ Will be deleted |

### Level 3: Dependencies of Level 2 Tables

#### From `activity_group`:
| Table | Column | Line | Delete Rule | Status |
|-------|--------|------|-------------|--------|
| `activity` | `activity_group` | 592 | CASCADE ✅ | ✅ Will be deleted |

#### From `q_plan`:
| Table | Column | Line | Delete Rule | Status |
|-------|--------|------|-------------|--------|
| `q_plan_team` | `q_plan` | 705 | CASCADE ✅ | ✅ Will be deleted |

## Complete Cascade Chain Verification

### ✅ Verified Cascade Paths:

1. **event → contao_public_rounds** ✅
   - Direct CASCADE (line 281)

2. **event → slideshow → slide** ✅
   - event → slideshow: CASCADE (line 295)
   - slideshow → slide: CASCADE (line 314)

3. **event → publication** ✅
   - Direct CASCADE (line 328)

4. **event → user.selection_event** ✅
   - Direct SET NULL (line 351) - doesn't block deletion

5. **event → room → room_type_room** ✅
   - event → room: CASCADE (line 392)
   - room → room_type_room: CASCADE (line 409)
   - event → room_type_room: CASCADE (line 410) - also direct

6. **event → team → team_plan** ✅
   - event → team: CASCADE (line 425)
   - team → team_plan: CASCADE (line 503)

7. **event → plan → (full plan cascade chain)** ✅
   - event → plan: CASCADE (line 440)
   - plan → s_generator: CASCADE (line 454)
   - plan → team_plan: CASCADE (line 504)
   - plan → plan_param_value: CASCADE (line 518)
   - plan → match: CASCADE (line 535)
   - plan → extra_block: CASCADE (line 557)
   - plan → activity_group: CASCADE (line 571)
   - plan → q_plan: CASCADE (line 673)
   - activity_group → activity: CASCADE (line 592)
   - q_plan → q_plan_team: CASCADE (line 705)

8. **event → s_one_link_access** ✅
   - Direct CASCADE (line 489)

9. **event → event_logo** ✅
   - Direct CASCADE (line 621)

10. **event → table_event** ✅
    - Direct CASCADE (line 634)

## Summary

### ✅ What WILL be deleted when event is deleted:

**Level 1 (Direct):**
- ✅ `contao_public_rounds` (line 281)
- ✅ `slideshow` (line 295)
- ✅ `publication` (line 328)
- ✅ `room` (line 392)
- ✅ `room_type_room` (line 410)
- ✅ `team` (line 425)
- ✅ `plan` (line 440)
- ✅ `s_one_link_access` (line 489)
- ✅ `event_logo` (line 621)
- ✅ `table_event` (line 634)

**Level 2 (Via CASCADE):**
- ✅ `slide` (via slideshow CASCADE, line 314)
- ✅ `team_plan` (via team CASCADE, line 503)
- ✅ All plan-related tables (via plan CASCADE - see plan analysis)

**Level 3 (Via CASCADE):**
- ✅ `activity` (via activity_group CASCADE, line 592)
- ✅ `q_plan_team` (via q_plan CASCADE, line 705)

**Level 1 (Via SET NULL):**
- ✅ `user.selection_event` (set to NULL, line 351) - doesn't block deletion

### Total Tables Affected:
- **10 direct deletions** (CASCADE)
- **1 field set to NULL** (SET NULL, doesn't block)
- **Multiple cascaded deletions** through plan chain (7+ tables)
- **Additional cascaded deletions** through slideshow, room, team chains

## Conclusion

**Overall**: ✅ **Cascade deletion works perfectly!**

**Status**: All foreign keys have proper delete rules:
- ✅ All direct dependencies on `event` have CASCADE (except `user.selection_event` which uses SET NULL appropriately)
- ✅ All multi-level dependencies have CASCADE
- ✅ The only SET NULL relationship (`user.selection_event`) doesn't block deletion
- ✅ No blocking issues found

**Result**: When an event is deleted, all related data is properly cascaded through all levels, including the entire plan cascade chain. No fixes needed! 🎉
