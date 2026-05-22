<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SharepointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SharepointController extends Controller
{
    public function __construct(
        private readonly SharepointService $sharepointService,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json($this->sharepointService->getPublicStatus());
    }

    public function listDocuments(Request $request): JsonResponse
    {
        try {
            if (! $this->sharepointService->isConfigured()) {
                return response()->json([
                    'configured' => false,
                    'items' => [],
                    'breadcrumbs' => [],
                ]);
            }

            $itemId = $request->query('item_id');
            $data = $this->sharepointService->listFolder($itemId ?: null);
            $data['configured'] = true;

            return response()->json($data);
        } catch (\RuntimeException $e) {
            return response()->json([
                'configured' => true,
                'error' => $e->getMessage(),
                'items' => [],
                'breadcrumbs' => [],
            ], 422);
        } catch (\Exception $e) {
            Log::error('SharePoint list documents failed', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Dokumente konnten nicht geladen werden.',
            ], 500);
        }
    }

    public function getAdminConfig(): JsonResponse
    {
        return response()->json($this->sharepointService->getAdminConfig());
    }

    public function updateAdminConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|string|max:64',
            'client_id' => 'nullable|string|max:64',
            'client_secret' => 'nullable|string|max:500',
            'folder_url' => 'nullable|string|max:2000',
            'is_enabled' => 'nullable|boolean',
        ]);

        $this->sharepointService->updateConfig($validated);

        return response()->json([
            'success' => true,
            'config' => $this->sharepointService->getAdminConfig(),
        ]);
    }

    public function testConnection(): JsonResponse
    {
        try {
            $result = $this->sharepointService->testConnection();

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('SharePoint test connection failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Verbindungstest fehlgeschlagen.',
            ], 500);
        }
    }
}
