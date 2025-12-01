<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    /**
     * Get all applications with their API keys
     */
    public function index(): JsonResponse
    {
        try {
            $applications = Application::with(['apiKeys' => function ($query) {
                $query->orderBy('created_at', 'desc');
            }])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json($applications);
        } catch (\Exception $e) {
            Log::error('Failed to fetch applications', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch applications',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single application
     */
    public function show($id): JsonResponse
    {
        try {
            $application = Application::with('apiKeys')->findOrFail($id);
            return response()->json($application);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Application not found'
            ], 404);
        }
    }

    /**
     * Create a new application
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'description' => 'nullable|string',
                'contact_email' => 'required|email|max:255',
                'webhook_url' => 'nullable|url|max:500',
                'allowed_ips' => 'nullable|array',
                'rate_limit' => 'nullable|integer|min:1|max:100000',
                'is_active' => 'nullable|boolean',
            ]);

            $application = Application::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'contact_email' => $validated['contact_email'],
                'webhook_url' => $validated['webhook_url'] ?? null,
                'allowed_ips' => $validated['allowed_ips'] ?? null,
                'rate_limit' => $validated['rate_limit'] ?? 1000,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            Log::info('Application created', ['application_id' => $application->id]);

            return response()->json($application, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create application', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to create application',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an application
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $application = Application::findOrFail($id);

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:100',
                'description' => 'nullable|string',
                'contact_email' => 'sometimes|required|email|max:255',
                'webhook_url' => 'nullable|url|max:500',
                'allowed_ips' => 'nullable|array',
                'rate_limit' => 'nullable|integer|min:1|max:100000',
                'is_active' => 'nullable|boolean',
            ]);

            $application->update($validated);

            Log::info('Application updated', ['application_id' => $application->id]);

            return response()->json($application);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update application', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to update application',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an application
     */
    public function destroy($id): JsonResponse
    {
        try {
            $application = Application::findOrFail($id);
            $application->delete();

            Log::info('Application deleted', ['application_id' => $id]);

            return response()->json(['message' => 'Application deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to delete application', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to delete application',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new API key for an application
     */
    public function createApiKey(Request $request, $applicationId): JsonResponse
    {
        try {
            $application = Application::findOrFail($applicationId);

            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'scopes' => 'nullable|array',
                'expires_at' => 'nullable|date',
            ]);

            // Generate API key
            $plainKey = bin2hex(random_bytes(32)); // 64 character hex string
            $keyHash = hash('sha256', $plainKey);

            $apiKey = ApiKey::create([
                'name' => $validated['name'],
                'key_hash' => $keyHash,
                'application_id' => $application->id,
                'scopes' => $validated['scopes'] ?? [],
                'expires_at' => $validated['expires_at'] ? new \DateTime($validated['expires_at']) : null,
                'is_active' => true,
            ]);

            Log::info('API key created', [
                'application_id' => $application->id,
                'api_key_id' => $apiKey->id
            ]);

            // Return the API key with the plain key (only shown once)
            return response()->json([
                'api_key' => $apiKey,
                'plain_key' => $plainKey, // Only returned on creation
                'message' => 'API key created. Store this key securely - it will not be shown again.'
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create API key', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to create API key',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an API key
     */
    public function updateApiKey(Request $request, $applicationId, $apiKeyId): JsonResponse
    {
        try {
            $apiKey = ApiKey::where('application_id', $applicationId)
                ->findOrFail($apiKeyId);

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:100',
                'scopes' => 'nullable|array',
                'expires_at' => 'nullable|date',
                'is_active' => 'nullable|boolean',
            ]);

            $apiKey->update($validated);

            Log::info('API key updated', ['api_key_id' => $apiKey->id]);

            return response()->json($apiKey);
        } catch (\Exception $e) {
            Log::error('Failed to update API key', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to update API key',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an API key
     */
    public function deleteApiKey($applicationId, $apiKeyId): JsonResponse
    {
        try {
            $apiKey = ApiKey::where('application_id', $applicationId)
                ->findOrFail($apiKeyId);

            $apiKey->delete();

            Log::info('API key deleted', ['api_key_id' => $apiKeyId]);

            return response()->json(['message' => 'API key deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to delete API key', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to delete API key',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}

