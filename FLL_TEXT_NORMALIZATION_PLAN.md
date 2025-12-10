# FLL Explore/Challenge UI Text Normalization Plan

## Goals
1. Expand "FLL" to "FIRST LEGO League" with "FIRST" in italics throughout the UI
2. Use program icons consistently where there's sufficient space
3. **Do NOT add icons** if that would require extra space or break layouts
4. UI only - PDF output will be handled separately

---

## Analysis Summary

### ✅ Already Correct
These locations already use the full "FIRST LEGO League" with italic FIRST:
- `Schedule.vue` (lines 620, 654) - with icons ✅
- `ExploreSettings.vue` (line 576) - with icon ✅
- `ChallengeSettings.vue` (line 272) - with icon ✅
- `PublicEvent.vue` (lines 718, 756) - with icons ✅
- `OnlineAccessBox.vue` (lines 276, 289, 317, 346) - without icons (tight space) ✅

### ❌ Needs Fixing

#### 1. Rooms.vue
**Locations:**
- Line 132: `{ id: 'explore', name: 'FLL Explore', ... }`
- Line 133: `{ id: 'challenge', name: 'FLL Challenge', ... }`
- Line 676: `name: isExplore ? 'Alle FLL Explore Teams' : 'Alle FLL Challenge Teams'`
- Line 988: `name: group.id === 'explore' ? 'Alle FLL Explore Teams' : 'Alle FLL Challenge Teams'`

**Action:**
- Expand "FLL" to full text with italic FIRST
- Add icons if space allows (check layout context)
- **Note:** Line 676 and 988 are proxy item names - might be tight space, consider icon + abbreviated text

---

#### 2. SelectEvent.vue
**Locations:**
- Line 272: `<img> ... Explore` (checkbox label)
- Line 285: `<img> ... Challenge` (checkbox label)

**Action:**
- Icons already present ✅
- Text is just "Explore"/"Challenge" - consider if full text fits in checkbox labels
- **Decision needed:** Keep abbreviated for compact checkbox labels, or expand?

---

#### 3. MParameter.vue
**Locations:**
- Line 187: Filter checkboxes: `{value:2,label:'Explore'}, {value:3,label:'Challenge'}`
- Line 314-315: Dropdown options: `<option :value="2">Explore</option>`, `<option :value="3">Challenge</option>`

**Action:**
- Expand to full text with italic FIRST
- Dropdown might be tight - use abbreviated form if needed
- **Check:** Can dropdowns fit "FIRST LEGO League Explore/Challenge"? If not, consider icon + abbreviated

---

#### 4. FreeBlocks.vue
**Locations:**
- Line 688: `@click="... toggleProgram(b, 2)" title="Explore"`
- Line 698: `@click="... toggleProgram(b, 3)" title="Challenge"`

**Action:**
- These are tooltips (`title` attributes)
- Icons already present ✅
- Expand tooltip text to full form with italic FIRST
- **Note:** Tooltips can be longer, so full text is fine

---

#### 5. FabricEditor.vue
**Locations:**
- Line 55: `{title: 'FLL Challenge', url: ...}`
- Line 56: `{title: 'FLL Challenge', url: ...}`
- Line 57: `{title: 'FLL Explore', url: ...}`
- Line 58: `{title: 'FLL Explore', url: ...}`
- Line 59: `{title: 'FLL Explore', url: ...}`

**Action:**
- These are image titles shown in UI (likely tooltips or labels)
- Expand to full text with italic FIRST
- **Note:** Also lines 51-52 show "First LEGO League" - should be "FIRST" (all caps)

---

#### 6. PublicPlanSlideContentRenderer.vue
**Locations:**
- Line 136: `alt="Explore"`
- Line 142: `alt="Challenge"`

**Action:**
- Icons already present ✅
- Expand alt text to descriptive form
- Use: `alt="FIRST LEGO League Explore Logo"` (or similar descriptive text)
- **Note:** Alt text should be descriptive, not just "Explore"

---

#### 7. utils/images.ts
**Locations:**
- Line 27: `return 'Logo Explore'`
- Line 28: `return 'Logo Challenge'`

**Action:**
- Expand to full descriptive form
- Use: `return 'FIRST LEGO League Explore Logo'` / `'FIRST LEGO League Challenge Logo'`

---

#### 8. Schedule.vue
**Locations:**
- Line 640: `"Explore ist deaktiviert"`
- Line 696: `"Challenge ist deaktiviert"`

**Action:**
- Consider expanding for consistency
- **Decision needed:** Full text might be too verbose in error messages

---

#### 9. ExploreSettings.vue
**Locations:**
- Line 688: `"um Explore-Einstellungen zu konfigurieren"`
- Line 640: `"Explore ist deaktiviert"`

**Action:**
- Consider expanding for consistency
- **Decision needed:** Full text might be too verbose in instructional text

---

#### 10. ChallengeSettings.vue
**Locations:**
- Line 395: `"um Challenge-Einstellungen zu konfigurieren"`
- Line 394: `"Challenge ist deaktiviert"`

**Action:**
- Consider expanding for consistency
- **Decision needed:** Full text might be too verbose in instructional text

---

## Implementation Strategy

### Phase 1: High Priority (User-facing labels)
1. **Rooms.vue** - Group names and proxy item names (most visible)
2. **FabricEditor.vue** - Image titles (user selects these)
3. **PublicPlanSlideContentRenderer.vue** - Alt text (accessibility)
4. **utils/images.ts** - Alt text functions (used throughout)

### Phase 2: Medium Priority (Form controls)
5. **MParameter.vue** - Dropdown options and filter labels
6. **FreeBlocks.vue** - Tooltips (hover text)

### Phase 3: Low Priority (Internal/contextual)
7. **SelectEvent.vue** - Checkbox labels (might stay abbreviated)
8. **Schedule.vue / ExploreSettings.vue / ChallengeSettings.vue** - Error/info messages (might stay abbreviated)

---

## Icon Usage Guidelines

### ✅ Add Icons Where:
- Already have icon infrastructure (programLogoSrc function exists)
- Space is available (e.g., headings, labels with flex layout)
- Icons don't increase component width (inline with text)

### ❌ Don't Add Icons Where:
- Would break compact layouts (dropdowns, checkboxes)
- Already icon-only contexts (FabricEditor image selection)
- Error messages or tight inline text

---

## Text Format Rules

### Full Form:
```html
<span class="italic">FIRST</span> LEGO League Explore
<span class="italic">FIRST</span> LEGO League Challenge
```

### Abbreviated Form (only if space constrained):
```html
Explore
Challenge
```
**Note:** Use abbreviated only if absolutely necessary and context makes it clear.

---

## Files to Modify

1. `frontend/src/components/Rooms.vue`
2. `frontend/src/components/SelectEvent.vue` (if expanding)
3. `frontend/src/components/molecules/MParameter.vue`
4. `frontend/src/components/molecules/FreeBlocks.vue`
5. `frontend/src/components/FabricEditor.vue`
6. `frontend/src/components/slideTypes/PublicPlanSlideContentRenderer.vue`
7. `frontend/src/utils/images.ts`
8. `frontend/src/components/Schedule.vue` (optional, decision needed)
9. `frontend/src/components/molecules/ExploreSettings.vue` (optional, decision needed)
10. `frontend/src/components/molecules/ChallengeSettings.vue` (optional, decision needed)

---

## Questions for Discussion

1. **SelectEvent.vue checkboxes:** Keep abbreviated "Explore"/"Challenge" or expand to full text?
2. **Error/Info messages:** Expand "Explore ist deaktiviert" or keep abbreviated?
3. **MParameter dropdowns:** Can they fit full text, or use icon + abbreviated?
4. **Rooms proxy items:** Full text with icon, or abbreviated for compact display?
