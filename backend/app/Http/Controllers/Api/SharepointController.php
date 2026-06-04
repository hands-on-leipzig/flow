<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SharepointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

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

    public function getFileLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'drive_id' => 'required|string|max:256',
            'item_id' => 'required|string|max:256',
        ]);

        try {
            return response()->json(
                $this->sharepointService->resolveGuestFileLink(
                    $validated['drive_id'],
                    $validated['item_id'],
                )
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('SharePoint file link failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Datei-Link konnte nicht aufgelöst werden.'], 500);
        }
    }

    public function streamFile(Request $request): Response|JsonResponse
    {
        $validated = $request->validate([
            'drive_id' => 'required|string|max:256',
            'item_id' => 'required|string|max:256',
        ]);

        try {
            $file = $this->sharepointService->streamFileContent(
                $validated['drive_id'],
                $validated['item_id'],
            );

            return response($file['body'], 200, [
                'Content-Type' => $file['content_type'],
                'Content-Disposition' => 'inline; filename="'.addslashes($file['filename']).'"',
                'Content-Length' => (string) strlen($file['body']),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('SharePoint file stream failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Datei konnte nicht geladen werden.'], 500);
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
