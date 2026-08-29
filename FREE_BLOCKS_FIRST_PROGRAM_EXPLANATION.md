# Extra blocks: `first_program` and types

## Overview

`extra_block.first_program` selects **one program** or **übergreifend** (joint). It controls:

1. Which activity type code is used when materializing (`*_free_block` / `*_slot_block`)
2. Which program column (or the agnostic column) shows the block in schedules
3. For slots: which teams appear in the assignment table

There is **no multi-select** — not a set of programs.

## Values

| Value | Meaning | Activity types (examples) |
|-------|---------|---------------------------|
| `0` | **übergreifend** (joint) | `g_free_block`, `g_slot_block` — program-agnostic column |
| `2` | Explore | `e_free_block`, `e_slot_block` |
| `3` | Challenge | `c_free_block`, `c_slot_block` |
| `8` | Future 8+ | `f8_free_block`, `f8_slot_block` |

`NULL` is migrated to `0` (übergreifend). Discover (`1`) is allowed in API but uncommon.

## Types (`extra_block.type`)

| Type | Scheduling |
|------|------------|
| `free` | Fixed `start` / `end` on the block; one parallel activity |
| `slot` | Per-team `start` in `slot_block_team`; duration on the block |

The old **inserted** block type and insert-point catalog were removed (2026). Pauses in the generator are duration-only on time cursors, not extra-block rows.

## Materialization

**Free** — [FreeBlockGenerator.php](backend/app/Core/FreeBlockGenerator.php):

- Skips inactive blocks or programs not on (`ProgramPresence`)
- übergreifend: one `g_free_block` when any program is on
- Specific program: that program's `*_free_block` when that program is on

**Slot** — [SlotBlockPlanSyncService.php](backend/app/Services/SlotBlockPlanSyncService.php):

- Reads `slot_block_team` rows with a start time
- übergreifend: one `g_slot_block` group (all on-program teams)
- Specific program: one `*_slot_block` group for that program's teams

After full or lite generation, free blocks are inserted then slot activities are synced.

## Visibility

Role visibility is **not** per block. It follows `m_visibility` on the materialized activity type (`g/e/c/f8_*_block`). Per-block `active` only controls whether the block is materialized.

## Code references

- Enum: `backend/app/Enums/FirstProgram.php`
- Type codes: `backend/app/Support/ExtraBlockActivityTypeCode.php`
- API: `backend/app/Http/Controllers/Api/ExtraBlockController.php`
- Free UI: `frontend/src/components/molecules/FreeBlocks.vue`
- Slot UI: `frontend/src/components/Slots.vue`
