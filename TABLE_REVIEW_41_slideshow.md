# Table Review #41: slideshow

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `name` (varchar(255), nullable) ✅
- `event` (int(10) unsigned, NOT NULL, FK to `event.id`)
- `transition_time` (int(11), NOT NULL, default: 15) ✅

### Indexes
- `slideshow_event_foreign` (index on `event`)

### Foreign Keys
- `event` → `event.id`: RESTRICT on update, **CASCADE** on delete ⚠️ **Master: no delete rule (defaults to RESTRICT)**

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `name`: `string('name')->nullable(true)` ✅
- `event`: `unsignedInteger('event')` (NOT NULL) ✅
- `transition_time`: `integer('transition_time')->default(15)` ✅
- FK: `event` → `event.id` (no delete rule specified = RESTRICT) ⚠️ **Dev DB: CASCADE**

## Usage
- Stores slideshow configurations for events
- One slideshow can have many slides (via `slide.slideshow_id`)
- `name`: Display name for the slideshow (nullable)
- `event`: Foreign key to `event.id`
- `transition_time`: Time in seconds between slide transitions (default: 15)
- Used in `CarouselController` for managing slideshows
- Used in frontend `Carousel.vue` component for displaying slideshows
- When an event is deleted, the slideshow should be deleted (CASCADE)

## Questions for Review

1. **FK Delete Rule:**
   - Dev DB: CASCADE on delete
   - Master: No delete rule (defaults to RESTRICT)
   - Should it be CASCADE to match Dev DB? (Makes sense: if event is deleted, slideshow should be deleted)

2. **Column Definitions:**
   - All columns match between Dev DB and master ✅
   - `name`: nullable ✅
   - `transition_time`: default 15 ✅

3. **Data Types:**
   - All types match ✅
   - `transition_time`: Dev DB shows `int(11)`, master uses `integer()` (equivalent) ✅

## Decisions ✅

- [x] **FK delete rule: CASCADE on delete** ✅ (to match Dev DB)

## Implementation

Updated master migration:
- Changed FK delete rule from RESTRICT (default) to `onDelete('cascade')` to match Dev DB
- All other columns remain as-is ✅

