# Test Environment Setup Guide

This guide explains how to set up the test environment so that users can see data and the application works properly.

## The Problem

After deploying to test, the database appears "blank" because:
1. Users need to be associated with regional partners
2. Events need to be in the current season
3. Main tables need to be populated with reference data

## Quick Setup

### 1. Run the Setup Script

```bash
cd backend
php artisan tinker
>>> include 'database/scripts/setup_test_environment.php';
>>> setupTestEnvironment();
```

This will:
- Create a test regional partner
- Create a test event in the current season
- Create a test user with proper relationships
- Verify all main tables have data

### 2. Test the API

```bash
# Test the selectable events endpoint
curl -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  https://test.flow.hands-on-technology.org/api/events/selectable
```

## What Data is Needed

### Essential Tables

1. **Regional Partners** (`regional_partner`)
   - Users are associated with regional partners
   - Events belong to regional partners

2. **Events** (`event`)
   - Must be in the current season
   - Must have a valid regional partner
   - Must have a valid level

3. **Seasons** (`m_season`)
   - Events are filtered by the latest season
   - Currently: UNEARTHED (2025)

4. **Levels** (`m_level`)
   - Events must have a valid level
   - Used for room type filtering

5. **Main Tables** (`m_*`)
   - All reference data for the application
   - Room types, activity types, parameters, etc.

### User Authentication

Users are created automatically when they first log in via JWT. The system:
1. Extracts user ID from JWT `sub` claim
2. Creates user record if it doesn't exist
3. Associates user with regional partners based on JWT roles

## Data Population Strategies

### Option 1: Use Existing Data (Recommended)

If you have a working dev database:

```bash
# Export main tables from dev
mysqldump -u dev_user -p dev_database \
  --tables m_activity_type m_activity_type_detail m_first_program \
  m_insert_point m_level m_parameter m_role m_room_type \
  m_room_type_group m_season m_supported_plan m_visibility \
  --no-create-info --single-transaction > main_tables.sql

# Import to test
mysql -u test_user -p test_database < main_tables.sql
```

### Option 2: Create Test Data

Use the setup script to create minimal test data:

```bash
php artisan tinker
>>> include 'database/scripts/setup_test_environment.php';
>>> setupTestEnvironment();
```

### Option 3: Copy from Production

If you have production data you want to preserve:

```bash
# Export specific tables from production
mysqldump -u prod_user -p prod_database \
  --tables regional_partner event user user_regional_partner \
  --no-create-info --single-transaction > prod_data.sql

# Import to test
mysql -u test_user -p test_database < prod_data.sql
```

## Troubleshooting

### "Blank Database" Issues

1. **Check if main tables have data:**
   ```bash
   php artisan tinker
   >>> DB::table('m_room_type')->count();
   >>> DB::table('m_activity_type')->count();
   ```

2. **Check if events are in current season:**
   ```bash
   php artisan tinker
   >>> $latest = App\Models\MSeason::latest('year')->first();
   >>> echo "Latest season: " . $latest->id . " - " . $latest->name;
   >>> App\Models\Event::where('season', $latest->id)->count();
   ```

3. **Check user-regional partner relationships:**
   ```bash
   php artisan tinker
   >>> $user = App\Models\User::first();
   >>> $user->regionalPartners()->count();
   ```

### API Endpoint Issues

1. **Test the selectable events endpoint:**
   ```bash
   php artisan tinker
   >>> $user = App\Models\User::first();
   >>> Auth::login($user);
   >>> $controller = new App\Http\Controllers\Api\EventController();
   >>> $data = $controller->getSelectableEvents();
   >>> echo "Response count: " . $data->count();
   ```

2. **Check JWT authentication:**
   - Ensure JWT token is valid
   - Check user roles in JWT claims
   - Verify user exists in database

## Production Deployment

For production, follow the same steps but:

1. **Backup existing data first**
2. **Use the comprehensive migration** to sync schema
3. **Refresh main tables** with dev data
4. **Preserve existing user/event data**

## Key Points

- **Season Filtering**: Events are filtered by the latest season
- **User Relationships**: Users must be linked to regional partners
- **JWT Authentication**: Users are created automatically on first login
- **Main Tables**: Must be populated with reference data
- **Data Integrity**: Foreign key relationships must be maintained

## Next Steps

1. Run the setup script
2. Test the API endpoints
3. Verify the frontend loads data
4. Check user authentication flow
5. Monitor for any missing data
