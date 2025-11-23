# Event Cascade Delete - Simple Dependency Graph (Master Migration Only)

## Visual Graph

```
event (DELETED)
│
├─► contao_public_rounds [CASCADE] ──► DELETED (line 281)
│
├─► slideshow [CASCADE] ──► DELETED (line 295)
│   │
│   └─► slide [CASCADE] ──► DELETED (line 314)
│
├─► publication [CASCADE] ──► DELETED (line 328)
│
├─► user.selection_event [SET NULL] ──► (set to NULL, line 351)
│
├─► room [CASCADE] ──► DELETED (line 392)
│   │
│   └─► room_type_room.room [CASCADE] ──► DELETED (line 409)
│
├─► room_type_room [CASCADE] ──► DELETED (line 410)
│
├─► team [CASCADE] ──► DELETED (line 425)
│   │
│   └─► team_plan.team [CASCADE] ──► DELETED (line 503)
│
├─► plan [CASCADE] ──► DELETED (line 440)
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
├─► s_one_link_access [CASCADE] ──► DELETED (line 489)
│
├─► event_logo [CASCADE] ──► DELETED (line 621)
│
└─► table_event [CASCADE] ──► DELETED (line 634)
```

## Summary Table

| Level | Table | FK Column | References | Line | Delete Rule | Status |
|-------|-------|-----------|------------|------|-------------|--------|
| **1** | `contao_public_rounds` | `event_id` | `event.id` | 281 | CASCADE ✅ | ✅ Deleted |
| **1** | `slideshow` | `event` | `event.id` | 295 | CASCADE ✅ | ✅ Deleted |
| **1** | `publication` | `event` | `event.id` | 328 | CASCADE ✅ | ✅ Deleted |
| **1** | `user` | `selection_event` | `event.id` | 351 | SET NULL ✅ | ✅ Set to NULL |
| **1** | `room` | `event` | `event.id` | 392 | CASCADE ✅ | ✅ Deleted |
| **1** | `room_type_room` | `event` | `event.id` | 410 | CASCADE ✅ | ✅ Deleted |
| **1** | `team` | `event` | `event.id` | 425 | CASCADE ✅ | ✅ Deleted |
| **1** | `plan` | `event` | `event.id` | 440 | CASCADE ✅ | ✅ Deleted |
| **1** | `s_one_link_access` | `event` | `event.id` | 489 | CASCADE ✅ | ✅ Deleted |
| **1** | `event_logo` | `event` | `event.id` | 621 | CASCADE ✅ | ✅ Deleted |
| **1** | `table_event` | `event` | `event.id` | 634 | CASCADE ✅ | ✅ Deleted |
| **2** | `slide` | `slideshow_id` | `slideshow.id` | 314 | CASCADE ✅ | ✅ Deleted |
| **2** | `room_type_room` | `room` | `room.id` | 409 | CASCADE ✅ | ✅ Deleted |
| **2** | `team_plan` | `team` | `team.id` | 503 | CASCADE ✅ | ✅ Deleted |
| **2** | `s_generator` | `plan` | `plan.id` | 454 | CASCADE ✅ | ✅ Deleted |
| **2** | `team_plan` | `plan` | `plan.id` | 504 | CASCADE ✅ | ✅ Deleted |
| **2** | `plan_param_value` | `plan` | `plan.id` | 518 | CASCADE ✅ | ✅ Deleted |
| **2** | `match` | `plan` | `plan.id` | 535 | CASCADE ✅ | ✅ Deleted |
| **2** | `extra_block` | `plan` | `plan.id` | 557 | CASCADE ✅ | ✅ Deleted |
| **2** | `activity_group` | `plan` | `plan.id` | 571 | CASCADE ✅ | ✅ Deleted |
| **2** | `q_plan` | `plan` | `plan.id` | 673 | CASCADE ✅ | ✅ Deleted |
| **3** | `activity` | `activity_group` | `activity_group.id` | 592 | CASCADE ✅ | ✅ Deleted |
| **3** | `q_plan_team` | `q_plan` | `q_plan.id` | 705 | CASCADE ✅ | ✅ Deleted |

## Verification Results

### ✅ All Cascade Paths Verified:

1. ✅ **event → contao_public_rounds** - Direct CASCADE
2. ✅ **event → slideshow → slide** - Multi-level CASCADE
3. ✅ **event → publication** - Direct CASCADE
4. ✅ **event → user.selection_event** - SET NULL (doesn't block)
5. ✅ **event → room → room_type_room** - Multi-level CASCADE (also direct)
6. ✅ **event → team → team_plan** - Multi-level CASCADE
7. ✅ **event → plan → (full plan cascade chain)** - Multi-level CASCADE
8. ✅ **event → s_one_link_access** - Direct CASCADE
9. ✅ **event → event_logo** - Direct CASCADE
10. ✅ **event → table_event** - Direct CASCADE

## Conclusion

**Status**: ✅ **PERFECT - No Issues Found!**

- ✅ All 10 direct dependencies have CASCADE (except `user.selection_event` which appropriately uses SET NULL)
- ✅ All multi-level dependencies have CASCADE
- ✅ The only SET NULL relationship doesn't block deletion
- ✅ No missing delete rules
- ✅ No blocking issues

**Result**: When an event is deleted, all related data is properly cascaded through all levels, including the entire plan cascade chain. The cascade delete chain is complete and working correctly! 🎉

