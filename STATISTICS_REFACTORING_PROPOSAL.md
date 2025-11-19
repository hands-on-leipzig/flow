# Statistics.vue Refactoring Proposal

## Current State Analysis

### File Metrics
- **Total Lines**: 987 lines
- **Script Section**: ~474 lines
- **Template Section**: ~513 lines
- **Complexity**: Very High (multiple responsibilities)

### Naming Consistency Review
✅ **Component Imports**: Consistent PascalCase
- `TimelineChart` from `./TimelineChart.vue`
- `OneLinkAccessChart` from `./OneLinkAccessChart.vue`

✅ **File Naming**: Consistent with codebase
- All molecule components use PascalCase: `ConfirmationModal.vue`, `MParameter.vue`, etc.

### Current Structure Issues

1. **Multiple Responsibilities** (Violates Single Responsibility Principle)
   - Data fetching and state management
   - Data transformation (flattening rows)
   - Table rendering
   - Modal management (5 different modal types)
   - Orphan cleanup UI
   - Statistics display boxes
   - Event selection logic

2. **Large Template** (~513 lines)
   - Deeply nested conditionals
   - Repetitive modal structures
   - Complex table with many columns
   - Multiple modal types in one teleport

3. **Complex State Management**
   - Multiple refs and computed properties
   - Modal state with union types
   - Data transformation logic mixed with presentation

4. **Code Duplication**
   - Modal structure repeated for each modal type
   - Similar button patterns
   - Repeated styling classes

## Refactoring Options

### Option 1: Extract Modals (Recommended First Step)
**Complexity**: Low | **Impact**: High | **Risk**: Low

Extract each modal into its own component:
- `StatisticsExpertParametersModal.vue`
- `StatisticsTimelineModal.vue`
- `StatisticsAccessChartModal.vue`
- `StatisticsDeleteModal.vue` (for plan-delete and cleanup)

**Benefits**:
- Reduces main component by ~150 lines
- Improves maintainability
- Makes modals reusable
- Easier to test individually

**Files to Create**:
```
frontend/src/components/molecules/statistics/
  ├── StatisticsExpertParametersModal.vue
  ├── StatisticsTimelineModal.vue
  ├── StatisticsAccessChartModal.vue
  └── StatisticsDeleteModal.vue
```

### Option 2: Extract Table Components
**Complexity**: Medium | **Impact**: High | **Risk**: Medium

Extract table-related components:
- `StatisticsTable.vue` - Main table wrapper
- `StatisticsTableRow.vue` - Single row component
- `StatisticsTableHeader.vue` - Table header

**Benefits**:
- Reduces main component by ~200 lines
- Easier to modify table structure
- Better separation of concerns

**Files to Create**:
```
frontend/src/components/molecules/statistics/
  ├── StatisticsTable.vue
  ├── StatisticsTableRow.vue
  └── StatisticsTableHeader.vue
```

### Option 3: Extract Statistics Boxes
**Complexity**: Low | **Impact**: Medium | **Risk**: Low

Extract the summary boxes into components:
- `StatisticsSummaryBox.vue` - Reusable box component
- `StatisticsOrphanBadges.vue` - Orphan cleanup badges
- `StatisticsSeasonFilter.vue` - Season radio buttons

**Benefits**:
- Reduces main component by ~100 lines
- Reusable summary box component
- Cleaner template

**Files to Create**:
```
frontend/src/components/molecules/statistics/
  ├── StatisticsSummaryBox.vue
  ├── StatisticsOrphanBadges.vue
  └── StatisticsSeasonFilter.vue
```

### Option 4: Extract Composables
**Complexity**: Medium | **Impact**: High | **Risk**: Medium

Move data fetching and transformation logic to composables:
- `useStatisticsData.ts` - Data fetching and state
- `useStatisticsTable.ts` - Table row flattening and helpers
- `useStatisticsModals.ts` - Modal state management

**Benefits**:
- Separates business logic from presentation
- Reusable logic
- Easier to test
- Reduces main component by ~200 lines

**Files to Create**:
```
frontend/src/composables/statistics/
  ├── useStatisticsData.ts
  ├── useStatisticsTable.ts
  └── useStatisticsModals.ts
```

### Option 5: Complete Component Split (Most Aggressive)
**Complexity**: High | **Impact**: Very High | **Risk**: High

Split into multiple top-level components:
- `Statistics.vue` - Main orchestrator (reduced to ~200 lines)
- `StatisticsOverview.vue` - Summary boxes and filters
- `StatisticsTable.vue` - Main table
- `StatisticsModals.vue` - Modal container

**Benefits**:
- Very clean separation
- Each component < 300 lines
- Maximum maintainability

**Drawbacks**:
- More files to manage
- More prop drilling potentially needed
- Higher refactoring effort

## Recommended Refactoring Path

### Phase 1: Extract Modals (Quick Win)
1. Create `StatisticsExpertParametersModal.vue`
2. Create `StatisticsTimelineModal.vue`
3. Create `StatisticsAccessChartModal.vue`
4. Create `StatisticsDeleteModal.vue` (handles both plan-delete and cleanup)
5. Update `Statistics.vue` to use new modal components

**Estimated Reduction**: ~150 lines
**Time**: 1-2 hours

### Phase 2: Extract Statistics Boxes
1. Create `StatisticsOrphanBadges.vue`
2. Create `StatisticsSummaryBox.vue` (reusable)
3. Create `StatisticsSeasonFilter.vue`
4. Update `Statistics.vue` to use new components

**Estimated Reduction**: ~100 lines
**Time**: 1 hour

### Phase 3: Extract Table Components
1. Create `StatisticsTableRow.vue` with props for row data
2. Create `StatisticsTable.vue` wrapper
3. Move table rendering logic to components

**Estimated Reduction**: ~200 lines
**Time**: 2-3 hours

### Phase 4: Extract Composables (Optional)
1. Create `useStatisticsData.ts` for data fetching
2. Create `useStatisticsTable.ts` for row flattening
3. Create `useStatisticsModals.ts` for modal state

**Estimated Reduction**: ~200 lines
**Time**: 2-3 hours

## File Structure After Refactoring

```
frontend/src/components/molecules/statistics/
  ├── Statistics.vue (main, ~300 lines)
  ├── StatisticsExpertParametersModal.vue
  ├── StatisticsTimelineModal.vue
  ├── StatisticsAccessChartModal.vue
  ├── StatisticsDeleteModal.vue
  ├── StatisticsOrphanBadges.vue
  ├── StatisticsSummaryBox.vue
  ├── StatisticsSeasonFilter.vue
  ├── StatisticsTable.vue
  └── StatisticsTableRow.vue

frontend/src/composables/statistics/ (optional)
  ├── useStatisticsData.ts
  ├── useStatisticsTable.ts
  └── useStatisticsModals.ts
```

## Naming Convention Recommendations

### Component Files
- Use PascalCase: `StatisticsTableRow.vue` ✅
- Prefix with `Statistics` for clarity: `StatisticsExpertParametersModal.vue` ✅
- Keep existing chart components as-is: `TimelineChart.vue`, `OneLinkAccessChart.vue` ✅

### Composables
- Use camelCase with `use` prefix: `useStatisticsData.ts` ✅
- Group in `composables/statistics/` directory

### Types
- Keep in main component or move to `types/statistics.ts`
- Use PascalCase: `FlattenedRow`, `ModalMode`, `CleanupTarget`

## Benefits Summary

After all phases:
- **Main component**: ~300 lines (down from 987)
- **Better maintainability**: Each component has single responsibility
- **Easier testing**: Smaller, focused components
- **Reusability**: Modal and box components can be reused
- **Better organization**: Clear file structure
- **Easier onboarding**: New developers can understand smaller files

## Migration Strategy

1. **Incremental**: Refactor one piece at a time
2. **Test after each phase**: Ensure functionality remains intact
3. **Keep existing API**: Don't change props/events initially
4. **Gradual adoption**: Can stop at any phase if needed

## Recommendation

**Start with Phase 1 (Extract Modals)** - This provides immediate benefits with low risk and can be done incrementally.

