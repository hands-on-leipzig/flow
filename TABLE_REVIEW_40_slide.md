# Table Review #40: slide

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `name` (varchar(255), NOT NULL)
- `type` (varchar(255), NOT NULL)
- `content` (longtext, NOT NULL) ⚠️ **Master: `json('content')`**
- `order` (int(11), NOT NULL, default: 0) ⚠️ **Master: `integer('order')->default(0)`**
- `slideshow_id` (int(10) unsigned, NOT NULL, FK to `slideshow.id`) ⚠️ **Master: `slideshow` (no `_id` suffix)**
- `active` (tinyint(1), NOT NULL, default: 1) ⚠️ **Missing in master migration**

### Indexes
- `slide_slideshow_id_foreign` (index on `slideshow_id`)

### Foreign Keys
- `slideshow_id` → `slideshow.id`: RESTRICT on update, **CASCADE** on delete ✅

## Master Migration Current State

- `id`: `unsignedInteger` ✅
- `name`: `string('name')` (no length, no nullability) ⚠️ **Dev DB: varchar(255) NOT NULL**
- `type`: `string('type')` (no length, no nullability) ⚠️ **Dev DB: varchar(255) NOT NULL**
- `content`: `json('content')` ⚠️ **Dev DB: longtext NOT NULL**
- `order`: `integer('order')->default(0)` (no nullability) ⚠️ **Dev DB: int(11) NOT NULL default 0**
- `slideshow`: `unsignedInteger('slideshow')` ⚠️ **Dev DB: `slideshow_id`**
- Missing `active` column ⚠️ **Dev DB: tinyint(1) NOT NULL default 1**
- FK: `slideshow` → `slideshow.id` with `onDelete('cascade')` ✅

## Later Migrations

1. `2025_09_11_112538_slide_active.php`: Added `active` column as `boolean()->default(true)`

## Usage
- Stores individual slides that belong to a slideshow
- `name`: Display name for the slide
- `type`: Type of slide content (e.g., "ImageSlideContent", "RobotGameSlideContent", "UrlSlideContent", etc.)
- `content`: JSON data containing slide-specific content (stored as longtext in Dev DB, but should be JSON)
- `order`: Display order within the slideshow
- `slideshow_id`: Foreign key to `slideshow.id`
- `active`: Whether the slide is active/visible (default: true)
- Used in `CarouselController` for managing slides
- Used in frontend `Carousel.vue` component for displaying slideshows
- Model uses `slideshow_id` in relationship, but master migration uses `slideshow`

## Questions for Review

1. **Column Name:**
   - Dev DB: `slideshow_id`, Master: `slideshow`
   - Model uses `slideshow_id` in relationship
   - Should master migration use `slideshow_id` to match Dev DB and model?

2. **Content Type:**
   - Dev DB: `longtext`, Master: `json('content')`
   - Model expects JSON content (parsed in frontend)
   - Should it be `longText()` or `json()`? JSON is more appropriate for structured data

3. **Column Lengths:**
   - `name` and `type`: Should be `string('name', 255)` and `string('type', 255)` to match Dev DB?

4. **Nullable Fields:**
   - `name`, `type`, `content`, `order`: Should all be NOT NULL to match Dev DB?

5. **Active Column:**
   - Missing in master migration, but exists in Dev DB (added in later migration)
   - Should be added to master migration?

6. **Data Types:**
   - `order`: Dev DB shows `int(11)`, master uses `integer()` (equivalent) ✅
   - `active`: Dev DB shows `tinyint(1)`, migration uses `boolean()` (equivalent) ✅

## Decisions ✅

- [x] **Change column name from `slideshow` to `slideshow_id`** ✅ (Dev DB has the correct name)
- [x] **Add `active` column to master migration** ✅ (`boolean()->default(true)`)
- [x] **Update master to match Dev DB** ✅
  - `name`: `string('name', 255)` (added length)
  - `type`: `string('type', 255)` (added length)
  - `content`: `longText('content')` (changed from `json()` to match Dev DB)
  - `order`: `integer('order')->default(0)` (kept as-is)
  - `slideshow_id`: `unsignedInteger('slideshow_id')` (changed name from `slideshow`)

## Implementation

Updated master migration:
- Changed `slideshow` to `slideshow_id` to match Dev DB and model
- Added `active` column as `boolean()->default(true)`
- Updated `name` to `string('name', 255)` to match Dev DB
- Updated `type` to `string('type', 255)` to match Dev DB
- Changed `content` from `json()` to `longText()` to match Dev DB
- Kept `order` as `integer('order')->default(0)`
- FK: `slideshow_id` → `slideshow.id` with `onDelete('cascade')`

