# Name Tag PDF Implementation Plan

## Overview
Create a PDF export feature for printing name tags on Avery Zweckform L4785 label sheets. Each name tag contains:
- Person's name (large)
- Team name (smaller, below name)
- Program logo (Explore or Challenge)
- Season logo
- Organizer/Event logos

## Requirements

### Label Sheet Specifications (Avery L4785)
- **Page**: DIN A4 (297mm × 210mm)
- **Margins**: 
  - Top: 13.5mm
  - Bottom: 13.5mm
  - Left: 17.5mm
  - Right: 17.5mm
- **Layout**: 2 columns
- **Column spacing**: 15mm
- **Label size**: 50mm height × 80mm width
- **Vertical spacing between labels**: 5mm
- **Label padding**:
  - Top: 5mm
  - Left: 2mm
  - Right: 2mm
  - Content width: 76mm

### Content per Name Tag
1. **Person name** (large font, top)
2. **Team name** (smaller font, below name)
3. **Program logo** (Explore or Challenge) - based on team's `first_program`
4. **Season logo** - from event's season
5. **Organizer logos** - from event's `event_logo` table (same as footer logos)

## Implementation Steps

### 1. Backend Controller Method
**File**: `backend/app/Http/Controllers/Api/PlanExportController.php`

**Method**: `nameTagsPdf(int $eventId)`

**Logic**:
1. Get event data
2. Get all teams for the event (both Explore and Challenge)
3. For each team:
   - Fetch team members from DRAHT API (`/handson/flow/{drahtEventId}/people`)
   - Get players and coaches
   - For each person, create a name tag entry with:
     - Person name (from `name` + `firstname` fields)
     - Team name
     - Program (Explore/Challenge) - determine from team's `first_program`
     - Program logo path
     - Season logo path
     - Organizer logos (from `buildFooterLogos()`)
4. Generate HTML using Blade template
5. Generate PDF (portrait, A4)
6. Return PDF with proper headers

**Data Structure**:
```php
$nameTags = [
    [
        'person_name' => 'John Doe',
        'team_name' => 'Team Awesome',
        'program' => 'explore', // or 'challenge'
        'program_logo' => 'data:image/png;base64,...',
        'season_logo' => 'data:image/png;base64,...',
        'organizer_logos' => ['data:image/png;base64,...', ...]
    ],
    // ... more name tags
]
```

### 2. Blade Template
**File**: `backend/resources/views/pdf/name-tags.blade.php`

**Layout**:
- Use CSS Grid or absolute positioning for precise label placement
- Calculate positions based on:
  - Page margins
  - Label size (50mm × 80mm)
  - Column spacing (15mm)
  - Vertical spacing (5mm)
- Each label contains:
  - Person name (large, bold)
  - Team name (smaller, below)
  - Logos row at bottom (program, season, organizer logos)

**CSS Calculations**:
```css
/* Page setup */
@page {
    size: A4 portrait;
    margin: 13.5mm 17.5mm;
}

/* Label dimensions */
.label {
    width: 80mm;
    height: 50mm;
    padding: 5mm 2mm;
}

/* Column 1: left margin + label width */
/* Column 2: left margin + label width + column spacing + label width */
```

### 3. DRAHT API Integration
**Endpoint**: `/handson/flow/{drahtEventId}/people`

**Usage**:
- For each team, determine if it's Explore or Challenge
- Get corresponding `event_explore` or `event_challenge` ID
- Call DRAHT API to get people data
- Extract players and coaches
- Combine into single list for name tags

**Data Structure from DRAHT**:
```json
{
  "team_number": {
    "name": "Team Name",
    "players": [
      {"name": "Last", "firstname": "First", ...}
    ],
    "coaches": [
      {"name": "Coach Name", "email": "...", ...}
    ]
  }
}
```

### 4. Logo Retrieval

**Program Logos**:
- Explore: `public_path('flow/fll_explore_hs.png')`
- Challenge: `public_path('flow/fll_challenge_hs.png')`
- Convert to data URI using `PdfLayoutService::toDataUri()`

**Season Logo**:
- Get event's season from `event.season`
- Look up season in `m_season` table
- Determine season logo filename (e.g., `season_unearthed_v.png`)
- Load from `public_path('flow/{season_logo}')`
- Convert to data URI

**Organizer Logos**:
- Use existing `PdfLayoutService::buildFooterLogos($eventId)`
- Returns array of data URIs

### 5. Route
**File**: `backend/routes/api.php`

**Add**:
```php
Route::get('/export/name-tags/{eventId}', [PlanExportController::class, 'nameTagsPdf']);
```

### 6. Frontend Integration (Optional)
**File**: `frontend/src/components/molecules/PdfPlansBox.vue` or similar

**Add button/link**:
- Use existing `usePdfExport` composable
- Call `/export/name-tags/{eventId}`

## Technical Details

### Label Grid Calculation
- **Usable width**: 210mm - 17.5mm - 17.5mm = 175mm
- **Usable height**: 297mm - 13.5mm - 13.5mm = 270mm
- **Column 1 X**: 17.5mm (left margin)
- **Column 2 X**: 17.5mm + 80mm + 15mm = 112.5mm
- **Labels per column**: floor((270mm - 50mm) / (50mm + 5mm)) + 1 = floor(220 / 55) + 1 = 4 labels
- **Total labels per page**: 2 columns × 4 labels = 8 labels

### CSS Positioning Strategy
Use absolute positioning with calculated offsets:
```css
.label {
    position: absolute;
    width: 80mm;
    height: 50mm;
}

.label-col1-row1 { top: 13.5mm; left: 17.5mm; }
.label-col1-row2 { top: 68.5mm; left: 17.5mm; } /* 13.5 + 50 + 5 */
.label-col1-row3 { top: 123.5mm; left: 17.5mm; } /* 13.5 + 50 + 5 + 50 + 5 */
.label-col1-row4 { top: 178.5mm; left: 17.5mm; }
.label-col2-row1 { top: 13.5mm; left: 112.5mm; }
/* ... etc */
```

### Font Sizes
- **Person name**: ~18-20px (large, bold)
- **Team name**: ~12-14px (smaller, regular)
- **Logo size**: ~15-20mm height (proportional width)

## Files to Create/Modify

### New Files
1. `backend/resources/views/pdf/name-tags.blade.php` - Main template
2. `backend/resources/views/pdf/content/name-tag-label.blade.php` - Individual label template (optional, for reusability)

### Modified Files
1. `backend/app/Http/Controllers/Api/PlanExportController.php` - Add `nameTagsPdf()` method
2. `backend/routes/api.php` - Add route
3. `frontend/src/components/molecules/PdfPlansBox.vue` - Add UI button (optional)

## Testing Checklist
- [ ] Test with Explore teams only
- [ ] Test with Challenge teams only
- [ ] Test with mixed Explore/Challenge teams
- [ ] Test with teams that have no members
- [ ] Test with teams that have many members
- [ ] Verify label positioning matches Avery L4785 sheet
- [ ] Verify all logos display correctly
- [ ] Test PDF generation performance with large number of teams
- [ ] Verify person names are formatted correctly (firstname + name)
- [ ] Test with teams missing DRAHT data (graceful handling)

## Edge Cases to Handle
1. **Missing DRAHT data**: If API call fails, skip that team or show error
2. **No team members**: Skip teams with no players/coaches
3. **Missing logos**: Handle missing logo files gracefully (skip or show placeholder)
4. **Long names**: Truncate or wrap long person/team names
5. **Many pages**: Ensure pagination works correctly
6. **Mixed programs**: Handle teams from both Explore and Challenge in same event

## Future Enhancements
- Filter by program (only Explore or only Challenge)
- Filter by specific teams
- Include/exclude coaches option
- Custom label size support
- Preview before download
