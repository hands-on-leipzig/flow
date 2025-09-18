# Draht API Simulator

This simulator provides mock responses for the Draht API endpoints, allowing the application to work in test environments without requiring the actual Draht service.

## 🎯 Purpose

- **Test Environment**: Enables full testing without external API dependencies
- **Development**: Allows developers to work offline or with limited access
- **CI/CD**: Ensures consistent testing in automated environments

## 🚀 Features

### Simulated Endpoints

1. **`/handson/rp`** - Regional Partners
   - Returns 3 test regional partners
   - Includes ID, name, and region data

2. **`/handson/flow/events`** - All Events and Teams
   - Returns 3 test events with different program types
   - Includes teams with mock members
   - Covers Explore-only, Challenge-only, and Combined events

3. **`/handson/events/{id}/scheduledata`** - Event Schedule Data
   - Returns detailed event information
   - Includes address, contact, teams, and capacity data
   - Generates random but realistic team data

### Mock Data Features

- **Realistic Team Names**: Uses alphabet-based naming (Team A, B, C, etc.)
- **Random Member Generation**: Creates 2-4 members per team with realistic names
- **Contact Information**: Includes serialized contact data
- **Capacity Data**: Random team capacities between 10-50
- **Date Generation**: Future dates for realistic testing

## 🔧 Usage

### Automatic Activation

The simulator is automatically activated in these environments:
- `local` (development)
- `staging` (test)

### Manual Testing

You can test the simulator directly via API endpoints:

```bash
# Get regional partners
curl http://localhost:8000/api/draht-simulator/handson/rp

# Get all events
curl http://localhost:8000/api/draht-simulator/handson/flow/events

# Get specific event data
curl http://localhost:8000/api/draht-simulator/handson/events/1001/scheduledata
```

### Integration

The `DrahtController` automatically uses the simulator in test environments:

```php
// This will use the simulator in local/staging
$response = $controller->makeDrahtCall('/handson/rp');
```

## 📁 Files

- **`DrahtSimulatorController.php`** - Main simulator logic
- **`DrahtController.php`** - Modified to use simulator in test environments
- **`api.php`** - Routes for direct simulator access

## 🧪 Testing

The simulator has been tested with:
- ✅ Direct API calls
- ✅ DrahtController integration
- ✅ Full event data retrieval
- ✅ Team and member generation
- ✅ Response format compatibility

## 🔄 Data Generation

### Regional Partners
- **Test Regional Partner A** (ID: 2001)
- **Test Regional Partner B** (ID: 2002)  
- **Test Regional Partner C** (ID: 2003)

### Events
- **Test Explore Event** - Explore only (ID: 1001)
- **Test Challenge Event** - Challenge only (ID: 1002)
- **Test Combined Event** - Both programs (ID: 1003)

### Teams
- Random team count (2-8 per event)
- Alphabetical naming (Team A, B, C, etc.)
- Mixed program types (Explore, Challenge, Both)
- Realistic member data with names and emails

## 🚨 Production

The simulator is **disabled** in production environments. The `DrahtController` will use the real Draht API with proper authentication.

## 🔍 Debugging

To debug simulator issues:

1. Check environment: `app()->environment()`
2. Verify route registration: `php artisan route:list | grep draht`
3. Test direct access: Use the manual testing endpoints above
4. Check logs: `tail -f storage/logs/laravel.log`

## 📝 Notes

- The simulator maintains the same response format as the real Draht API
- All mock data is generated dynamically for realistic testing
- The simulator respects the same authentication flow as the real API
- Team and member data is randomized to avoid conflicts in testing
