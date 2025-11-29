# External API Implementation - Setup Complete

## Overview

The external API is now configured at `/api/external/` with API key authentication. This document summarizes what has been implemented.

## Routes

All external API routes are available under `/api/external/`:

- `GET /api/external/health` - Health check endpoint
- `GET /api/external/events` - List events
- `GET /api/external/events/{id}` - Get event by ID
- `GET /api/external/events/slug/{slug}` - Get event by slug
- `GET /api/external/plans/event/{eventId}` - Get plan by event ID
- `GET /api/external/plans/{id}` - Get plan by ID
- `GET /api/external/plans/{id}/activities` - Get plan activities

## Database Tables

### `applications`
Stores external applications that use the API:
- `id` - Primary key
- `name` - Application name
- `description` - Optional description
- `contact_email` - Contact email
- `webhook_url` - Optional webhook URL
- `allowed_ips` - JSON array of allowed IP addresses (optional)
- `rate_limit` - Requests per hour (default: 1000)
- `is_active` - Whether the application is active
- `created_at`, `updated_at` - Timestamps

### `api_keys`
Stores API keys for authentication:
- `id` - Primary key
- `name` - Human-readable key identifier
- `key_hash` - SHA256 hash of the API key (unique)
- `application_id` - Foreign key to applications
- `scopes` - JSON array of permission scopes
- `last_used_at` - Timestamp of last usage
- `expires_at` - Optional expiration date
- `is_active` - Whether the key is active
- `created_at`, `updated_at` - Timestamps

### `api_request_logs`
Logs all API requests for monitoring:
- `id` - Primary key
- `application_id` - Foreign key to applications
- `api_key_id` - Foreign key to api_keys (nullable)
- `method` - HTTP method (GET, POST, etc.)
- `path` - Request path
- `status_code` - HTTP status code
- `response_time_ms` - Response time in milliseconds
- `ip_address` - Client IP address
- `user_agent` - User agent string
- `request_headers` - JSON of request headers
- `response_headers` - JSON of response headers
- `created_at` - Timestamp

## Middleware

### `api.key` (ApiKeyMiddleware)
- Validates API key from `X-API-Key` or `Authorization` header
- Checks if key is active and not expired
- Validates IP whitelist if configured
- Attaches application and API key to request

### `api.rate_limit` (ApiRateLimitMiddleware)
- Enforces rate limits per application
- Adds rate limit headers to responses
- Returns 429 status when limit exceeded

## Models

### `Application`
Located at `app/Models/Application.php`
- Relationships: `apiKeys()`, `activeApiKeys()`
- Casts: `allowed_ips` to array, `is_active` to boolean

### `ApiKey`
Located at `app/Models/ApiKey.php`
- Relationships: `application()`
- Methods: `isExpired()`, `isValid()`
- Casts: `scopes` to array, `is_active` to boolean

## Controllers

All external API controllers extend `BaseController` which provides:
- `getApplication()` - Get application from request
- `getApiKey()` - Get API key from request
- `hasScope()` - Check if request has specific scope
- `requireScope()` - Require scope or abort
- `success()` - Standard success response
- `error()` - Standard error response

### Available Controllers
- `HealthController` - Health check endpoint
- `EventController` - Event-related endpoints
- `PlanController` - Plan-related endpoints

## Setup Instructions

### 1. Run Migrations
```bash
cd backend
php artisan migrate
```

This will create the `applications`, `api_keys`, and `api_request_logs` tables.

### 2. Create an Application
```php
use App\Models\Application;
use App\Models\ApiKey;

// Create application
$application = Application::create([
    'name' => 'My External App',
    'description' => 'Description of the application',
    'contact_email' => 'dev@example.com',
    'rate_limit' => 1000,
    'is_active' => true,
]);

// Generate API key
$plainKey = bin2hex(random_bytes(32)); // 64 character hex string
$keyHash = hash('sha256', $plainKey);

$apiKey = ApiKey::create([
    'name' => 'Production Key',
    'key_hash' => $keyHash,
    'application_id' => $application->id,
    'scopes' => ['events:read', 'plans:read'],
    'is_active' => true,
]);

// IMPORTANT: Store $plainKey securely - it won't be retrievable later!
echo "API Key: " . $plainKey . "\n";
```

### 3. Test the API
```bash
# Health check
curl -H "X-API-Key: your-api-key-here" \
     https://your-domain.com/api/external/health

# List events
curl -H "X-API-Key: your-api-key-here" \
     https://your-domain.com/api/external/events

# Get event by ID
curl -H "X-API-Key: your-api-key-here" \
     https://your-domain.com/api/external/events/1
```

## Scopes

Scopes define what permissions an API key has. Current scopes:
- `events:read` - Read events
- `plans:read` - Read plans

Add more scopes as needed in your controllers using `requireScope()`.

## Security Features

1. ✅ API keys are hashed (SHA256) - plain keys are never stored
2. ✅ IP whitelisting support per application
3. ✅ Rate limiting per application
4. ✅ Scope-based permissions
5. ✅ Key expiration support
6. ✅ Request logging for audit trail

## Next Steps

1. **Create Admin Interface**: Build UI for managing applications and API keys
2. **Add More Endpoints**: Implement additional endpoints as needed
3. **Request Logging Middleware**: Add middleware to log all requests (optional)
4. **API Documentation**: Generate OpenAPI/Swagger documentation
5. **Testing**: Write tests for external API endpoints

## Files Created/Modified

### New Files
- `backend/database/migrations/2025_11_24_170000_create_external_api_tables.php`
- `backend/app/Http/Middleware/ApiKeyMiddleware.php`
- `backend/app/Http/Middleware/ApiRateLimitMiddleware.php`
- `backend/routes/api-external.php`
- `backend/app/Http/Controllers/External/BaseController.php`
- `backend/app/Http/Controllers/External/HealthController.php`
- `backend/app/Http/Controllers/External/EventController.php`
- `backend/app/Http/Controllers/External/PlanController.php`
- `backend/app/Models/Application.php`
- `backend/app/Models/ApiKey.php`

### Modified Files
- `backend/bootstrap/app.php` - Added middleware aliases and external routes

## Notes

- The external API is completely separate from the frontend API (`/api/*` with Keycloak)
- All external API routes require API key authentication
- Rate limiting is enforced per application
- Scopes are checked on each request
- Plain API keys should be stored securely - they cannot be retrieved after creation

