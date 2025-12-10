# Systems News Feature - Deep Dive

## Overview

The Systems News feature is a notification system that allows administrators to create and broadcast news/announcements to all users of the Flow application. It implements a FIFO (First In, First Out) delivery system where users see the oldest unread news first, ensuring important announcements are not missed.

## Architecture

### Database Schema

#### `m_news` Table
- **Purpose**: Stores news/announcement items
- **Type**: Master table (recreated on refresh)
- **Columns**:
  - `id` (unsigned integer, auto-increment, primary key)
  - `title` (string, 255 chars) - News headline
  - `text` (text) - Full news content
  - `link` (string, 500 chars, nullable) - Optional external link
  - `created_at` (timestamp) - When news was created
  - `updated_at` (timestamp) - Last update time

#### `news_user` Table
- **Purpose**: Tracks which users have read which news items
- **Type**: Junction/pivot table (preserved on migrations)
- **Columns**:
  - `id` (unsigned integer, auto-increment, primary key)
  - `user_id` (unsigned integer) - Foreign key to `user.id`
  - `news_id` (unsigned integer) - Foreign key to `m_news.id`
  - `read_at` (timestamp) - When user marked news as read
- **Constraints**:
  - Unique constraint on `(user_id, news_id)` - prevents duplicate read records
  - Foreign key `news_id` → `m_news.id` (CASCADE on delete)
  - Foreign key `user_id` → `user.id` (SET NULL on delete for data preservation)

### Models

#### `MNews` Model (`backend/app/Models/MNews.php`)
- **Table**: `m_news`
- **Relationships**:
  - `readByUsers()`: Belongs-to-many relationship with `User` through `news_user` pivot table
- **Fillable**: `title`, `text`, `link`
- **Casts**: `created_at` and `updated_at` to datetime

#### `NewsUser` Model (`backend/app/Models/NewsUser.php`)
- **Table**: `news_user`
- **Relationships**:
  - `news()`: Belongs to `MNews`
  - `user()`: Belongs to `User`
- **Fillable**: `user_id`, `news_id`, `read_at`
- **Casts**: `read_at` to datetime
- **Note**: No timestamps (uses `read_at` instead)

## Backend Implementation

### Controller: `NewsController` (`backend/app/Http/Controllers/Api/NewsController.php`)

#### Public Endpoints (Authenticated Users)

**1. `GET /api/news/unread`**
- **Purpose**: Get oldest unread news for current user
- **Authentication**: Required (Keycloak JWT)
- **Logic**:
  ```php
  // Finds oldest news that user hasn't read
  MNews::whereNotExists(function ($query) use ($userId) {
      $query->select(DB::raw(1))
          ->from('news_user')
          ->whereColumn('news_user.news_id', 'm_news.id')
          ->where('news_user.user_id', $userId);
  })
  ->orderBy('created_at', 'asc')  // FIFO: oldest first
  ->first();
  ```
- **Response**: 
  - `null` if no unread news
  - News object with `id`, `title`, `text`, `link`, `created_at` if found

**2. `POST /api/news/{id}/mark-read`**
- **Purpose**: Mark a news item as read for current user
- **Authentication**: Required
- **Logic**:
  ```php
  NewsUser::updateOrCreate(
      ['user_id' => $userId, 'news_id' => $id],
      ['read_at' => now()]
  );
  ```
- **Response**: `{success: true}`

#### Admin Endpoints (Admin Role Required)

**3. `GET /api/admin/news`**
- **Purpose**: List all news with read statistics
- **Authentication**: Required + Admin role (`flow_admin`)
- **Response**: Array of news items with:
  - `id`, `title`, `text`, `link`, `created_at`
  - `read_count`: Number of users who read it
  - `total_users`: Total number of users in system
- **Order**: Newest first (for admin display)

**4. `POST /api/admin/news`**
- **Purpose**: Create new news item
- **Authentication**: Required + Admin role
- **Validation**:
  - `title`: required, string, max 255
  - `text`: required, string
  - `link`: nullable, string, max 500
- **Response**: Created news object (201 status)

**5. `DELETE /api/admin/news/{id}`**
- **Purpose**: Delete a news item
- **Authentication**: Required + Admin role
- **Cascade**: Automatically deletes all `news_user` records via foreign key
- **Response**: `{success: true}`

**6. `GET /api/admin/news/{id}/stats`**
- **Purpose**: Get read statistics for specific news
- **Authentication**: Required + Admin role
- **Response**: 
  ```json
  {
    "news_id": 1,
    "read_count": 15,
    "total_users": 20
  }
  ```

### Routes (`backend/routes/api.php`)

```php
// User routes (authenticated)
Route::prefix('news')->group(function () {
    Route::get('/unread', [NewsController::class, 'getUnreadNews']);
    Route::post('/{id}/mark-read', [NewsController::class, 'markAsRead']);
});

// Admin routes (authenticated + admin role)
Route::prefix('admin/news')->group(function () {
    Route::get('/', [NewsController::class, 'index']);
    Route::post('/', [NewsController::class, 'store']);
    Route::delete('/{id}', [NewsController::class, 'destroy']);
    Route::get('/{id}/stats', [NewsController::class, 'stats']);
});
```

## Frontend Implementation

### User-Facing Components

#### 1. News Modal (`frontend/src/components/atoms/NewsModal.vue`)

**Purpose**: Displays unread news in a modal dialog

**Features**:
- Full-screen overlay with semi-transparent background
- Responsive design (max-width 2xl, max-height 90vh)
- Scrollable content area
- Gradient header (blue) with title and formatted date
- Content area with:
  - News text (whitespace-preserved)
  - Optional external link with icon
- Footer with:
  - Contact email link (mailto: flow@hands-on-technology.org)
  - "Gelesen" (Read) button to mark as read
- Fade-in animation

**Props**:
- `news` (Object, required): News item with `id`, `title`, `text`, `link`, `created_at`

**Events**:
- `@markRead`: Emitted when user clicks "Gelesen" button, passes `news.id`

**Computed**:
- `mailtoLink`: Generates mailto link with pre-filled subject

#### 2. App Integration (`frontend/src/App.vue`)

**News Check Logic**:
```javascript
// Check for unread news on route changes
watch(() => route.path, async () => {
  if (!isPublicRoute.value) {
    await checkForUnreadNews()
  }
})

// Only checks on authenticated routes (not public)
const checkForUnreadNews = async () => {
  if (isPublicRoute.value) return
  
  const response = await axios.get('/news/unread')
  if (response.data && response.data.id) {
    currentNews.value = response.data
    showNewsModal.value = true
  }
}
```

**Key Behaviors**:
- Only checks on non-public routes
- Checks on every route change
- Silently fails if API call fails (doesn't disrupt UX)
- Shows modal only if unread news exists
- Modal is lazy-loaded (async component)

**Mark as Read**:
```javascript
const markNewsAsRead = async (newsId) => {
  await axios.post(`/news/${newsId}/mark-read`)
  showNewsModal.value = false
  currentNews.value = null
  // Modal closes even if API call fails
}
```

### Admin Interface

#### SystemNews Component (`frontend/src/components/molecules/SystemNews.vue`)

**Purpose**: Admin interface for managing system news

**Features**:

1. **News List Display**:
   - Shows all news items (newest first)
   - Each item displays:
     - Title and formatted creation date
     - Full text content
     - Optional link
     - Read statistics badge:
       - Green if 100% read
       - Yellow if partially read
       - Shows "X von Y Usern gelesen (Z%)"
   - Delete button for each item

2. **Create News Form** (Dev Environment Only):
   - Toggle button (only enabled in dev)
   - Form fields:
     - Title (required, max 255 chars)
     - Text (required, textarea)
     - Link (optional, URL input, max 500 chars)
   - Create and Cancel buttons

3. **Delete Confirmation**:
   - Uses `ConfirmationModal` component
   - Shows news title in confirmation message
   - Prevents accidental deletion

**Props**:
- `isDevEnvironment` (Boolean): Controls whether create form is available

**API Calls**:
- `GET /admin/news`: Load all news with statistics
- `POST /admin/news`: Create new news
- `DELETE /admin/news/{id}`: Delete news

**Integration**: Accessed via Admin component tab "📰 System News"

## User Flow

### For Regular Users

1. **User logs in** → Authenticated via Keycloak
2. **User navigates** → Route change triggers news check
3. **System checks** → `GET /api/news/unread`
4. **If unread exists**:
   - Modal appears with oldest unread news
   - User reads content
   - User clicks "Gelesen" button
   - `POST /api/news/{id}/mark-read` called
   - Modal closes
   - On next route change, next unread news (if any) appears
5. **If no unread** → No modal, normal navigation continues

### For Administrators

1. **Admin navigates** → `/plan/admin` → "System News" tab
2. **View existing news**:
   - See all news with read statistics
   - Identify which announcements need follow-up
3. **Create new news** (Dev only):
   - Click "➕ Neue News erstellen"
   - Fill form (title, text, optional link)
   - Click "Erstellen"
   - News immediately available to all users
4. **Delete news**:
   - Click delete icon on news item
   - Confirm deletion
   - News and all read records removed

## Key Design Decisions

### 1. FIFO Delivery (First In, First Out)
- **Why**: Ensures users see oldest announcements first
- **Implementation**: `orderBy('created_at', 'asc')` in unread query
- **Benefit**: Important announcements don't get buried by newer ones

### 2. One News at a Time
- **Why**: Prevents modal overload, ensures attention
- **Implementation**: Only shows one modal, checks again after marking as read
- **Benefit**: Better UX, users can focus on one announcement

### 3. Silent Failure
- **Why**: News system shouldn't disrupt core functionality
- **Implementation**: Try-catch with console.error, no user-facing errors
- **Benefit**: Application remains usable even if news service has issues

### 4. Public Route Exclusion
- **Why**: Public pages (event displays) don't need news
- **Implementation**: `isPublicRoute` check before news check
- **Benefit**: Cleaner public-facing experience

### 5. Dev-Only Creation
- **Why**: Prevent accidental news creation in production
- **Implementation**: `isDevEnvironment` prop check
- **Benefit**: Production safety, dev flexibility

### 6. Cascade Delete
- **Why**: Clean up read records when news is deleted
- **Implementation**: Foreign key with `onDelete('cascade')`
- **Benefit**: No orphaned records

### 7. Unique Constraint
- **Why**: Prevent duplicate read records
- **Implementation**: Unique index on `(user_id, news_id)`
- **Benefit**: Data integrity, `updateOrCreate` works correctly

## Statistics & Analytics

### Read Statistics
- **Display**: In admin interface for each news item
- **Metrics**:
  - `read_count`: Users who marked as read
  - `total_users`: All users in system
  - Percentage: `(read_count / total_users) * 100`
- **Visual Indicators**:
  - Green badge: 100% read
  - Yellow badge: < 100% read

### Use Cases
- Track announcement effectiveness
- Identify users who may need follow-up
- Measure engagement with system updates

## Security Considerations

### Authentication
- All endpoints require Keycloak JWT authentication
- Admin endpoints require `flow_admin` role
- Middleware enforces role-based access

### Authorization
- Users can only mark their own read status
- Users can only see their own unread news
- Only admins can create/delete news

### Data Privacy
- No personal information exposed in news
- Read status is user-specific (not visible to other users)
- Admin can see aggregate statistics only

## Error Handling

### Backend
- News not found: 404 response
- Validation errors: 422 with error details
- Database errors: Caught and logged, user-friendly messages

### Frontend
- API failures: Silently logged, no user disruption
- Missing news ID: Console error, modal still closes
- Network errors: Graceful degradation

## Future Enhancements (Potential)

1. **Email Notifications**: Send email when new news is created
2. **News Categories**: Tag news (urgent, feature, maintenance)
3. **Scheduled Publishing**: Publish news at specific times
4. **Rich Text Editor**: HTML formatting for news content
5. **Read Receipts**: Track when users actually view (not just mark as read)
6. **News Expiration**: Auto-archive old news
7. **User Preferences**: Allow users to opt-out of certain news types
8. **Push Notifications**: Browser push for urgent news

## Testing Considerations

### Unit Tests
- Model relationships
- Controller validation
- Query logic (FIFO ordering)

### Integration Tests
- News creation flow
- Read marking flow
- Admin access control
- Cascade delete behavior

### E2E Tests
- Modal display on route change
- Mark as read functionality
- Admin create/delete flow
- Statistics accuracy

## Migration History

1. **2025-10-21**: Initial creation
   - `create_m_news_table.php`
   - `create_news_user_table.php`
2. **2025-11-10**: ID type updates
   - Changed to unsigned integers
3. **2025-11-14**: Foreign key fixes
   - Added proper cascade/SET NULL behaviors

## Related Files

### Backend
- `backend/app/Models/MNews.php`
- `backend/app/Models/NewsUser.php`
- `backend/app/Http/Controllers/Api/NewsController.php`
- `backend/routes/api.php` (lines 300-312)
- `backend/database/migrations/2025_10_21_120706_create_m_news_table.php`
- `backend/database/migrations/2025_10_21_120956_create_news_user_table.php`

### Frontend
- `frontend/src/components/atoms/NewsModal.vue`
- `frontend/src/components/molecules/SystemNews.vue`
- `frontend/src/components/Admin.vue` (integration)
- `frontend/src/App.vue` (news check logic)

## Summary

The Systems News feature is a well-architected notification system that:
- ✅ Provides non-intrusive user notifications
- ✅ Ensures important announcements are seen (FIFO)
- ✅ Offers admin control with statistics
- ✅ Maintains data integrity with proper relationships
- ✅ Handles errors gracefully
- ✅ Respects user privacy and security

It's a production-ready feature that balances user experience with administrative needs.



