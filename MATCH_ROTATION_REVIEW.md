# Match Rotation Service - Code Review

## ✅ What Looks Good

### 1. **Algorithm Implementation**
- ✅ Correctly implements the 3-step process: anti-rematch sorting, boundary fixing, table diversity
- ✅ Proper lexicographic priority: rematches > table diversity > stability
- ✅ Deterministic tie-breakers using team ID
- ✅ Handles odd-length blocks with cross-boundary pairing

### 2. **Code Quality**
- ✅ Clean separation of concerns (history tracking, pair building, table assignment)
- ✅ Good type hints and PHPDoc
- ✅ Proper validation (even teams, disjoint blocks)
- ✅ Well-structured with clear method names

### 3. **Table Mapping Logic**
- ✅ **2 tables**: Alternating 1,2,1,2 ✓
- ✅ **4 tables**: Windows of 2 matches ✓
  - Matches 1-2 → Tables 1,2
  - Matches 3-4 → Tables 3,4
  - Matches 5-6 → Tables 1,2
  - etc.

---

## ⚠️ Issues & Improvements Needed

### 1. **Table Mapping Mismatch with RobotGameGenerator**

**Problem:** The `MatchRotationService` uses a different table assignment than `RobotGameGenerator`.

**Current RobotGameGenerator logic** (line 108-116):
```php
if ($this->pp("r_tables") == 4) {
    foreach ($this->entries as &$entry) {
        if ($entry['match'] % 2 == 0) {
            // Move table assignments from 1-2 to 3-4
            $entry['table_1'] = 3;
            $entry['table_2'] = 4;
        }
    }
}
```
This means:
- **Odd matches** (1,3,5,...) → Tables 1,2
- **Even matches** (2,4,6,...) → Tables 3,4

**MatchRotationService logic** (tableForMatchIndex):
```php
$window = ceil($matchIndex / 2);  // 1,1,2,2,3,3,4,4
$oddWindow = ($window % 2 === 1);
// Odd window → 1,2; Even window → 3,4
```
This means:
- **Matches 1-2** → Tables 1,2
- **Matches 3-4** → Tables 3,4
- **Matches 5-6** → Tables 1,2

**These are DIFFERENT!**

**Example:**
- Match 2: RobotGameGenerator → Tables 3,4 | RotationService → Tables 1,2
- Match 3: RobotGameGenerator → Tables 1,2 | RotationService → Tables 3,4

**Solution:** We need to align these. The RobotGameGenerator's simpler logic (odd→1,2, even→3,4) should probably be adopted by the RotationService.

---

### 2. **Integration Point Missing**

**Problem:** The service is standalone but doesn't integrate with `RobotGameGenerator`.

**Current RobotGameGenerator flow:**
1. `createMatchPlan()` builds entries for all 4 rounds
2. Uses `getNextTeam()` for descending rotation (not optimal for Q2/Q3)
3. Saves to `match` table via `saveMatchEntries()`

**What needs to happen:**
1. Round 1 (Test Round) stays as-is from `createMatchPlan()`
2. Rounds 1-3 (Robot Game rounds) need to:
   - Extract the "delivered" sequence from current logic
   - Split into First/Middle/Last blocks based on `j_lanes`
   - Call `MatchRotationService` to optimize Rounds 2-3
   - Update `$this->entries` with optimized sequences

**Integration strategy:**
```php
// In RobotGameGenerator::createMatchPlan()

// ... existing Round 0 (TR) and Round 1 generation ...

// Extract Round 1 sequence for baseline
$round1Seq = $this->extractRoundSequence(1);

// Generate Round 2 & 3 "delivered" sequences (current logic)
$round2Delivered = $this->extractRoundSequence(2);
$round3Delivered = $this->extractRoundSequence(3);

// Split into blocks (First = j_lanes teams, Last = j_lanes teams, Middle = rest)
$round2Blocks = $this->splitIntoBlocks($round2Delivered);
$round3Blocks = $this->splitIntoBlocks($round3Delivered);

// Optimize with MatchRotationService
$rotationService = new MatchRotationService();
$optimized = $rotationService->plan(
    $this->pp('r_tables'),
    $round1Seq,
    $round2Blocks,
    $round3Blocks
);

// Update entries for rounds 2 and 3
$this->applyOptimizedSequence(2, $optimized['round2']);
$this->applyOptimizedSequence(3, $optimized['round3']);
```

---

### 3. **Block Definition Clarity**

**Question:** How are First/Middle/Last blocks determined?

From the algorithm description:
- `First` = first `j_lanes` teams
- `Last` = last `j_lanes` teams  
- `Middle` = remaining teams

**Example:** 12 teams, 3 lanes
- First: teams starting judging in Round 1
- Last: teams starting judging in Round 3 (or later)
- Middle: teams starting judging in Round 2

**Current RobotGameGenerator logic:**
```php
// Round 1: teams = j_lanes * 2 (starts with team at position based on j_rounds)
// Round 2: teams = j_lanes * 3 (or similar)
// Round 3: teams = c_teams
```

The logic for determining which teams belong to which block needs to be explicit and match the rotation algorithm's expectations.

---

### 4. **Performance Concern in improveTablesByLocalSwaps()**

**Issue:** Line 345-347, 355-357
```php
$beforePairs = $this->buildPairs($seq);
$beforeTables = $this->assignTablesForPairs(count($beforePairs));
// ... then again after swap
$afterPairs = $this->buildPairs($seq);
$afterTables = $this->assignTablesForPairs(count($afterPairs));
```

This rebuilds ALL pairs for every swap attempt. For large teams, this is O(n²) or worse.

**Solution:** Cache pairs/tables at block boundaries and only recompute affected pairs.

---

### 5. **Volunteer Team Handling**

**Issue:** `RobotGameGenerator` has special handling for volunteer teams (team 0):
```php
'team_1' => ($team_1 > $this->pp("c_teams")) ? 0 : $team_1,
```

**Question:** Does `MatchRotationService` need to handle volunteer teams (team 0)? If so, should they be excluded from rotation or treated specially?

---

### 6. **Test Round (Round 0) Not Included**

The rotation service only handles Rounds 2 & 3, but the algorithm description mentions that Test Round should use the same tables as Round 1 (Q2/Q4 requirement).

**Current RobotGameGenerator** already handles this (lines 125-162), but we need to ensure the rotation doesn't break this constraint.

---

## 🔧 Recommended Changes

### Priority 1: Fix Table Mapping
Update `tableForMatchIndex()` to match `RobotGameGenerator`:
```php
private function tableForMatchIndex(int $matchIndex): int
{
    if ($this->rTables === 2) {
        return ($matchIndex % 2 === 1) ? 1 : 2;
    }
    // r_tables=4: odd matches → 1,2; even matches → 3,4
    return ($matchIndex % 2 === 1) ? 
        (($matchIndex % 4 === 1) ? 1 : 2) : 
        (($matchIndex % 4 === 2) ? 3 : 4);
}
```

**OR** align to the pattern used in RobotGameGenerator:
```php
private function tableForMatchIndex(int $matchIndex): int
{
    if ($this->rTables === 2) {
        return ($matchIndex % 2 === 1) ? 1 : 2;
    }
    // 4 tables: odd matches on 1+2, even matches on 3+4
    if ($matchIndex % 2 === 1) {
        return 1; // or 2, need to alternate within odd matches
    } else {
        return 3; // or 4, need to alternate within even matches
    }
}
```

**Actually**, looking more carefully at RobotGameGenerator, I see table_1 and table_2 are BOTH assigned per match. Let me re-examine...

### Priority 2: Create Integration Methods
Add to `RobotGameGenerator`:
- `extractRoundSequence(int $round): array`
- `splitIntoBlocks(array $sequence): array`
- `applyOptimizedSequence(int $round, array $optimized): void`

### Priority 3: Add Unit Tests
Create tests for:
- Small cases (6, 8, 10 teams)
- Different table counts (2, 4)
- Different lane counts (2, 3, 4)
- Verify Q2 and Q3 improvements

---

## 📊 Expected Impact

**Before (current RobotGameGenerator):**
- Q2 pass rate: ~60-70% (estimate)
- Q3 pass rate: ~50-60% (estimate)

**After (with MatchRotationService):**
- Q2 pass rate: ~90-95% (target)
- Q3 pass rate: ~85-95% (target)

---

## 🎯 Next Steps

1. **Clarify table mapping** - Which logic is correct? RobotGameGenerator or RotationService?
2. **Understand block semantics** - How do First/Middle/Last map to judging rounds?
3. **Design integration** - Where and how to call RotationService from RobotGameGenerator?
4. **Test with real data** - Use QualityEvaluatorService to measure improvement
5. **Handle edge cases** - Volunteer teams, asymmetric games, odd configurations

Would you like me to:
- A) Fix the table mapping issue first
- B) Create the integration methods
- C) Understand your current block/judging logic better
- D) Something else?

