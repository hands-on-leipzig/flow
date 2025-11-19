# Delete Dialogs Audit

This document lists all places in the UI where users can delete something and what confirmation dialog is used.

## Summary

- **Total delete operations found:** 8
- **Using ConfirmationModal component:** 1
- **Using custom modal (similar to ConfirmationModal):** 3
- **Using browser confirm():** 3
- **No confirmation:** 1

---

## 1. Free Blocks (FreeBlocks.vue)

**Location:** `frontend/src/components/molecules/FreeBlocks.vue`

**Delete Action:** Delete a free block

**Confirmation Dialog:**
- **Type:** `ConfirmationModal` component
- **Title:** "Block löschen"
- **Message:** `Möchtest du den Block "{block.name || 'Unbenannt'}" wirklich löschen?`
- **Confirm Button:** "Löschen" (red)
- **Cancel Button:** "Abbrechen"
- **Type:** `danger`
- **Styling:** Standard ConfirmationModal with red danger styling

**Code Reference:**
- Lines 796-804: ConfirmationModal usage
- Lines 432-442: Delete logic

---

## 2. Plans (Statistics.vue)

**Location:** `frontend/src/components/molecules/Statistics.vue`

**Delete Action:** Delete a plan

**Confirmation Dialog:**
- **Type:** `StatisticsDeleteModal` component (custom)
- **Title:** "Plan löschen?"
- **Message:** `Bist du sicher, dass du den Plan mit der ID {planId} löschen möchtest? Diese Aktion kann nicht rückgängig gemacht werden.`
- **Confirm Button:** "Löschen" (red)
- **Cancel Button:** "Abbrechen"
- **Styling:** Custom modal, similar to ConfirmationModal but simpler structure

**Code Reference:**
- Lines 877-879: StatisticsDeleteModal usage
- Lines 339-342: Open delete modal
- Lines 427-428: Delete logic

---

## 3. Orphan Cleanup (Statistics.vue)

**Location:** `frontend/src/components/molecules/Statistics.vue`

**Delete Action:** Clean up orphaned events, plans, activity groups, or activities

**Confirmation Dialog:**
- **Type:** `StatisticsDeleteModal` component (custom)
- **Title:** Dynamic based on cleanup type:
  - "Events bereinigen?"
  - "Pläne bereinigen?"
  - "Activity Groups bereinigen?"
  - "Activities bereinigen?"
- **Message:** Dynamic description based on cleanup type
- **Confirm Button:** "Bereinigen" (red)
- **Cancel Button:** "Abbrechen"
- **Styling:** Custom modal, same as plan delete

**Code Reference:**
- Lines 877-879: StatisticsDeleteModal usage
- Lines 35-63 in StatisticsDeleteModal.vue: Cleanup metadata

---

## 4. System News (SystemNews.vue)

**Location:** `frontend/src/components/molecules/SystemNews.vue`

**Delete Action:** Delete a system news item

**Confirmation Dialog:**
- **Type:** Browser `confirm()` dialog
- **Message:** `News "{title}" wirklich löschen?`
- **Styling:** Native browser dialog (not styled)

**Code Reference:**
- Lines 65-77: Delete logic with browser confirm

---

## 5. Rooms (Rooms.vue)

**Location:** `frontend/src/components/Rooms.vue`

**Delete Action:** Delete a room

**Confirmation Dialog:**
- **Type:** Custom inline modal (not using ConfirmationModal component)
- **Title:** "Raum löschen?"
- **Message:** `Bist du sicher, dass du den Raum {roomToDelete.name} löschen möchtest? Diese Aktion kann nicht rückgängig gemacht werden.`
- **Confirm Button:** "Löschen" (red bg-red-600)
- **Cancel Button:** "Abbrechen" (gray)
- **Styling:** Custom modal with similar structure to ConfirmationModal but implemented inline

**Code Reference:**
- Lines 1067-1094: Custom delete modal
- Lines 403-424: Delete logic

---

## 6. Logos (Logos.vue)

**Location:** `frontend/src/components/Logos.vue`

**Delete Action:** Delete a logo

**Confirmation Dialog:**
- **Type:** Custom inline modal (similar to ConfirmationModal but not using the component)
- **Title:** "Logo löschen"
- **Message:** `Möchten Sie das Logo "{logo.title || 'Unbenannt'}" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
- **Confirm Button:** "Löschen" (red bg-red-600)
- **Cancel Button:** "Abbrechen" (gray)
- **Styling:** Custom modal with red warning icon, similar to ConfirmationModal structure
- **Special Feature:** Shows error message if delete fails

**Code Reference:**
- Lines 622-674: Custom delete modal
- Lines 117-144: Delete logic

---

## 7. QRuns (QRunList.vue)

**Location:** `frontend/src/components/atoms/QRunList.vue`

**Delete Action:** Delete a QRun (quality run)

**Confirmation Dialog:**
- **Type:** Browser `confirm()` dialog
- **Message:** `QRun {qrunId} wirklich löschen?`
- **Styling:** Native browser dialog (not styled)

**Code Reference:**
- Lines 53-63: Delete logic with browser confirm

---

## 8. Main Tables Records (MainTablesAdmin.vue)

**Location:** `frontend/src/components/molecules/MainTablesAdmin.vue`

**Delete Action:** Delete a record from a main table (admin only, DEV only)

**Confirmation Dialog:**
- **Type:** Browser `confirm()` dialog
- **Message:** `Are you sure you want to delete this record?`
- **Styling:** Native browser dialog (not styled)
- **Note:** English message (unlike most others which are German)

**Code Reference:**
- Lines 429-440: Delete logic with browser confirm

---

## 9. Slides (SlideThumb.vue)

**Location:** `frontend/src/components/SlideThumb.vue`

**Delete Action:** Delete a slide

**Confirmation Dialog:**
- **Type:** **NONE** - No confirmation dialog
- **Behavior:** Direct delete on click
- **Note:** This is potentially dangerous as there's no confirmation

**Code Reference:**
- Lines 18-24: Delete logic (no confirmation)

---

## Recommendations

1. **Standardize on ConfirmationModal component:**
   - Rooms.vue (custom modal) → Use ConfirmationModal
   - Logos.vue (custom modal) → Use ConfirmationModal
   - StatisticsDeleteModal → Consider if it should use ConfirmationModal or if its custom structure is needed

2. **Replace browser confirm() dialogs:**
   - SystemNews.vue → Use ConfirmationModal
   - QRunList.vue → Use ConfirmationModal
   - MainTablesAdmin.vue → Use ConfirmationModal

3. **Add confirmation to SlideThumb.vue:**
   - Currently has no confirmation - should add ConfirmationModal

4. **Consistency improvements:**
   - All delete dialogs should use the same component for consistency
   - All messages should be in German (MainTablesAdmin.vue uses English)
   - Consider adding "Diese Aktion kann nicht rückgängig gemacht werden." to all delete dialogs for consistency

