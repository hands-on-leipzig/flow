# Table Review #39: s_one_link_access

## Current Schema (Dev DB)

### Columns
- `id` (bigint(20) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ⚠️ **Should be `unsignedInteger`**
- `event` (int(10) unsigned, NOT NULL, FK to `event.id`)
- `access_date` (date, NOT NULL)
- `access_time` (timestamp, nullable)
- `user_agent` (text, nullable)
- `referrer` (text, nullable)
- `ip_hash` (varchar(64), nullable)
- `accept_language` (varchar(50), nullable)
- `screen_width` (smallint(5) unsigned, nullable)
- `screen_height` (smallint(5) unsigned, nullable)
- `viewport_width` (smallint(5) unsigned, nullable)
- `viewport_height` (smallint(5) unsigned, nullable)
- `device_pixel_ratio` (decimal(3,2), nullable)
- `touch_support` (tinyint(1), nullable)
- `connection_type` (varchar(20), nullable)
- `source` (varchar(20), nullable)

### Indexes
- `idx_event_access_date` (composite index on `event`, `access_date`)
- `idx_access_date` (index on `access_date`)

### Foreign Keys
- `event` → `event.id`: RESTRICT on update, **CASCADE** on delete ✅

## Master Migration Current State

**Note:** `s_one_link_access` is NOT in the master migration. It's defined in a separate migration file: `2025_11_19_112914_create_s_one_link_access_table.php`

From the migration file:
- `id`: `id()` (defaults to `bigIncrements` = `bigint(20) unsigned`) ⚠️ **Should be `unsignedInteger`**
- `event`: `unsignedInteger` (NOT NULL), FK to `event.id` with `onDelete('cascade')` ✅
- `access_date`: `date()` (NOT NULL) ✅
- `access_time`: `timestamp()->nullable()` ✅
- `user_agent`: `text()->nullable()` ✅
- `referrer`: `text()->nullable()` ✅
- `ip_hash`: `string('ip_hash', 64)->nullable()` ✅
- `accept_language`: `string('accept_language', 50)->nullable()` ✅
- `screen_width`: `unsignedSmallInteger()->nullable()` ✅
- `screen_height`: `unsignedSmallInteger()->nullable()` ✅
- `viewport_width`: `unsignedSmallInteger()->nullable()` ✅
- `viewport_height`: `unsignedSmallInteger()->nullable()` ✅
- `device_pixel_ratio`: `decimal('device_pixel_ratio', 3, 2)->nullable()` ✅
- `touch_support`: `boolean()->nullable()` ✅
- `connection_type`: `string('connection_type', 20)->nullable()` ✅
- `source`: `string('source', 20)->nullable()` ✅
- Indexes: Composite `['event', 'access_date']` and single `access_date` ✅
- FK: `event` → `event.id` with `onDelete('cascade')` ✅

## Usage
- Statistics table tracking public event page accesses
- One row per access to a public event page (via slug)
- Captures both server-side (HTTP headers) and client-side (JavaScript) data
- `access_date` and `access_time` track when access occurred
- `source` tracks how user arrived ('qr', 'direct', 'referrer', 'unknown')
- Used in `StatisticController` for access statistics and charts
- Used in `PublishController` to log accesses via `logOneLinkAccess()` endpoint
- Privacy: IP addresses are hashed (SHA-256) before storage

## Questions for Review

1. **Should `s_one_link_access` be added to master migration?**
   - Currently it's in a separate migration file
   - For consistency, should it be in the master migration?

2. **ID Type:**
   - Migration uses `id()` which defaults to `bigIncrements` (bigint(20) unsigned)
   - Dev DB shows `bigint(20) unsigned`
   - Should it be `unsignedInteger` to match standard, or keep `bigIncrements` for high-volume statistics table?

3. **Data Types:**
   - All other types match between migration and Dev DB ✅
   - `touch_support`: Migration uses `boolean()`, Dev DB shows `tinyint(1)` - these are equivalent ✅

4. **Indexes:**
   - Both migration and Dev DB have the same indexes ✅
   - Composite index on `['event', 'access_date']` for efficient queries filtering by event and date
   - Single index on `access_date` for date-range queries

5. **Nullable Fields:**
   - `event` and `access_date`: NOT NULL ✅
   - All other fields: nullable ✅ (appropriate for optional client-side data)

6. **FK Delete Rule:**
   - CASCADE on delete ✅ (matches both migration and Dev DB)
   - Makes sense: if event is deleted, access history should be deleted too

## Decisions ✅

- [x] **Add `s_one_link_access` table to master migration** ✅
- [x] **Change `id` from `bigIncrements` to `unsignedInteger`** ✅ (standard type)
- [x] **event FK: CASCADE on delete, keep the rest as-is** ✅

## Implementation

Added `s_one_link_access` table to master migration:
- `id`: `unsignedInteger` (changed from `bigIncrements` to match standard)
- `event`: `unsignedInteger` (NOT NULL), FK to `event.id` with `onDelete('cascade')`
- `access_date`: `date()` (NOT NULL)
- `access_time`: `timestamp()->nullable()`
- `user_agent`: `text()->nullable()`
- `referrer`: `text()->nullable()`
- `ip_hash`: `string('ip_hash', 64)->nullable()`
- `accept_language`: `string('accept_language', 50)->nullable()`
- `screen_width`: `unsignedSmallInteger()->nullable()`
- `screen_height`: `unsignedSmallInteger()->nullable()`
- `viewport_width`: `unsignedSmallInteger()->nullable()`
- `viewport_height`: `unsignedSmallInteger()->nullable()`
- `device_pixel_ratio`: `decimal('device_pixel_ratio', 3, 2)->nullable()`
- `touch_support`: `boolean()->nullable()`
- `connection_type`: `string('connection_type', 20)->nullable()`
- `source`: `string('source', 20)->nullable()`
- Indexes: Composite `['event', 'access_date']` and single `access_date`
- FK: `event` → `event.id` with `onDelete('cascade')`
- No timestamps (model uses `$timestamps = false`) ✅

