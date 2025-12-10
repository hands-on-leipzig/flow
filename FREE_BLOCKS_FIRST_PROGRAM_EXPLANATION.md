# Free Blocks: `first_program` Field Explanation

## Overview

The `first_program` field in free blocks (`extra_block` table) determines **which FIRST LEGO League program(s) the block applies to**. This controls:
1. **Visibility filtering** - Which blocks are shown based on enabled programs
2. **Plan generation** - How the block is inserted into the generated schedule
3. **Activity type assignment** - Which activity type detail code is used when creating the activity

---

## Possible Values

From `App\Enums\FirstProgram`:

| Value | Enum Constant | Meaning | Description |
|-------|---------------|---------|-------------|
| `0` | `JOINT` | Gemeinsam | **Joint/General** - Applies to BOTH Explore and Challenge participants (all participants see it) |
| `1` | `DISCOVER` | Discover | Discover program (rarely used, treated similarly to Explore) |
| `2` | `EXPLORE` | Explore | **FIRST LEGO League Explore** - Only Explore participants see it |
| `3` | `CHALLENGE` | Challenge | **FIRST LEGO League Challenge** - Only Challenge participants see it |
| `null` | - | None | No program assigned (block not shown) |

---

## UI Toggle Behavior

The UI has two clickable program icons (Explore and Challenge logos). Clicking them cycles through states:

### Clicking Explore Icon (program = 2):
```
Current State  →  Next State
─────────────────────────────
null          →  2 (Explore)
2 (Explore)   →  null
3 (Challenge) →  0 (Joint)
0 (Joint)     →  3 (Challenge)
```

**Visual flow:** No program → Explore only → No program (toggles Explore on/off)

### Clicking Challenge Icon (program = 3):
```
Current State  →  Next State
─────────────────────────────
null          →  3 (Challenge)
3 (Challenge) →  null
2 (Explore)   →  0 (Joint)
0 (Joint)     →  2 (Explore)
```

**Visual flow:** No program → Challenge only → No program (toggles Challenge on/off)

### Getting to Joint (0) State:
- Start with Explore (2), click Challenge icon → becomes Joint (0)
- Start with Challenge (3), click Explore icon → becomes Joint (0)

**Joint state:** Both icons appear active (full opacity), indicating the block applies to all participants.

---

## Visibility Filtering

In `FreeBlocks.vue`, blocks are filtered based on enabled programs:

```typescript
const visibleCustomBlocks = computed(() => {
  return customBlocks.value.filter(block => {
    // If both programs disabled, show all blocks
    if (props.showExplore === false && props.showChallenge === false) return true
    
    // Hide Explore/Joint blocks if Explore is disabled
    if (props.showExplore === false && (block.first_program === 2 || block.first_program === 0)) return false
    
    // Hide Challenge/Joint blocks if Challenge is disabled
    if (props.showChallenge === false && (block.first_program === 3 || block.first_program === 0)) return false
    
    return true
  })
})
```

**Rules:**
- `first_program = 2` (Explore): Hidden if `showExplore === false`
- `first_program = 3` (Challenge): Hidden if `showChallenge === false`
- `first_program = 0` (Joint): Hidden if BOTH Explore AND Challenge are disabled
- `first_program = null`: Always hidden (block has no program assignment)

---

## Plan Generation Behavior

In `FreeBlockGenerator.php`, blocks are processed during plan generation:

### 1. Program Check
```php
// Skip Explore blocks if Explore is disabled (e_mode = 0)
if ($blockProgram === FirstProgram::EXPLORE->value && $eMode == 0) {
    continue; // Block is skipped
}

// Skip Challenge blocks if Challenge is disabled (c_mode = 0)
if ($blockProgram === FirstProgram::CHALLENGE->value && $cMode == 0) {
    continue; // Block is skipped
}
```

**Important:** Joint blocks (`first_program = 0`) are **never skipped** based on program mode - they're always inserted (assuming they're active).

### 2. Activity Type Mapping
The `first_program` value determines which activity type detail code is used:

```php
$code = match ($blockProgram) {
    FirstProgram::CHALLENGE->value => 'c_free_block',  // Challenge-specific
    FirstProgram::EXPLORE->value   => 'e_free_block',  // Explore-specific
    FirstProgram::JOINT->value     => 'g_free_block',  // Joint/General
    default                        => 'g_free_block',  // Fallback
};
```

### 3. Activity Creation
An activity is created with:
- The mapped activity type detail (determines visibility/column in schedules)
- The block's start/end times
- Reference to the `extra_block` ID

---

## Visual Indicators in UI

### Icon Opacity States:
- **Full opacity (100%)**: Program is active for this block
  - Explore icon full: `first_program === 2 || first_program === 0`
  - Challenge icon full: `first_program === 3 || first_program === 0`
- **Reduced opacity (30%)**: Program is NOT active for this block
- **Grayscale**: Block is inactive (`active === false`)

### Example States:

| first_program | Explore Icon | Challenge Icon | Meaning |
|---------------|--------------|----------------|---------|
| `null` | 30% opacity | 30% opacity | No program assigned - block hidden |
| `2` (Explore) | 100% opacity | 30% opacity | Explore only |
| `3` (Challenge) | 30% opacity | 100% opacity | Challenge only |
| `0` (Joint) | 100% opacity | 100% opacity | Both/all participants |

---

## Default Value

When a new block is created (`addCustom()`), it defaults to:
```typescript
first_program: 3  // Challenge
```

This means new blocks default to Challenge-only by default.

---

## Key Takeaways

1. **Joint (0) = "All participants"** - Block appears in schedules for both Explore and Challenge teams
2. **Explore (2) = "Explore only"** - Block only appears in Explore schedules
3. **Challenge (3) = "Challenge only"** - Block only appears in Challenge schedules
4. **null = "Hidden"** - Block is not assigned to any program (effectively disabled)
5. **Toggle behavior** - Clicking program icons cycles through states, with Joint being the "both" state when switching between Explore and Challenge
6. **Generation impact** - The value determines which activity type code is used (`c_free_block`, `e_free_block`, or `g_free_block`), which affects which schedule columns/views show the activity

---

## Code References

- **Enum definition**: `backend/app/Enums/FirstProgram.php`
- **Toggle logic**: `frontend/src/components/molecules/FreeBlocks.vue` → `toggleProgram()`
- **Visibility filtering**: `frontend/src/components/molecules/FreeBlocks.vue` → `visibleCustomBlocks`
- **Plan generation**: `backend/app/Core/FreeBlockGenerator.php` → `insertFreeActivities()`
- **Validation**: `backend/app/Http/Controllers/Api/ExtraBlockController.php` → `storeOrUpdate()`
