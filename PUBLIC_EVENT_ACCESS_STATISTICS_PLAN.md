# One Link Access Statistics - Implementation Plan

## Overview

This document outlines the implementation plan for tracking public event page access statistics via the "one link" (slug-based URLs). The system will record detailed access information for the `PublicEvent.vue` component, similar to how generator runs are tracked in `s_generator`.

## Goals

1. Track every access to public event pages (via slug-based URLs)
2. Collect metadata about each access (device, screen size, connection type, etc.)
3. Differentiate between QR code scans, direct entries, and referrer-based visits
4. Provide statistics on access counts per event per day
5. Display statistics in the admin Statistics component

## Current State

### Public Event Access Flow
- Users access events via slug-based URLs: `/{slug}` (e.g., `/my-event-2024`)
- Route is defined in `frontend/src/main.js`: `{path: '/:slug', component: PublicEvent, props: true, meta: {public: true}}`
- Component loads event data via `/events/slug/{slug}` endpoint
- No authentication required (public route)
- QR codes are generated in `PublishController::linkAndQRcode()` method

### Existing Statistics Pattern
- Generator runs are tracked in `s_generator` table
- One row per generator run
- Statistics displayed in `Statistics.vue` component
- Timeline chart shows generator runs per day

## Requirements

### Functional Requirements
1. Log every successful page load (after event is found)
2. Track access source: QR code, direct entry, or referrer
3. Capture device/browser metadata (user agent, screen size, etc.)
4. Aggregate statistics by event and date
5. Display statistics in admin UI

### Non-Functional Requirements
1. Silent failure - logging errors must not disrupt user experience
2. No authentication required for logging endpoint
3. Handle thousands of accesses per day efficiently
4. Keep displayed links short and easy to type
5. QR code URLs should include `?source=qr` parameter (not displayed link)

## Database Schema

### New Table: `s_one_link_access`

```sql
CREATE TABLE `s_one_link_access` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event` INT(10) UNSIGNED NOT NULL,
  `access_date` DATE NOT NULL,
  `access_time` TIMESTAMP NULL DEFAULT NULL,
  
  -- Server-side captured (from HTTP request)
  `user_agent` TEXT NULL,
  `referrer` TEXT NULL,
  `ip_hash` VARCHAR(64) NULL,
  `accept_language` VARCHAR(50) NULL,
  
  -- Client-side captured (sent from frontend)
  `screen_width` SMALLINT(5) UNSIGNED NULL,
  `screen_height` SMALLINT(5) UNSIGNED NULL,
  `viewport_width` SMALLINT(5) UNSIGNED NULL,
  `viewport_height` SMALLINT(5) UNSIGNED NULL,
  `device_pixel_ratio` DECIMAL(3,2) NULL,
  `touch_support` TINYINT(1) NULL,
  `connection_type` VARCHAR(20) NULL,
  
  -- Source tracking
  `source` VARCHAR(20) NULL, -- 'qr', 'direct', 'referrer', 'unknown'
  
  PRIMARY KEY (`id`),
  INDEX `idx_event_access_date` (`event`, `access_date`),
  INDEX `idx_access_date` (`access_date`),
  FOREIGN KEY (`event`) REFERENCES `event` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Field Descriptions

**Core Fields:**
- `id`: Auto-increment primary key
- `event`: Foreign key to `event.id` (not slug, for data integrity)
- `access_date`: Date of access (for daily aggregation)
- `access_time`: Exact timestamp of access (for monitoring usage over day of event)

**Server-side Fields (from HTTP request):**
- `user_agent`: Full user agent string (browser, OS, device info)
- `referrer`: HTTP Referer header (where user came from)
- `ip_hash`: SHA-256 hash of IP address (privacy-friendly)
- `accept_language`: Language preferences from Accept-Language header

**Client-side Fields (from JavaScript):**
- `screen_width/height`: Physical screen dimensions
- `viewport_width/height`: Browser viewport dimensions
- `device_pixel_ratio`: Device pixel ratio (e.g., 2.0, 3.0)
- `touch_support`: Boolean - device supports touch
- `connection_type`: Network connection type ('wifi', 'cellular', 'ethernet', etc.)

**Source Tracking:**
- `source`: How user arrived at page ('qr', 'direct', 'referrer', 'unknown')

## Implementation Steps

### Step 1: Database Migration

**File:** `backend/database/migrations/YYYY_MM_DD_HHMMSS_create_s_one_link_access_table.php`

**Actions:**
1. Create migration file using `php artisan make:migration`
2. Define table structure as above
3. Add foreign key constraint to `event.id` with `ON DELETE CASCADE`
4. Add indexes for efficient queries
5. Test migration up/down

**Dependencies:** None

**Risk:** Low - new table, no existing data

---

### Step 2: Create Eloquent Model

**File:** `backend/app/Models/OneLinkAccess.php`

**Actions:**
1. Create model class extending `Illuminate\Database\Eloquent\Model`
2. Set `$table = 's_one_link_access'`
3. Define `$fillable` array with all fields
4. Set `public $timestamps = false` (we use `access_time` manually)
5. Add relationship: `event(): BelongsTo`

**Code Structure:**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneLinkAccess extends Model
{
    protected $table = 's_one_link_access';
    
    public $timestamps = false;
    
    protected $fillable = [
        'event',
        'access_date',
        'access_time',
        'user_agent',
        'referrer',
        'ip_hash',
        'accept_language',
        'screen_width',
        'screen_height',
        'viewport_width',
        'viewport_height',
        'device_pixel_ratio',
        'touch_support',
        'connection_type',
        'source',
    ];
    
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event');
    }
}
```

**Dependencies:** Step 1 (migration)

**Risk:** Low

---

### Step 3: Add API Endpoint for Logging

**File:** `backend/app/Http/Controllers/Api/PublishController.php`

**Actions:**
1. Add `logOneLinkAccess(Request $request): JsonResponse` method to existing controller
3. Validate `event_id` exists in database
4. Extract server-side data from request:
   - User agent: `$request->userAgent()`
   - Referrer: `$request->header('referer')`
   - IP hash: `hash('sha256', $request->ip() . config('app.key'))`
   - Accept-Language: `$request->header('accept-language')`
5. Extract client-side data from request body
6. Determine source:
   - If `source` parameter = 'qr' → 'qr'
   - Else if referrer present → 'referrer'
   - Else → 'direct'
7. Insert record into database with `access_time = Carbon::now()`
8. Return success response
9. Handle errors gracefully (log but don't fail)

**Route:** `POST /api/one-link-access`

**Request Body:**
```json
{
  "event_id": 123,
  "source": "qr",  // Optional, from query parameter
  "screen_width": 375,
  "screen_height": 667,
  "viewport_width": 375,
  "viewport_height": 667,
  "device_pixel_ratio": 2.0,
  "touch_support": true,
  "connection_type": "wifi"
}
```

**Response:**
```json
{
  "success": true
}
```

**Error Handling:**
- Invalid event_id → 400 Bad Request
- Database errors → 500 Internal Server Error (logged)
- All errors should be logged but not disrupt user experience

**Dependencies:** Step 1, Step 2

**Risk:** Low - new endpoint, no breaking changes

---

### Step 4: Update QR Code Generation

**File:** `backend/app/Http/Controllers/Api/PublishController.php`

**Method:** `linkAndQRcode($eventId)`

**Actions:**
1. Locate where QR code URL is generated
2. Create two versions of the link:
   - **Display link** (stored in DB): Short, clean URL without query parameters
   - **QR code link** (for QR code only): Same URL + `?source=qr` parameter
3. Generate QR code using the QR code link (with `?source=qr`)
4. Store display link in database (without `?source=qr`)
5. Return both links in response if needed

**Current Code Location:**
```php
// Around line 107
$link = config('app.frontend_url') . "/" . $slug;
```

**Modified Code:**
```php
// Display link (stored in DB, shown to users)
$displayLink = config('app.frontend_url') . "/" . $slug;

// QR code link (includes source parameter)
$qrCodeLink = $displayLink . "?source=qr";

// Generate QR code using qrCodeLink
$qrCode = new QrCode($qrCodeLink, ...);

// Store displayLink in database
DB::table('event')->where('id', $eventId)->update([
    'slug' => $slug,
    'link' => $displayLink,  // Clean link without ?source=qr
    'qrcode' => $qrcodeRaw,
]);
```

**Dependencies:** None (independent change)

**Risk:** Low - only affects QR code generation, display link stays clean

---

### Step 5: Frontend Integration - PublicEvent.vue

**File:** `frontend/src/components/PublicEvent.vue`

**Location:** In `loadEvent()` function, immediately after event is successfully loaded

**Actions:**
1. Extract `source` from route query parameter (`route.query.source`)
2. Collect client-side data:
   - Screen dimensions: `window.screen.width/height`
   - Viewport dimensions: `window.innerWidth/Height`
   - Device pixel ratio: `window.devicePixelRatio`
   - Touch support: `'ontouchstart' in window || navigator.maxTouchPoints > 0`
   - Connection type: `navigator.connection?.effectiveType || navigator.connection?.type`
3. Determine source if not provided:
   - If `route.query.source === 'qr'` → 'qr'
   - Else if `document.referrer` exists → 'referrer'
   - Else → 'direct'
4. Call logging endpoint with all data
5. Wrap in try-catch for silent failure
6. **Important:** Log immediately after event is loaded, regardless of subsequent operations (plan fetch, logos, etc.)

**Code Location:**
```javascript
// After line 41: event.value = eventResponse.data
// Add logging call here - BEFORE schedule info and plan fetch
```

**Implementation:**
```javascript
const loadEvent = async () => {
  try {
    loading.value = true
    error.value = null

    // Load event by slug
    const eventResponse = await axios.get(`/events/slug/${route.params.slug}`)
    event.value = eventResponse.data

    // ✅ LOG ACCESS IMMEDIATELY AFTER EVENT IS LOADED
    // This works for ALL levels (1-4), including level 4 with iframe
    // Log before schedule info fetch so we capture access even if that fails
    try {
      // Determine source
      let source = 'unknown';
      if (route.query.source === 'qr') {
        source = 'qr';
      } else if (document.referrer) {
        source = 'referrer';
      } else {
        source = 'direct';
      }

      // Collect client-side data
      const clientData = {
        event_id: event.value.id,
        source: source,
        screen_width: window.screen.width,
        screen_height: window.screen.height,
        viewport_width: window.innerWidth,
        viewport_height: window.innerHeight,
        device_pixel_ratio: window.devicePixelRatio || 1,
        touch_support: 'ontouchstart' in window || navigator.maxTouchPoints > 0,
        connection_type: navigator.connection?.effectiveType || 
                         navigator.connection?.type || 
                         null
      };

      // Log access (fire and forget - don't await)
      axios.post('/one-link-access', clientData).catch(err => {
        console.error('Failed to log access:', err);
        // Silent failure - don't disrupt user experience
      });
    } catch (err) {
      // Silent failure - don't prevent page from loading
      console.error('Error preparing access log:', err);
    }

    // Load schedule information with publication level
    const scheduleResponse = await axios.get(`/publish/public-information/${event.value.id}`)
    scheduleInfo.value = scheduleResponse.data

    // If level 4, fetch plan ID for embedding (no redirect)
    if (scheduleInfo.value?.level === 4) {
      try {
        const planResponse = await axios.get(`/plans/public/${event.value.id}`)
        publicPlanId.value = planResponse.data.id
      } catch (planError) {
        // Plan fetch failed, but access was already logged above
        // This is correct - user accessed the page even if iframe content fails
        console.error('Error fetching plan ID:', planError)
        if (planError.response?.status === 404) {
          error.value = 'Plan nicht gefunden'
        } else {
          console.warn('Plan fetch failed, but continuing with page display')
        }
      }
    }

    // Load logos for the event
    try {
      const logosResponse = await axios.get(`/events/${event.value.id}/logos`)
      eventLogos.value = logosResponse.data
    } catch (logoError) {
      console.error('Error fetching logos:', logoError)
      eventLogos.value = []
    }

  } catch (err) {
    console.error('Error loading event:', err)
    error.value = err.response?.data?.error || 'Fehler beim Laden der Veranstaltung'
  } finally {
    loading.value = false
  }
}
```

**Level 4 Iframe Compatibility:**
- ✅ **Works correctly:** Logging happens in parent component before iframe renders
- ✅ **No double counting:** Iframe is separate document and doesn't trigger logging
- ✅ **Handles failures:** Access logged even if plan fetch fails (user still accessed page)
- ✅ **Client data correct:** Collected from parent window, not iframe (which is correct)
- ✅ **Source tracking:** `?source=qr` parameter is in parent URL, correctly captured

**Dependencies:** Step 3 (API endpoint)

**Risk:** Low - silent failure, doesn't affect existing functionality

---

### Step 6: Add Statistics Endpoints

**File:** `backend/app/Http/Controllers/Api/StatisticController.php`

**Method 1:** `oneLinkAccess(): JsonResponse` - List all accesses (for table)

**Actions:**
1. Query `s_one_link_access` table
2. Join with `event` table to get slug and name
3. Group by event slug, event name, and access_date
4. Count accesses per group
5. Calculate total per event (sum across all dates)
6. Order by date (descending) then slug
7. Return JSON response

**Route:** `GET /api/stats/one-link-access`

**Response Format:**
```json
{
  "accesses": [
    {
      "event_id": 123,
      "slug": "my-event-2024",
      "event_name": "My Event 2024",
      "total_count": 150
    },
    {
      "event_id": 124,
      "slug": "another-event",
      "event_name": "Another Event",
      "total_count": 89
    }
  ]
}
```

**Query:**
```php
$accesses = DB::table('s_one_link_access as ola')
    ->join('event', 'event.id', '=', 'ola.event')
    ->select(
        'event.id as event_id',
        'event.slug',
        'event.name as event_name',
        DB::raw('COUNT(*) as total_count')
    )
    ->groupBy('event.id', 'event.slug', 'event.name')
    ->orderBy('total_count', 'desc')
    ->get();
```

**Method 2:** `oneLinkAccessChart(int $eventId): JsonResponse` - Chart data

**Actions:**
1. Get event and plan data (for date range and publication levels)
2. Query daily aggregated access counts (for timeline view)
3. Query 15-minute interval access counts for event day(s) (for day view)
   - **Single-day events:** 6:00-20:55 on event date (60 intervals)
   - **Multi-day events:** ONE continuous interval from 6:00 on first day through 20:55 on last day
     - Includes ALL 15-minute intervals (including night hours 21:00-5:59)
     - Calculate start: `event.date 06:00:00`
     - Calculate end: `event.date + (event.days - 1) days 20:55:00`
     - Generate all 15-minute intervals between start and end
4. Get publication level intervals (same as timeline chart)
5. Return JSON with both views' data

**Route:** `GET /api/stats/one-link-access/{eventId}`

**Response Format:**
```json
{
  "start_date": "2025-01-01",
  "end_date": "2025-01-20",
  "event_date": "2025-01-20",
  "event_days": 1,
  "daily_data": [
    {
      "date": "2025-01-15",
      "access_count": 42
    }
  ],
  "event_day_intervals": [
    {
      "datetime": "2025-01-20 06:00:00",
      "time": "06:00",
      "access_count": 0
    },
    {
      "datetime": "2025-01-20 06:15:00",
      "time": "06:15",
      "access_count": 2
    },
    {
      "datetime": "2025-01-20 20:45:00",
      "time": "20:45",
      "access_count": 5
    },
    {
      "datetime": "2025-01-20 20:55:00",
      "time": "20:55",
      "access_count": 3
    }
  ],
  "publication_intervals": [
    {
      "level": 1,
      "start_date": "2025-01-01",
      "end_date": "2025-01-10"
    }
  ]
}
```

**Note:** For multi-day events, `event_day_intervals` is ONE continuous interval:
- **Start:** 6:00 on first day (`event.date 06:00:00`)
- **End:** 20:55 on last day (`event.date + (event.days - 1) days 20:55:00`)
- **Includes ALL 15-minute intervals** between start and end, including:
  - Day hours (6:00-20:55) on all days
  - Night hours (21:00-23:45 and 00:00-05:45) between days
- **Example for 2-day event (Jan 20-21, event.days = 2):**
  ```json
  "event_day_intervals": [
    {"datetime": "2025-01-20 06:00:00", "time": "06:00", "access_count": 0},
    {"datetime": "2025-01-20 06:15:00", "time": "06:15", "access_count": 2},
    ...
    {"datetime": "2025-01-20 20:55:00", "time": "20:55", "access_count": 3},
    {"datetime": "2025-01-20 21:00:00", "time": "21:00", "access_count": 1},
    {"datetime": "2025-01-20 21:15:00", "time": "21:15", "access_count": 0},
    ...
    {"datetime": "2025-01-20 23:45:00", "time": "23:45", "access_count": 0},
    {"datetime": "2025-01-21 00:00:00", "time": "00:00", "access_count": 0},
    {"datetime": "2025-01-21 00:15:00", "time": "00:15", "access_count": 0},
    ...
    {"datetime": "2025-01-21 05:45:00", "time": "05:45", "access_count": 0},
    {"datetime": "2025-01-21 06:00:00", "time": "06:00", "access_count": 5},
    ...
    {"datetime": "2025-01-21 20:55:00", "time": "20:55", "access_count": 2}
  ]
  ```
  - Total: 156 intervals (all 15-minute intervals from Jan 20 06:00 to Jan 21 20:55)
- **Calculation:** `(end_datetime - start_datetime) / 15 minutes` intervals

**Dependencies:** Step 1, Step 2

**Risk:** Low - read-only endpoints

---

### Step 7: Add Statistics Display to Statistics.vue

**File:** `frontend/src/components/molecules/Statistics.vue`

**Actions:**
1. Add new **rightmost column** to the statistics table
2. Column header: **"Zugriffe"**
3. Fetch access statistics from `/stats/one-link-access` endpoint
4. Display **total access count** per event (sum of all days)
5. Format similar to generator stats column
6. Add clickable button/icon to open chart modal (similar to timeline chart)

**Chart Modal (Similar to TimelineChart):**
- Create new component: `OneLinkAccessChart.vue` (similar to `TimelineChart.vue`)
- **Default view:** Timeline chart showing:
  - **Left y-axis:** Access count per day (orange bars, dynamic scaling)
  - **Right y-axis:** Publication level per day (blue horizontal lines, fixed 0-4 scale)
  - **X-axis:** Dates from plan creation to event date
- **Toggle view:** Switch to "Day of Event" view:
  - **X-axis:** Time of day (15-minute intervals)
  - **Y-axis:** Access count (orange bars)
  - **No publication level** in this view
  - **Single-day events:** Shows 6:00-20:55 on event date (60 intervals)
  - **Multi-day events:** ONE continuous interval from 6:00 on first day through 20:55 on last day
    - Includes ALL 15-minute intervals, including night hours (21:00-5:59)
    - Example for 2-day event: 6:00 (day 1) → 23:45 (day 1) → 00:00 (night) → 05:45 (night) → 6:00 (day 2) → 20:55 (day 2)
    - Total intervals = (days * 24 * 4) - (first day: 5:45-5:59 skipped) - (last day: 20:56-23:59 skipped)
    - Or more simply: from 6:00 first day to 20:55 last day, all 15-minute intervals included
  - **Label format:** Show time with date if needed (e.g., "06:00", "23:45", "00:00", "20:55") on x-axis

**Data Structure:**
- Store access data in component state
- Map by event_id for quick lookup
- For chart: fetch daily aggregated data and hourly/minute data for event day

**UI Design:**
- Column shows total access count (number)
- Click opens modal with chart
- Chart has toggle button to switch between "Full Timeline" and "Day of Event" views
- Similar styling to existing TimelineChart component

**Dependencies:** Step 6 (statistics endpoint)

**Risk:** Low - additive feature, doesn't break existing functionality

---

## API Routes

### New Routes

**File:** `backend/routes/api.php`

```php
// One link access logging (no auth required)
Route::post('/one-link-access', [PublishController::class, 'logOneLinkAccess']);

// Statistics endpoints (requires auth)
Route::get('/stats/one-link-access', [StatisticController::class, 'oneLinkAccess']);
Route::get('/stats/one-link-access/{eventId}', [StatisticController::class, 'oneLinkAccessChart']);
```

---

## Data Flow

### Access Logging Flow

1. User visits `/{slug}` or `/{slug}?source=qr`
2. `PublicEvent.vue` component loads
3. Component fetches event data via `/events/slug/{slug}`
4. **Immediately after event is loaded**, component collects client-side data
5. Component calls `POST /api/one-link-access` with:
   - `event_id` (from loaded event)
   - `source` (from query param or determined)
   - Client-side metadata (screen size, etc.)
6. Backend extracts server-side data (IP, user agent, referrer)
7. Backend hashes IP for privacy
8. Backend inserts record into `s_one_link_access` with `access_time = now()`
9. Response returned (success or error, silently handled)
10. **For level 4:** Iframe renders separately after logging completes (no impact on logging)

**Note:** Logging happens before schedule info fetch and plan fetch, ensuring access is recorded even if subsequent operations fail. This is especially important for level 4, where plan fetch might fail but the user still accessed the page.

### Statistics Viewing Flow

1. Admin opens Statistics page
2. Component fetches `/stats/one-link-access`
3. Backend queries `s_one_link_access` grouped by event and date
4. Returns aggregated counts
5. Frontend displays in table or chart

---

## Testing Strategy

### Unit Tests
1. Test model relationships
2. Test controller validation
3. Test source determination logic

### Integration Tests
1. Test API endpoint with valid/invalid data
2. Test QR code generation includes `?source=qr`
3. Test display link does NOT include `?source=qr`
4. Test statistics endpoint returns correct data

### Manual Testing
1. Access event page directly → should log as 'direct'
2. Access via QR code → should log as 'qr'
3. Access via referrer → should log as 'referrer'
4. Verify statistics display in admin UI
5. Test with missing client-side data (graceful degradation)

---

## Performance Considerations

### Database Performance
- **Indexes:** Critical for efficient queries
  - `(event, access_date)` - for event-specific queries
  - `(access_date)` - for date-based queries
- **Volume:** Expected thousands of accesses per day
  - 10,000 accesses/day ≈ 0.12 inserts/second (trivial)
  - 100,000 accesses/day ≈ 1.2 inserts/second (still manageable)
- **No optimization needed initially** - MySQL handles this easily

### API Performance
- Logging endpoint should be fast (< 100ms)
- Use database transactions if needed
- Consider async logging if volume becomes issue (future)

### Frontend Performance
- Logging call is fire-and-forget (non-blocking)
- No impact on page load time
- Silent failure ensures no user disruption

---

## Security Considerations

### Privacy
- **IP Hashing:** IP addresses are hashed with app key (SHA-256)
- **No PII:** No personally identifiable information stored
- **User Agent:** Contains device info but not user identity

### Access Control
- Logging endpoint: **Public** (no auth required)
- Statistics endpoint: **Protected** (requires authentication)

### Rate Limiting
- Not implemented initially
- Can be added later if abuse detected
- Consider per-IP rate limiting if needed

---

## Migration Strategy

### Deployment Steps
1. Run database migration (creates new table)
2. Deploy backend changes (controller, model, routes)
3. Deploy frontend changes (PublicEvent.vue)
4. Update QR code generation (regenerate existing QR codes if needed)
5. Verify logging works
6. Add statistics display

### Rollback Plan
1. Remove frontend logging call
2. Remove API routes
3. Drop `s_one_link_access` table (if needed)
4. Revert QR code generation changes

### Data Migration
- No data migration needed (new feature)
- Existing QR codes will continue to work (just won't be tracked as 'qr' source until regenerated)

---

## Future Enhancements

### Potential Additions
1. **Real-time statistics:** WebSocket updates for live access counts
2. **Geographic analysis:** Add country detection from IP (privacy-friendly)
3. **Device parsing:** Parse user agent to extract device model, OS version
4. **Access patterns:** Detect unusual patterns (bot detection)
5. **Export functionality:** Export statistics to CSV/Excel
6. **Charts/Visualizations:** Timeline charts similar to generator runs
7. **Filtering:** Filter statistics by date range, event, source type

### Performance Optimizations (if needed)
1. **Batch inserts:** Collect multiple accesses and insert in batches
2. **Queue system:** Use Laravel queues for async logging
3. **Aggregation table:** Pre-aggregate daily counts for faster queries
4. **Partitioning:** Partition table by date for very large datasets

---

## Level 4 Iframe Compatibility

### Analysis

The public event page supports level 4 publication, which displays the full schedule in an embedded iframe. The logging implementation is fully compatible with this feature:

**How it works:**
1. Component lifecycle: `loadEvent()` is called in `onMounted()`
2. Event is loaded first, then schedule info, then (if level 4) plan ID
3. **Logging happens immediately after event is loaded** (before iframe renders)
4. Iframe is a separate document (`/output/zeitplan.cgi`) and doesn't affect parent component

**Key Points:**
- ✅ Logging occurs in parent component, not iframe
- ✅ Works for all levels (1-4), including level 4 with iframe
- ✅ Access is logged even if plan fetch fails (user still accessed page)
- ✅ Client-side data (screen size, etc.) collected from parent window (correct)
- ✅ Source parameter (`?source=qr`) is in parent URL, correctly captured
- ✅ No double counting: iframe doesn't trigger separate logging

**Edge Cases Handled:**
- Plan fetch fails (404): Access still logged (correct - user accessed page)
- Iframe fails to load: Access still logged (correct - page was accessed)
- Level 4 with successful plan: Access logged, iframe renders normally
- Level 1-3: Access logged, normal event page displays

**Conclusion:** No special handling needed for level 4. The logging placement ensures it works correctly for all publication levels.

---

## Open Questions / Decisions Needed

### Decisions Made

1. **"Day of Event" View - Time Range:**
   - **Single-day events:** 6:00 to 20:55 (60 intervals of 15 minutes) - skips the night to save space
   - **Multi-day events:** ONE continuous interval from 6:00 on first day through 20:55 on last day - includes ALL intervals including nights
   - This ensures charts can be compared (single-day shows day hours only, multi-day shows full continuous timeline)

2. **"Day of Event" View - Multi-day Events:**
   - **Decision:** ONE continuous interval from 6:00 on first day through 20:55 on last day
   - Includes ALL 15-minute intervals, including night hours (21:00-5:59)
   - Full details through the night (not just day hours)
   - For `event.days = 1`: Shows 6:00-20:55 on event date (60 intervals)
   - For `event.days > 1`: Shows continuous timeline from 6:00 (first day) through 20:55 (last day), including all night hours

3. **Chart Data Endpoint:**
   - **Decision:** New endpoint `/stats/one-link-access/{eventId}`
   - Returns daily aggregated data, 15-minute interval data, and publication level intervals

4. **Chart Component Naming:**
   - **Decision:** `OneLinkAccessChart.vue`

5. **Total Access Count Calculation:**
   - **Decision:** Total everything for the event (sum of all accesses across all dates, historical total)

6. **QR Code Regeneration:**
   - **Decision:** Yes, but not urgent - can be done gradually

7. **Historical Data:**
   - **Decision:** No - start fresh

---

## Success Criteria

1. ✅ Every public event page access is logged
2. ✅ QR code scans are distinguished from other sources
3. ✅ Statistics are viewable in admin UI
4. ✅ No performance degradation
5. ✅ No disruption to user experience
6. ✅ Display links remain short and clean
7. ✅ QR code URLs include `?source=qr` parameter

---

## Timeline Estimate

- **Step 1-2:** Database & Model (30 min)
- **Step 3:** API Endpoint (1 hour)
- **Step 4:** QR Code Update (30 min)
- **Step 5:** Frontend Integration (1 hour)
- **Step 6:** Statistics Endpoint (30 min)
- **Step 7:** Statistics Display (2 hours)

**Total:** ~6 hours

---

## Dependencies

- Laravel framework
- Vue.js frontend
- Existing event and statistics infrastructure
- QR code generation library (already in use)

---

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|------------|--------|------------|
| Database performance issues | Low | Medium | Proper indexing, monitor queries |
| Logging endpoint failures | Low | Low | Silent failure, non-blocking |
| Frontend errors | Low | Low | Try-catch, graceful degradation |
| QR code compatibility | Low | Low | Backward compatible, old QR codes still work |
| Privacy concerns | Low | High | IP hashing, no PII stored |

---

## Conclusion

This plan provides a comprehensive approach to tracking public event access statistics while maintaining user privacy and system performance. The implementation is straightforward and follows existing patterns in the codebase.

