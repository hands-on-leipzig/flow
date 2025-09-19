# SSO Test Setup Guide

This guide explains how to configure your Identity Provider (IDP) to work with the automatic test user creation system.

## How It Works

The system automatically creates users and assigns them to test regional partners when they first log in with the `flow-tester` role. No need to pre-create specific test users!

## IDP Configuration

### Step 1: Assign the `flow-tester` Role

Give your actual user accounts the `flow-tester` role in your IDP. This role:
- **Allows access** to the test environment
- **Automatically creates** the user in the database on first login
- **Auto-assigns** the user to all test regional partners
- **Grants access** to all test events

### Step 2: JWT Token Requirements

Your JWT tokens must include:

```json
{
  "sub": "your-actual-user@yourdomain.com",  // Your real user email
  "resource_access": {
    "flow": {
      "roles": ["flow-tester"]  // Required role for test access
    }
  }
}
```

## What Happens on First Login

1. **User logs in** with `flow-tester` role
2. **System creates** user record in database
3. **System checks** if test regional partners exist
4. **If missing**, creates test regional partners and events
5. **System assigns** user to all test regional partners
6. **User can access** all test events immediately

## Test Data Created Automatically

### Regional Partners
- **Test Regional Partner A**: Separate Explore + Challenge events
- **Test Regional Partner B**: Combined Explore + Challenge event

### Events
- **Test Explore Event**: Explore only (30 days from now)
- **Test Challenge Event**: Challenge only (45 days from now)  
- **Test Combined Event**: Both Explore + Challenge (60 days from now)

## Benefits

- **Use your real accounts**: No need to create fake test users
- **Automatic setup**: Everything is configured on first login
- **Consistent data**: Same test data for all users
- **Easy management**: Just assign the `flow-tester` role

## Testing the Setup

1. **Deploy to test environment** (triggers fresh database creation)
2. **Assign `flow-tester` role** to your user accounts in your IDP
3. **Test login flow**:
   - Login with your real user account
   - Should automatically be created and assigned to test regional partners
   - Should see all test events (both regional partners)

## Troubleshooting

### "Forbidden - tester role required" error
- Ensure your JWT token includes the `flow-tester` role
- Check that you're accessing the test environment (not production)

### "No events visible"
- Check that test regional partners and events were created
- Verify the user is linked to regional partners
- Check that events are in the current season

### "Access denied" errors
- Ensure JWT includes `flow-tester` role for test environment
- For production, use `regionalpartner` or `flow-admin` roles

## Production Considerations

For production, you would:
1. **Remove the fresh database script** from the deployment workflow
2. **Use real regional partners** from your production data
3. **Configure SSO** with your actual user base
4. **Set up proper role mappings** for regional partner access