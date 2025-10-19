# Database Write Optimization Analysis

## Current Write Patterns in Plan Generation

### 1. ActivityWriter (Core bottleneck)

**Location:** `backend/app/Core/ActivityWriter.php`

**Current Pattern:**
```php
// Line 51-54: One INSERT per group
ActivityGroup::create([...]);

// Line 82-95: One INSERT per activity  
Activity::create([...]);
```

**Scale for typical Challenge plan (15 teams, 3 lanes, 4 tables):**
- ~20-30 ActivityGroup inserts
- ~300-500 Activity inserts
- **Total: ~350-550 individual INSERT queries**

**Used by:**
- ChallengeGenerator
- ExploreGenerator  
- FinaleGenerator
- FreeBlockGenerator

---

### 2. RobotGameGenerator::saveMatchEntries()

**Location:** `backend/app/Core/RobotGameGenerator.php:323-370`

**Current Pattern:**
```php
// Line 356: DELETE all existing
MatchEntry::where('plan', $planId)->delete();

// Line 359-369: One INSERT per match
foreach ($this->entries as $entry) {
    MatchEntry::create([...]);
}
```

**Scale:**
- DELETE: 1 query
- INSERT: ~32 matches × 4 rounds = 128 individual INSERTs
- **Called TWICE** (line 203 before rotation, line 261 after rotation)
- **Total: 2 DELETE + 256 INSERTs = 258 queries**

---

### 3. QualityEvaluatorService (Per-team updates)

**Location:** `backend/app/Services/QualityEvaluatorService.php`

**Current Pattern (in calculateQ1, Q2, Q3, Q4, Q5):**
```php
// One UPDATE per team
foreach ($teams as $team) {
    QPlanTeam::where('q_plan', $qPlanId)
        ->where('team', $team)
        ->update([...]);
}
```

**Scale:**
- Q1: 15 UPDATEs
- Q2: 15 UPDATEs
- Q3: 15 UPDATEs
- Q4: 15 UPDATEs
- Q5: 15 UPDATEs
- **Total: 75 UPDATE queries per plan evaluation**

---

### 4. QualityEvaluatorService::generateQPlansFromSelection()

**Location:** `backend/app/Services/QualityEvaluatorService.php:68-102`

**Current Pattern:**
```php
// One INSERT per plan
Plan::create([...]);

// One INSERT per parameter (4 parameters)
PlanParamValue::create([...]);  // ×4

// One INSERT per qPlan
QPlan::create([...]);
```

**Scale for 200 plans:**
- 200 Plan inserts
- 800 PlanParamValue inserts (4 per plan)
- 200 QPlan inserts
- **Total: 1,200 individual INSERTs**

---

## 📊 Total Database Load per Quality Run (200 plans)

| Operation | Queries per Plan | Total for 200 Plans | Time Estimate |
|-----------|------------------|---------------------|---------------|
| Create Plan+Params+QPlan | 6 | 1,200 | ~2-5 seconds |
| Plan Generation (Activities) | 350-550 | 70,000-110,000 | ~30-60 seconds |
| Match Entries (2× writes) | 258 | 51,600 | ~10-20 seconds |
| Quality Evaluation | 75 | 15,000 | ~5-10 seconds |
| **TOTAL** | **689-889** | **~138,000-178,000** | **~45-95 seconds** |

**Plus plan generation logic (CPU time): ~20-40 seconds**

**Grand Total: ~1-2 minutes per 200 plans minimum**

---

## 🎯 High-Impact Optimization Opportunities

### Priority 1: ActivityWriter Bulk Inserts ⭐⭐⭐

**Impact:** 70,000-110,000 queries → ~400-800 queries (99% reduction!)

**Approach:** Batch-collect activities during generation, bulk insert at end

**Complexity:** Medium (need to refactor ActivityWriter to buffer)

**Expected Speedup:** 20-40 seconds saved per 200 plans

---

### Priority 2: RobotGameGenerator Bulk Match Inserts ⭐⭐⭐

**Impact:** 51,600 queries → 400 queries (99% reduction!)

**Approach:** Use `MatchEntry::insert()` instead of `create()` in loop

**Complexity:** Easy (one-line change)

**Expected Speedup:** 10-15 seconds saved per 200 plans

**Implementation:**
```php
private function saveMatchEntries(): void
{
    $planId = $this->pp('g_plan');
    
    // Clear existing
    MatchEntry::where('plan', $planId)->delete();
    
    // Bulk insert all entries
    $data = array_map(fn($e) => [
        'plan' => $planId,
        'round' => $e['round'],
        'match_no' => $e['match'],
        'table_1' => $e['table_1'],
        'table_2' => $e['table_2'],
        'table_1_team' => $e['team_1'],
        'table_2_team' => $e['team_2'],
    ], $this->entries);
    
    MatchEntry::insert($data);  // Single bulk INSERT
}
```

---

### Priority 3: QualityEvaluator Bulk Updates ⭐⭐

**Impact:** 15,000 queries → 2,000 queries (87% reduction!)

**Approach:** Use raw SQL with CASE WHEN for batch updates

**Complexity:** Medium (need to build dynamic SQL)

**Expected Speedup:** 3-5 seconds saved per 200 plans

**Implementation:**
```php
private function calculateQ3(int $qPlanId): void
{
    // ... existing calculation logic ...
    
    // Build bulk update
    $cases = [];
    $teamIds = [];
    foreach ($opponents as $team => $faced) {
        if ($team === 0) continue;
        
        $uniqueOpponents = count(array_unique($faced));
        $ok = $uniqueOpponents === 3 ? 1 : 0;
        
        $cases['q3_ok'][] = "WHEN {$team} THEN {$ok}";
        $cases['q3_teams'][] = "WHEN {$team} THEN {$uniqueOpponents}";
        $teamIds[] = $team;
    }
    
    if (!empty($teamIds)) {
        $teamList = implode(',', $teamIds);
        DB::statement("
            UPDATE q_plan_team
            SET q3_ok = CASE team " . implode(' ', $cases['q3_ok']) . " END,
                q3_teams = CASE team " . implode(' ', $cases['q3_teams']) . " END
            WHERE q_plan = ? AND team IN ({$teamList})
        ", [$qPlanId]);
    }
}
```

---

### Priority 4: generateQPlansFromSelection Bulk Inserts ⭐

**Impact:** 1,200 queries → 600 queries (50% reduction!)

**Approach:** Collect all plans/params, bulk insert

**Complexity:** Medium

**Expected Speedup:** 1-2 seconds saved (one-time per qRun)

---

## 💡 Recommended Implementation Order

### Phase 1: Quick Wins (Easy + High Impact)

1. **RobotGameGenerator::saveMatchEntries()** - Bulk insert
   - One-line change
   - Big impact (99% reduction)
   - Low risk

### Phase 2: ActivityWriter Refactor (Medium Complexity)

2. **ActivityWriter** - Buffer and bulk insert
   - Requires architectural change
   - Biggest overall impact
   - Moderate risk (core functionality)

**Two approaches:**

**Approach A: Buffer in ActivityWriter**
```php
class ActivityWriter
{
    private array $activityBuffer = [];
    private array $groupBuffer = [];
    
    public function insertActivity(...) {
        // Add to buffer instead of immediate insert
        $this->activityBuffer[] = [...];
        return count($this->activityBuffer); // Return index
    }
    
    public function flush(): void {
        // Bulk insert all buffered activities
        ActivityGroup::insert($this->groupBuffer);
        Activity::insert($this->activityBuffer);
    }
}

// Call flush() at end of generation
```

**Approach B: Keep current API, batch internally**
- Collect activities in batches of 100
- Flush every 100 activities
- More complex but safer

### Phase 3: Quality Evaluator (Medium Complexity)

3. **QualityEvaluatorService** - Bulk updates
   - Moderate impact
   - Requires careful SQL construction
   - Medium risk

---

## 🚀 Expected Overall Improvement

**Before:** ~138,000-178,000 queries per 200 plans
**After (all optimizations):** ~3,000-5,000 queries per 200 plans

**Speedup:** 95-97% reduction in database queries
**Time saved:** 30-60 seconds per 200 plans (database I/O only)

**Note:** CPU time for plan generation logic remains the same

---

## 🎯 Recommendation

**Start with Priority 1 (RobotGameGenerator):**
- Easiest to implement
- Significant impact
- Low risk
- Can be done in 5 minutes

**Then decide on ActivityWriter:**
- Bigger change
- Needs careful testing
- But biggest overall impact

Would you like me to implement the RobotGameGenerator bulk insert first?

