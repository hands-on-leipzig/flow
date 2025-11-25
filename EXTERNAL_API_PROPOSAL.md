# External API Proposal for FLOW

## Executive Summary

This document proposes a comprehensive architecture for exposing FLOW's functionality to external applications while maintaining clear separation from the frontend API and implementing robust authorization mechanisms.

## Current State Analysis

### Current Architecture
- **Backend**: Laravel with API routes in `routes/api.php`
- **Frontend**: Vue.js application consuming `/api/*` endpoints
- **Authentication**: Keycloak JWT tokens via `KeycloakJwtMiddleware`
- **Route Structure**: All routes under `/api/*` with mixed public/authenticated endpoints
- **Authorization**: Role-based (flow_admin, flow-tester) checked in middleware

### Challenges
1. Frontend and external APIs share the same route namespace (`/api/*`)
2. External applications may not use Keycloak for authentication
3. Need different authorization models (user-based vs application-based)
4. Rate limiting and usage tracking requirements differ
5. API versioning strategy needed

## Proposed Architecture

### 1. Route Separation Strategy

#### Option A: Path-Based Separation (Recommended)
```
/api/v1/*          → Frontend API (existing, Keycloak auth)
/api/external/v1/* → External API (API key/token auth)
```

**Advantages:**
- Clear separation
- Easy to apply different middleware
- Simple to version independently
- Minimal changes to existing code

**Implementation:**
```php
// routes/api.php (existing - no changes needed)
Route::middleware(['keycloak'])->group(function () {
    // Existing frontend routes
});

// routes/api-external.php (new file)
Route::prefix('external/v1')->group(function () {
    // External API routes
});
```

#### Option B: Subdomain Separation
```
api.flow.example.com/*     → Frontend API
external.flow.example.com/* → External API
```

**Advantages:**
- Complete isolation
- Can use different SSL certificates
- Easier to scale independently

**Disadvantages:**
- Requires DNS/SSL configuration
- More complex deployment

### 2. Authentication & Authorization Strategy

#### For External Applications

**Option 1: API Keys (Recommended for M2M)**
- Simple, stateless authentication
- Suitable for server-to-server communication
- Easy to revoke and rotate

**Implementation:**
```php
// Create API keys table
Schema::create('api_keys', function (Blueprint $table) {
    $table->id();
    $table->string('name'); // Human-readable identifier
    $table->string('key', 64)->unique(); // Hashed API key
    $table->string('key_hash', 64)->unique(); // For lookup
    $table->unsignedInteger('application_id')->nullable();
    $table->json('scopes')->nullable(); // Permissions
    $table->timestamp('last_used_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    
    $table->foreign('application_id')->references('id')->on('applications');
});

// Create applications table
Schema::create('applications', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('description')->nullable();
    $table->string('contact_email');
    $table->string('webhook_url')->nullable();
    $table->json('allowed_ips')->nullable(); // IP whitelist
    $table->unsignedInteger('rate_limit')->default(1000); // Requests per hour
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**Option 2: OAuth2 Client Credentials**
- Industry standard
- More complex but more flexible
- Better for multiple scopes/permissions

**Option 3: JWT Tokens (Issued by FLOW)**
- Similar to current Keycloak approach
- FLOW acts as token issuer
- Good for applications that need user context

#### Recommended: Hybrid Approach
- **API Keys** for server-to-server (M2M) communication
- **OAuth2** for applications that need user delegation
- **JWT** for applications that already have user authentication

### 3. Middleware Stack

```php
// app/Http/Middleware/ApiKeyMiddleware.php
class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->header('X-API-Key') 
               ?? $request->header('Authorization'); // Support: Bearer {key}
        
        if (!$apiKey) {
            return response()->json(['error' => 'API key required'], 401);
        }
        
        // Remove 'Bearer ' prefix if present
        $apiKey = str_replace('Bearer ', '', $apiKey);
        
        // Find API key in database
        $keyRecord = ApiKey::where('key_hash', hash('sha256', $apiKey))
            ->where('is_active', true)
            ->where(function($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->first();
        
        if (!$keyRecord) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }
        
        // Check IP whitelist if configured
        if ($keyRecord->application->allowed_ips) {
            $clientIp = $request->ip();
            if (!in_array($clientIp, $keyRecord->application->allowed_ips)) {
                return response()->json(['error' => 'IP not allowed'], 403);
            }
        }
        
        // Update last used timestamp
        $keyRecord->update(['last_used_at' => now()]);
        
        // Attach application context to request
        $request->merge([
            'api_key' => $keyRecord,
            'application' => $keyRecord->application,
        ]);
        
        return $next($request);
    }
}

// app/Http/Middleware/ApiRateLimitMiddleware.php
class ApiRateLimitMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $application = $request->get('application');
        
        if (!$application) {
            return $next($request);
        }
        
        $key = 'api_rate_limit:' . $application->id;
        $limit = $application->rate_limit;
        
        $current = Cache::get($key, 0);
        
        if ($current >= $limit) {
            return response()->json([
                'error' => 'Rate limit exceeded',
                'limit' => $limit,
                'retry_after' => Cache::get($key . ':reset', 3600)
            ], 429);
        }
        
        Cache::put($key, $current + 1, now()->addHour());
        Cache::put($key . ':reset', now()->addHour()->timestamp, now()->addHour());
        
        return $next($request);
    }
}
```

### 4. Route Structure Example

```php
// routes/api-external.php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\External\EventController;
use App\Http\Controllers\External\PlanController;
use App\Http\Controllers\External\TeamController;

Route::prefix('external/v1')->middleware([
    'api',
    'api.key',           // API key authentication
    'api.rate_limit',    // Rate limiting
    'api.logging',       // Request logging
])->group(function () {
    
    // Health check
    Route::get('/health', fn() => ['status' => 'ok']);
    
    // Events
    Route::prefix('events')->group(function () {
        Route::get('/', [EventController::class, 'index']);
        Route::get('/{id}', [EventController::class, 'show']);
        Route::get('/slug/{slug}', [EventController::class, 'showBySlug']);
    });
    
    // Plans (read-only for external)
    Route::prefix('plans')->group(function () {
        Route::get('/event/{eventId}', [PlanController::class, 'showByEvent']);
        Route::get('/{id}', [PlanController::class, 'show']);
        Route::get('/{id}/activities', [PlanController::class, 'activities']);
    });
    
    // Teams
    Route::prefix('teams')->group(function () {
        Route::get('/event/{eventId}', [TeamController::class, 'index']);
        Route::get('/{id}', [TeamController::class, 'show']);
    });
    
    // Webhooks (if needed)
    Route::prefix('webhooks')->middleware(['api.webhook'])->group(function () {
        Route::post('/plan-generated', [WebhookController::class, 'planGenerated']);
    });
});
```

### 5. Controller Structure

```php
// app/Http/Controllers/External/BaseController.php
namespace App\Http\Controllers\External;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

abstract class BaseController extends Controller
{
    protected function getApplication(Request $request)
    {
        return $request->get('application');
    }
    
    protected function hasScope(Request $request, string $scope): bool
    {
        $apiKey = $request->get('api_key');
        if (!$apiKey || !$apiKey->scopes) {
            return false;
        }
        
        return in_array($scope, $apiKey->scopes);
    }
    
    protected function requireScope(Request $request, string $scope)
    {
        if (!$this->hasScope($request, $scope)) {
            abort(403, "Scope '{$scope}' required");
        }
    }
}

// app/Http/Controllers/External/EventController.php
namespace App\Http\Controllers\External;

class EventController extends BaseController
{
    public function index(Request $request)
    {
        $this->requireScope($request, 'events:read');
        
        $application = $this->getApplication($request);
        
        // Filter events based on application's regional partners
        $events = Event::query()
            ->whereHas('regionalPartner', function($query) use ($application) {
                // Filter by application's allowed regional partners
            })
            ->paginate(20);
        
        return response()->json($events);
    }
    
    public function show(Request $request, $id)
    {
        $this->requireScope($request, 'events:read');
        
        $event = Event::findOrFail($id);
        
        // Check if application has access to this event's regional partner
        $this->checkAccess($request, $event);
        
        return response()->json($event);
    }
    
    protected function checkAccess(Request $request, Event $event)
    {
        $application = $this->getApplication($request);
        
        // Implement access control logic
        // e.g., check if application's regional partners include event's regional partner
    }
}
```

### 6. API Key Management

#### Admin Interface
Create admin routes for managing API keys:

```php
// routes/api.php (admin section)
Route::prefix('admin/api-keys')->middleware(['keycloak'])->group(function () {
    Route::get('/', [ApiKeyController::class, 'index']);
    Route::post('/', [ApiKeyController::class, 'store']);
    Route::put('/{id}', [ApiKeyController::class, 'update']);
    Route::delete('/{id}', [ApiKeyController::class, 'destroy']);
    Route::post('/{id}/regenerate', [ApiKeyController::class, 'regenerate']);
});
```

#### Key Generation
```php
// app/Services/ApiKeyService.php
class ApiKeyService
{
    public function generateKey(): string
    {
        return bin2hex(random_bytes(32)); // 64 character hex string
    }
    
    public function createKey(array $data): ApiKey
    {
        $plainKey = $this->generateKey();
        $keyHash = hash('sha256', $plainKey);
        
        $apiKey = ApiKey::create([
            'name' => $data['name'],
            'key_hash' => $keyHash,
            'application_id' => $data['application_id'],
            'scopes' => $data['scopes'] ?? [],
            'expires_at' => $data['expires_at'] ?? null,
        ]);
        
        // Return plain key only once (store securely)
        return $apiKey->setAttribute('plain_key', $plainKey);
    }
}
```

### 7. Scopes & Permissions

Define granular permissions:

```php
// config/api-scopes.php
return [
    'events' => [
        'events:read' => 'Read events',
        'events:write' => 'Create/update events',
    ],
    'plans' => [
        'plans:read' => 'Read plans',
        'plans:write' => 'Create/update plans',
        'plans:generate' => 'Generate plans',
    ],
    'teams' => [
        'teams:read' => 'Read teams',
        'teams:write' => 'Create/update teams',
    ],
    'publications' => [
        'publications:read' => 'Read publication data',
        'publications:write' => 'Update publication settings',
    ],
];
```

### 8. Request/Response Format

#### Standardized Response Format
```php
// app/Http/Responses/ApiResponse.php
class ApiResponse
{
    public static function success($data = null, $message = null, $code = 200)
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
        ], $code);
    }
    
    public static function error($message, $errors = null, $code = 400)
    {
        return response()->json([
            'success' => false,
            'error' => $message,
            'errors' => $errors,
            'timestamp' => now()->toIso8601String(),
        ], $code);
    }
}
```

#### Pagination
```php
// Use Laravel's built-in pagination
return ApiResponse::success([
    'data' => $events->items(),
    'pagination' => [
        'current_page' => $events->currentPage(),
        'per_page' => $events->perPage(),
        'total' => $events->total(),
        'last_page' => $events->lastPage(),
    ],
]);
```

### 9. Documentation

#### OpenAPI/Swagger Specification
Generate API documentation using Laravel tools:

```bash
composer require darkaonline/l5-swagger
```

Create API documentation at `/api/external/v1/documentation`

#### Example Endpoint Documentation
```php
/**
 * @OA\Get(
 *     path="/external/v1/events",
 *     summary="List events",
 *     tags={"Events"},
 *     security={{"apiKey": {}}},
 *     @OA\Parameter(
 *         name="page",
 *         in="query",
 *         description="Page number",
 *         required=false,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Successful response",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean"),
 *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Event"))
 *         )
 *     )
 * )
 */
```

### 10. Security Considerations

1. **HTTPS Only**: Enforce HTTPS for all external API requests
2. **IP Whitelisting**: Optional IP-based access control
3. **Rate Limiting**: Per-application rate limits
4. **Request Logging**: Log all API requests for audit
5. **Key Rotation**: Support for key expiration and rotation
6. **Scope Validation**: Strict scope checking on all endpoints
7. **Input Validation**: Validate all inputs (Laravel Form Requests)
8. **CORS**: Configure CORS appropriately for external domains

### 11. Monitoring & Analytics

```php
// app/Models/ApiRequestLog.php
Schema::create('api_request_logs', function (Blueprint $table) {
    $table->id();
    $table->unsignedInteger('application_id');
    $table->unsignedInteger('api_key_id')->nullable();
    $table->string('method', 10);
    $table->string('path');
    $table->integer('status_code');
    $table->integer('response_time_ms');
    $table->string('ip_address', 45);
    $table->text('user_agent')->nullable();
    $table->json('request_headers')->nullable();
    $table->json('response_headers')->nullable();
    $table->timestamp('created_at');
    
    $table->index(['application_id', 'created_at']);
    $table->index('status_code');
});
```

### 12. Migration Path

#### Phase 1: Foundation (Week 1-2)
1. Create database migrations for `applications` and `api_keys`
2. Create middleware for API key authentication
3. Create base controller for external API
4. Set up route file `routes/api-external.php`

#### Phase 2: Core Endpoints (Week 3-4)
1. Implement read-only endpoints (events, plans, teams)
2. Add scope checking
3. Implement rate limiting
4. Add request logging

#### Phase 3: Admin Interface (Week 5)
1. Create admin UI for managing applications and API keys
2. Add key generation and rotation
3. Add usage analytics dashboard

#### Phase 4: Documentation & Testing (Week 6)
1. Generate OpenAPI documentation
2. Write integration tests
3. Create example client code
4. Publish API documentation

## Implementation Checklist

- [ ] Create database migrations for applications and API keys
- [ ] Create `ApiKeyMiddleware`
- [ ] Create `ApiRateLimitMiddleware`
- [ ] Create `ApiLoggingMiddleware`
- [ ] Create `routes/api-external.php`
- [ ] Create `External` namespace controllers
- [ ] Create `BaseController` for external API
- [ ] Implement API key generation service
- [ ] Create admin interface for key management
- [ ] Add scope/permission system
- [ ] Implement request logging
- [ ] Add rate limiting
- [ ] Generate API documentation
- [ ] Write tests
- [ ] Update deployment documentation

## Questions to Resolve

1. **Authentication Method**: API keys, OAuth2, or both?
2. **Scope Granularity**: How fine-grained should permissions be?
3. **Rate Limits**: What are appropriate limits per application?
4. **Webhooks**: Do external applications need webhook notifications?
5. **Versioning**: How to handle API versioning (URL vs header)?
6. **Data Filtering**: Should applications only see data from their regional partners?

## Recommendations

1. **Start with API Keys**: Simpler to implement, can add OAuth2 later if needed
2. **Use Path-Based Separation**: `/api/external/v1/*` is clean and simple
3. **Implement Scopes Early**: Better to have granular permissions from the start
4. **Log Everything**: Request logging is essential for debugging and security
5. **Document First**: Create OpenAPI spec before implementing endpoints
6. **Test Thoroughly**: External APIs need comprehensive integration tests

## Next Steps

1. Review and approve this proposal
2. Decide on authentication method (API keys recommended)
3. Create detailed endpoint specifications
4. Begin Phase 1 implementation
5. Set up development environment for testing

