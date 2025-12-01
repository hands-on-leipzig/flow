# External API - Quick Summary

## Key Decisions

### 1. Route Separation
**Solution**: Path-based separation
- Frontend API: `/api/v1/*` (existing, Keycloak auth)
- External API: `/api/external/v1/*` (new, API key auth)

**Why**: Simple, clear separation, easy to maintain

### 2. Authentication
**Solution**: API Keys (with option to add OAuth2 later)
- Each external application gets API keys
- Keys are hashed and stored securely
- Support for scopes/permissions

**Why**: Simple for M2M communication, can extend later

### 3. Authorization
**Solution**: Scope-based permissions
- Each API key has assigned scopes (e.g., `events:read`, `plans:write`)
- Controllers check scopes before allowing operations
- Applications can have multiple keys with different permissions

## Architecture Overview

```
┌─────────────────┐
│ External App    │
│ (API Key)       │
└────────┬────────┘
         │
         │ /api/external/v1/*
         ▼
┌─────────────────┐
│ ApiKeyMiddleware│ ← Validates API key
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ RateLimit       │ ← Enforces rate limits
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ External        │ ← Controllers check scopes
│ Controllers     │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Business Logic  │
└─────────────────┘
```

## Database Schema

### `applications` table
- Stores external applications
- Has rate limits, IP whitelists
- Links to API keys

### `api_keys` table
- Stores hashed API keys
- Has scopes (permissions)
- Tracks last usage
- Can expire

### `api_request_logs` table
- Logs all API requests
- For monitoring and analytics

## Implementation Files Created

1. **Migrations**:
   - `2025_11_24_170000_create_external_api_tables.php`

2. **Middleware**:
   - `ApiKeyMiddleware.php` - Validates API keys
   - `ApiRateLimitMiddleware.php` - Enforces rate limits

3. **Routes**:
   - `routes/api-external.php` - External API routes

4. **Controllers**:
   - `External/BaseController.php` - Base controller with helper methods
   - `External/HealthController.php` - Health check endpoint

5. **Models**:
   - `Application.php` - Application model
   - `ApiKey.php` - API key model

## Next Steps

1. **Run migrations**:
   ```bash
   php artisan migrate
   ```

2. **Create example controllers**:
   - `External/EventController.php`
   - `External/PlanController.php`

3. **Create admin interface** for managing:
   - Applications
   - API keys
   - Viewing usage logs

4. **Add API documentation** (OpenAPI/Swagger)

5. **Write tests** for external API endpoints

## Usage Example

### Creating an API Key (Admin)
```php
$application = Application::create([
    'name' => 'My External App',
    'contact_email' => 'dev@example.com',
    'rate_limit' => 1000,
]);

$apiKey = ApiKey::create([
    'application_id' => $application->id,
    'name' => 'Production Key',
    'scopes' => ['events:read', 'plans:read'],
    'key_hash' => hash('sha256', $plainKey), // Store plain key securely
]);
```

### Using the API (External App)
```bash
curl -H "X-API-Key: your-api-key-here" \
     https://flow.example.com/api/external/v1/events
```

## Security Features

1. ✅ API keys are hashed (SHA256)
2. ✅ IP whitelisting support
3. ✅ Rate limiting per application
4. ✅ Scope-based permissions
5. ✅ Request logging
6. ✅ Key expiration support

## Questions?

See `EXTERNAL_API_PROPOSAL.md` for detailed documentation.

