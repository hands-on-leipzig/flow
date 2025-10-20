# Event Cascade Delete Analysis

## Question: What happens when you delete an event?

When you delete a record from the `event` table, here's what happens to related data:

---

## ✅ CASCADE DELETE (Automatically Deleted)

These tables have `onDelete('cascade')` and will be automatically deleted:

### 1. **publication**
- `publication.event` → `event.id` with CASCADE
- **Result:** All publications for the event are deleted

---

## ⚠️ RESTRICT (Delete Blocked if Data Exists)

These tables reference `event` but **do NOT have CASCADE DELETE**. If any records exist, the event deletion will **FAIL**:

### 2. **plan**
- `plan.event` → `event.id` (NO cascade)
- **Result:** ❌ Cannot delete event if plans exist
- **Note:** Plans cascade to activity_group, which cascades to activity

### 3. **team**
- `team.event` → `event.id` (NO cascade)
- **Result:** ❌ Cannot delete event if teams exist

### 4. **room**
- `room.event` → `event.id` (NO cascade)
- **Result:** ❌ Cannot delete event if rooms exist
- **Note:** Rooms cascade to room_type_room

### 5. **slideshow**
- `slideshow.event` → `event.id` (NO cascade)
- **Result:** ❌ Cannot delete event if slideshows exist
- **Note:** Slideshows cascade to slides

### 6. **activity_group**
- `activity_group.event` → `event.id` (NO cascade)
- **Result:** ❌ Cannot delete event if activity groups exist
- **Note:** This is nullable, so it might not block

### 7. **activity**
- `activity.event` → `event.id` (NO cascade)
- **Result:** ❌ Cannot delete event if activities exist
- **Note:** This is nullable, so it might not block

### 8. **logo**
- `logo.event` → `event.id` (NO cascade)
- **Result:** ❌ Cannot delete event if logos exist
- **Note:** This is nullable, so it might not block

### 9. **event_logo**
- `event_logo.event` → `event.id` (NO cascade)
- **Result:** ❌ Cannot delete event if event_logo entries exist

### 10. **table_event**
- `table_event.event` → `event.id` (NO cascade)
- **Result:** ❌ Cannot delete event if table_event entries exist

### 11. **room_type_room**
- `room_type_room.event` → `event.id` (NO cascade)
- **Result:** ❌ Cannot delete event if room_type_room entries exist

---

## 🔗 CASCADE CHAIN (If Event Could Be Deleted)

If the event could be deleted (no blocking constraints), this would be the cascade chain:

```
event (deleted)
├─> publication (CASCADE) ✓
└─> [BLOCKED by these if they exist]
    ├─> plan (NO CASCADE) ❌
    │   └─> activity_group (CASCADE)
    │       └─> activity (CASCADE)
    │   └─> plan_extra_block (CASCADE)
    │   └─> plan_param_value (CASCADE)
    │   └─> team_plan (CASCADE)
    │   └─> match (CASCADE)
    ├─> team (NO CASCADE) ❌
    ├─> room (NO CASCADE) ❌
    │   └─> room_type_room (CASCADE)
    ├─> slideshow (NO CASCADE) ❌
    │   └─> slide (CASCADE)
    ├─> table_event (NO CASCADE) ❌
    ├─> event_logo (NO CASCADE) ❌
    └─> logo (NO CASCADE) ❌
```

---

## 🎯 Practical Answer

### **Can you delete an event?**

**NO, in most cases** - because events almost always have:
- Plans (every real event)
- Teams (every real event)
- Rooms (most events)

**YES, only if** the event is completely empty:
- No plans
- No teams
- No rooms
- No slideshows
- No table_event entries
- No event_logo entries
- No room_type_room entries

### **Special Case: Quality Test Events**

The quality test event `!!! QPlan Event - nur für den Qualitätstest verwendet !!!` has:
- ✅ Many plans (200+ per qRun)
- ❌ Cannot be deleted directly

---

## 💡 How to Delete an Event (If Needed)

### Option 1: Manual Cascade (Recommended)
```php
DB::transaction(function() use ($eventId) {
    // Delete in order to respect foreign keys
    
    // 1. Delete activities (via activity_group cascade)
    $planIds = DB::table('plan')->where('event', $eventId)->pluck('id');
    DB::table('activity_group')->whereIn('plan', $planIds)->delete(); // Cascades to activity
    
    // 2. Delete plan-related data
    DB::table('plan_param_value')->whereIn('plan', $planIds)->delete();
    DB::table('team_plan')->whereIn('plan', $planIds)->delete();
    DB::table('match')->whereIn('plan', $planIds)->delete();
    DB::table('plan_extra_block')->whereIn('plan', $planIds)->delete();
    
    // 3. Delete plans
    DB::table('plan')->where('event', $eventId)->delete();
    
    // 4. Delete teams
    DB::table('team')->where('event', $eventId)->delete();
    
    // 5. Delete rooms (cascades to room_type_room)
    DB::table('room')->where('event', $eventId)->delete();
    
    // 6. Delete slideshows (cascades to slides)
    DB::table('slideshow')->where('event', $eventId)->delete();
    
    // 7. Delete other event data
    DB::table('table_event')->where('event', $eventId)->delete();
    DB::table('event_logo')->where('event', $eventId)->delete();
    DB::table('logo')->where('event', $eventId)->delete();
    DB::table('room_type_room')->where('event', $eventId)->delete();
    
    // 8. Finally delete the event (publication will cascade)
    DB::table('event')->where('id', $eventId)->delete();
});
```

### Option 2: Database CASCADE (Requires Migration)

Add CASCADE DELETE to all event foreign keys:
```php
// Migration to add CASCADE DELETE
Schema::table('plan', function (Blueprint $table) {
    $table->dropForeign(['event']);
    $table->foreign('event')->references('id')->on('event')->onDelete('cascade');
});

// Repeat for: team, room, slideshow, table_event, event_logo, room_type_room, etc.
```

**Risk:** Accidental event deletion would cascade to ALL related data!

---

## 🔍 Current Behavior Summary

**Deleting an event:**
- ✅ **WILL delete:** publication (only this has CASCADE)
- ❌ **WILL FAIL if exists:** plan, team, room, slideshow, table_event, event_logo, room_type_room
- ⚠️ **Safe by design:** Prevents accidental data loss

**To delete a real event with data:**
- Must manually delete all related records first
- Or create a migration to add CASCADE DELETE (risky)
- Or use a dedicated service method with transaction

---

## 💬 Recommendation

**Keep current design (RESTRICT):**
- ✅ Prevents accidental data loss
- ✅ Forces intentional deletion
- ✅ Makes you think about what you're deleting

**If you need to delete events regularly:**
- Create a service method: `EventService::deleteWithRelatedData($eventId)`
- Use transactions for safety
- Log what's being deleted
- Require confirmation/authorization

**For quality test events:**
- Already have delete/compress functionality in QualityController
- Don't need event-level deletion
- Just delete qRun (which handles qPlans appropriately)

