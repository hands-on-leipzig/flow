# Table Review #28: news_user

## Current Schema (Dev DB)

### Columns
- `id` (int(10) unsigned, NOT NULL, PRIMARY KEY, auto_increment) ✅
- `user_id` (int(10) unsigned, NOT NULL, FK) ✅
- `news_id` (int(10) unsigned, NOT NULL, FK) ✅
- `read_at` (timestamp, NOT NULL, default: current_timestamp()) ✅

### Indexes
- `news_user_user_id_news_id_unique` (unique index on `user_id`, `news_id`) ✅
- `news_user_news_id_foreign` (index on `news_id`) ✅ (created by FK)

### Foreign Keys
- **Note:** Dev DB export doesn't show foreign keys section for this table, but separate migration defines:
  - `user_id` → `user.id`: CASCADE on delete
  - `news_id` → `m_news.id`: CASCADE on delete

## Master Migration Current State

**Note:** `news_user` table is NOT in master migration. It's created by separate migration `2025_10_21_120956_create_news_user_table.php`.

The separate migration defines:
- `id`: `unsignedInteger` ✅
- `user_id`: `unsignedInteger` (NOT NULL) ✅
- `news_id`: `unsignedInteger` (NOT NULL) ✅
- `read_at`: `timestamp()->useCurrent()` ✅
- Unique constraint on (`user_id`, `news_id`) ✅
- FK: `user_id` → `user.id` with `onDelete('cascade')` ✅
- FK: `news_id` → `m_news.id` with `onDelete('cascade')` ✅

## Usage
- Junction table tracking which users have read which news items
- Used in `NewsController` for marking news as read and statistics
- Used in `NewsUser` model with relationships to `User` and `MNews`
- Unique constraint prevents duplicate read records per user/news combination

## Questions for Review

1. **Master Migration:**
   - Should `news_user` table be added to master migration, or stay as separate migration?
   - **Note:** It's a relatively new table (created Oct 2025), so separate migration might be intentional

2. **Foreign Keys:**
   - Separate migration defines both FKs with CASCADE
   - Dev DB export doesn't show foreign keys (might be missing from export or not created)
   - Should both FKs be added with CASCADE?

## Decisions ✅

- [x] **Add `news_user` table to master migration** ✅
- [x] **Both FK delete rules: CASCADE** ✅

## Implementation

Added to master migration after `user` table:
- `id`: `unsignedInteger` ✅
- `user_id`: `unsignedInteger` (NOT NULL, FK) ✅
- `news_id`: `unsignedInteger` (NOT NULL, FK) ✅
- `read_at`: `timestamp()->useCurrent()` ✅
- Unique constraint on (`user_id`, `news_id`) ✅
- FK: `user_id` → `user.id` with `onDelete('cascade')` ✅
- FK: `news_id` → `m_news.id` with `onDelete('cascade')` ✅
- Added to `down()` method for rollback

