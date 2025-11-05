# Refactoring Plan: Debounce System Consolidation

## Overview
Consolidate debounce systems and fix state synchronization issues while maintaining immediate DB persistence for block changes.

## Goals
1. Single debounce system in Schedule.vue for generator triggers
2. Remove duplicate change detection logic
3. Fix state synchronization (single source of truth)
4. Immediate DB saves for block changes, debounced generator triggers

---

## Issue 1: Remove Duplicate Change Detection

### Current State
- `handleParamUpdate()` checks for changes (lines 249-256)
- `useDebouncedSave` composable also checks via `changeDetection` callback (lines 62-70)

### Solution
**Remove duplicate check in `handleParamUpdate()`**
- Let the composable handle all change detection
- Keep the composable's `changeDetection` callback for validation

### Changes
```typescript
// Schedule.vue - handleParamUpdate()
function handleParamUpdate(param: { name: string, value: any }) {
  const p = paramMapByName.value[param.name]
  if (!p) {
    console.warn('Parameter not found:', param.name)
    return
  }

  // Remove this duplicate check - composable handles it
  // const oldVal = String(originalValues.value[param.name] ?? '')
  // const newVal = String(param.value ?? '')
  // if (oldVal === newVal) return

  // Update local state immediately for UI responsiveness
  p.value = param.value

  // Schedule update - composable will check for changes
  scheduleUpdate(param.name, param.value)
}
```

---

## Issue 2: Consolidate originalValues (Single Source of Truth)

### Current State
- Schedule.vue maintains `originalValues` ref (line 99)
- Composable maintains its own `originalValues` (line 44)
- Both are updated separately (lines 116, 318-321)

### Solution
**Use composable as single source of truth**
- Remove `originalValues` ref from Schedule.vue
- Use `setOriginal()` / `setOriginals()` from composable
- Update composable after successful saves

### Changes
```typescript
// Schedule.vue - Remove originalValues ref
// const originalValues = ref<Record<string, any>>({}) // DELETE

// In fetchParams()
setOriginals(Object.fromEntries(
  parameters.value.map(p => [p.name, p.value])
))

// In updateParams() after successful save
// Remove: originalValues.value[name] = value
// Keep: setOriginal(name, value) // Already done, but ensure it's only here
```

---

## Issue 3: Remove Redundant isGenerating Watcher

### Current State
- Manual `watch(isGenerating)` calls `freeze()`/`unfreeze()` (lines 228-239)
- Composable already checks `isGenerating()` in `startCountdown()` (line 94)

### Solution
**Remove manual watcher - composable handles it automatically**

### Changes
```typescript
// Schedule.vue - Remove this entire watcher
// watch(isGenerating, (generating) => {
//   if (generating) {
//     freeze()
//   } else {
//     setTimeout(() => {
//       unfreeze()
//     }, 100)
//   }
// })
```

The composable's `startCountdown()` already checks `isGenerating()` and freezes automatically.

---

## Issue 4: Unify Block Updates with Immediate DB Save + Debounced Generator

### Requirements
- Block changes (name, description, link, timing fields) → **Save to DB immediately** (no debounce)
- Block timing/toggle changes → **Also schedule debounced generator trigger** (via Schedule.vue)
- Use same debounce system as parameters for generator triggers

### Architecture

```
User changes block field
    ↓
InsertBlocks/ExtraBlocks: Save to DB immediately (on blur/change)
    ↓
If timing/toggle field: Emit to Schedule.vue
    ↓
Schedule.vue: Schedule debounced generator trigger
    ↓
After debounce: Trigger generator (if needed)
```

### Changes

#### 4a: InsertBlocks.vue - Immediate DB Save

**Remove internal debounce system**
- Remove `useDebouncedSave` from InsertBlocks
- Remove `SavingToast` from InsertBlocks
- Save to DB immediately on field blur/change

**New functions:**
```typescript
// Immediate save for block field changes
async function saveBlockField(blockId: number, field: string, value: any) {
  if (!props.planId) return
  
  const block = blocks.value.find(b => b.id === blockId)
  if (!block) return
  
  // Update local state
  block[field] = value
  
  // Save to DB immediately
  const blockData = { id: blockId, [field]: value }
  
  // Determine if this triggers generator
  const timingFields = ['buffer_before', 'duration', 'buffer_after', 'insert_point', 'first_program']
  const toggleFields = ['active']
  const needsGenerator = timingFields.includes(field) || toggleFields.includes(field)
  
  if (!needsGenerator) {
    blockData.skip_regeneration = true
  }
  
  try {
    await axios.post(`/plans/${props.planId}/extra-blocks`, blockData)
    
    // If timing/toggle change, notify Schedule for debounced generator
    if (needsGenerator && props.onUpdate) {
      props.onUpdate([{ 
        name: `block_${blockId}_${field}`, 
        value: value,
        triggerGenerator: true 
      }])
    }
  } catch (error) {
    console.error('Failed to save block field:', error)
    // Could rollback local state here if needed
  }
}
```

**Update field handlers:**
```typescript
// Replace scheduleUpdate calls with immediate save
function onFixedNumInput(pointId: number, field: string, e: Event) {
  const value = Number((e.target as HTMLInputElement).value)
  const block = fixedByPoint.value[pointId]
  if (block?.id) {
    saveBlockField(block.id, field, value) // Immediate save
  }
}
```

#### 4b: Schedule.vue - Handle Block Generator Triggers

**Add generator trigger tracking:**
```typescript
// Track which blocks need generator (separate from DB saves)
const blockGeneratorTriggers = ref<Set<string>>(new Set())

// In handleBlockUpdates()
function handleBlockUpdates(updates: Array<{name: string, value: any, triggerGenerator?: boolean}>) {
  // Only process generator triggers, not DB saves (those are done immediately in InsertBlocks)
  const generatorUpdates = updates.filter(u => u.triggerGenerator)
  
  if (generatorUpdates.length > 0) {
    // Schedule generator trigger via debounce system
    generatorUpdates.forEach(update => {
      scheduleUpdate(update.name, update.value)
    })
  }
}
```

**Update updateParams() to handle block generator triggers:**
```typescript
async function updateParams(params: Array<{ name: string, value: any }>, afterUpdate?: () => Promise<void>) {
  // Separate parameter updates from block generator triggers
  const paramUpdates = params.filter(p => !p.name.startsWith('block_'))
  const blockGeneratorTriggers = params.filter(p => p.name.startsWith('block_'))
  
  // 1. Save parameters (existing logic)
  if (paramUpdates.length > 0) {
    // ... existing parameter save logic ...
  }
  
  // 2. Block generator triggers - just trigger generator, no DB save needed
  // (DB saves were done immediately in InsertBlocks)
  let needsRegeneration = false
  if (blockGeneratorTriggers.length > 0) {
    needsRegeneration = true
  }
  
  // 3. Trigger generator if needed
  if (needsRegeneration || paramUpdates.length > 0) {
    await runGeneratorOnce(afterUpdate)
  }
}
```

#### 4c: ExtraBlocks.vue - Same Pattern

**Apply same pattern as InsertBlocks:**
- Remove internal debounce for DB saves
- Save immediately on field change
- Emit generator triggers to parent (if needed)

**Note:** ExtraBlocks already has more complex logic for timing changes. Ensure:
- Immediate DB save for all field changes
- Generator triggers only for timing/toggle fields
- Use parent's debounce system for generator triggers

---

## Implementation Order

1. **Phase 1: Cleanup (Issues 1-3)**
   - Remove duplicate change detection
   - Consolidate originalValues
   - Remove redundant watcher

2. **Phase 2: InsertBlocks Refactoring (Issue 4a-b)**
   - Remove internal debounce
   - Implement immediate DB saves
   - Add generator trigger emission

3. **Phase 3: Schedule.vue Integration (Issue 4c)**
   - Update handleBlockUpdates to handle generator triggers
   - Update updateParams to process generator triggers separately

4. **Phase 4: ExtraBlocks Refactoring (Issue 4d)**
   - Apply same pattern as InsertBlocks
   - Ensure immediate saves + debounced generator

5. **Phase 5: Testing**
   - Verify block changes save immediately
   - Verify generator is debounced
   - Verify no duplicate saves
   - Verify error handling

---

## Testing Checklist

- [ ] Parameter changes: debounced correctly, no duplicate saves
- [ ] Block field changes (name, description, link): save immediately, no generator
- [ ] Block timing changes: save immediately, generator debounced
- [ ] Block toggle changes: save immediately, generator debounced
- [ ] Multiple rapid changes: only last value saved, single generator run
- [ ] Generator state: countdown freezes during generation
- [ ] Error handling: failed saves don't break the flow
- [ ] No duplicate debounce timers visible
- [ ] originalValues stays in sync

---

## Risks

1. **Breaking existing behavior**: Ensure immediate saves don't conflict with generator logic
2. **Race conditions**: Multiple immediate saves might cause issues - may need queuing
3. **Error handling**: Failed immediate saves need proper rollback/notification
4. **Testing complexity**: Need to test both immediate saves and debounced generator triggers

---

## Notes

- Block changes go to `ExtraBlockController::storeOrUpdate()` (different from parameter controller)
- Backend already supports `skip_regeneration` flag for non-timing changes
- Generator trigger should use same 60-second debounce as parameters
- Consider adding visual feedback for immediate saves (subtle indicator vs countdown)

