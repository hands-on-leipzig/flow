<?php

namespace App\Services;

use App\Models\SharepointConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SharepointService
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    public function isConfigured(): bool
    {
        $config = SharepointConfig::instance();

        return $config->is_enabled
            && filled($config->tenant_id)
            && filled($config->client_id)
            && filled($config->client_secret)
            && filled($config->folder_url);
    }

    public function getPublicStatus(): array
    {
        $config = SharepointConfig::instance();

        return [
            'configured' => $this->isConfigured(),
            'folder_name' => $config->cached_root_name,
        ];
    }

    public function getAdminConfig(): array
    {
        $config = SharepointConfig::instance();

        return [
            'tenant_id' => $config->tenant_id,
            'client_id' => $config->client_id,
            'has_client_secret' => filled($config->client_secret),
            'folder_url' => $config->folder_url,
            'is_enabled' => $config->is_enabled,
            'cached_root_name' => $config->cached_root_name,
        ];
    }

    public function updateConfig(array $data): SharepointConfig
    {
        $config = SharepointConfig::instance();
        $folderChanged = array_key_exists('folder_url', $data)
            && $data['folder_url'] !== $config->folder_url;

        if (array_key_exists('tenant_id', $data)) {
            $config->tenant_id = $data['tenant_id'] ?: null;
        }
        if (array_key_exists('client_id', $data)) {
            $config->client_id = $data['client_id'] ?: null;
        }
        if (! empty($data['client_secret'])) {
            $config->client_secret = Crypt::encryptString($data['client_secret']);
        }
        if (array_key_exists('folder_url', $data)) {
            $config->folder_url = $data['folder_url'] ?: null;
        }
        if (array_key_exists('is_enabled', $data)) {
            $config->is_enabled = (bool) $data['is_enabled'];
        }

        if ($folderChanged) {
            $config->cached_drive_id = null;
            $config->cached_root_item_id = null;
            $config->cached_root_name = null;
        }

        $config->save();
        Cache::forget('sharepoint_access_token');

        return $config;
    }

    /**
     * @return array{items: array, breadcrumbs: array, current_item_id: ?string, folder_name: ?string}
     */
    public function listFolder(?string $itemId = null): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('SharePoint ist nicht konfiguriert.');
        }

        $config = SharepointConfig::instance();
        $token = $this->getAccessToken($config);

        if ($itemId === null) {
            $root = $this->resolveRootFolder($config, $token);
            $itemId = $root['id'];
            $driveId = $root['drive_id'];
            $folderName = $root['name'];
            $breadcrumbs = [['id' => $itemId, 'name' => $folderName]];
        } else {
            $driveId = $config->cached_drive_id;
            if (! $driveId) {
                $root = $this->resolveRootFolder($config, $token);
                $driveId = $root['drive_id'];
            }
            $folderName = null;
            $breadcrumbs = $this->buildBreadcrumbs($driveId, $itemId, $token);
        }

        $children = $this->fetchChildren($driveId, $itemId, $token);

        return [
            'items' => $children,
            'breadcrumbs' => $breadcrumbs,
            'current_item_id' => $itemId,
            'folder_name' => $folderName ?? ($breadcrumbs[0]['name'] ?? null),
        ];
    }

    public function testConnection(): array
    {
        $result = $this->listFolder();

        return [
            'success' => true,
            'folder_name' => $result['folder_name'],
            'item_count' => count($result['items']),
        ];
    }

    private function getAccessToken(SharepointConfig $config): string
    {
        $cacheKey = 'sharepoint_access_token_'.$config->client_id;

        return Cache::remember($cacheKey, 3500, function () use ($config) {
            $secret = Crypt::decryptString($config->client_secret);
            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$config->tenant_id}/oauth2/v2.0/token",
                [
                    'client_id' => $config->client_id,
                    'client_secret' => $secret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]
            );

            if (! $response->successful()) {
                Log::error('SharePoint token request failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                throw new \RuntimeException(
                    'Azure-Anmeldung fehlgeschlagen. Prüfe Tenant-ID, Client-ID und Client-Secret.'
                );
            }

            $token = $response->json('access_token');
            if (! $token) {
                throw new \RuntimeException('Kein Zugriffstoken von Azure erhalten.');
            }

            return $token;
        });
    }

    /**
     * @return array{id: string, drive_id: string, name: string}
     */
    private function resolveRootFolder(SharepointConfig $config, string $token): array
    {
        if ($config->cached_drive_id && $config->cached_root_item_id) {
            return [
                'id' => $config->cached_root_item_id,
                'drive_id' => $config->cached_drive_id,
                'name' => $config->cached_root_name ?? 'Ordner',
            ];
        }

        $shareId = $this->encodeShareUrl($config->folder_url);
        $response = Http::withToken($token)
            ->get(self::GRAPH_BASE."/shares/{$shareId}/driveItem");

        if (! $response->successful()) {
            Log::error('SharePoint folder resolve failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException(
                'SharePoint-Ordner konnte nicht geöffnet werden. Prüfe den Link und die App-Berechtigungen (Sites.Read.All).'
            );
        }

        $item = $response->json();
        if (($item['folder'] ?? null) === null && ($item['file'] ?? null) !== null) {
            throw new \RuntimeException('Der konfigurierte Link zeigt auf eine Datei, nicht auf einen Ordner.');
        }

        $driveId = $item['parentReference']['driveId'] ?? null;
        $itemId = $item['id'] ?? null;
        $name = $item['name'] ?? 'Ordner';

        if (! $driveId || ! $itemId) {
            throw new \RuntimeException('SharePoint-Ordner konnte nicht aufgelöst werden.');
        }

        $config->cached_drive_id = $driveId;
        $config->cached_root_item_id = $itemId;
        $config->cached_root_name = $name;
        $config->save();

        return [
            'id' => $itemId,
            'drive_id' => $driveId,
            'name' => $name,
        ];
    }

    private function encodeShareUrl(string $url): string
    {
        $base64 = base64_encode($url);
        $base64 = rtrim($base64, '=');
        $base64 = strtr($base64, '+/', '-_');

        return 'u!'.$base64;
    }

    private function fetchChildren(string $driveId, string $itemId, string $token): array
    {
        $response = Http::withToken($token)
            ->get(self::GRAPH_BASE."/drives/{$driveId}/items/{$itemId}/children", [
                '$orderby' => 'name',
                '$select' => 'id,name,size,lastModifiedDateTime,webUrl,folder,file',
            ]);

        if (! $response->successful()) {
            Log::error('SharePoint list children failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('Ordnerinhalt konnte nicht geladen werden.');
        }

        $items = [];
        foreach ($response->json('value', []) as $entry) {
            $isFolder = isset($entry['folder']);
            $items[] = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'type' => $isFolder ? 'folder' : 'file',
                'size' => $isFolder ? null : ($entry['size'] ?? null),
                'modified' => $entry['lastModifiedDateTime'] ?? null,
                'web_url' => $entry['webUrl'] ?? null,
            ];
        }

        usort($items, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $items;
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function buildBreadcrumbs(string $driveId, string $itemId, string $token): array
    {
        $config = SharepointConfig::instance();
        $crumbs = [];
        $currentId = $itemId;
        $maxDepth = 20;

        while ($currentId && $maxDepth-- > 0) {
            if ($config->cached_root_item_id && $currentId === $config->cached_root_item_id) {
                array_unshift($crumbs, [
                    'id' => $currentId,
                    'name' => $config->cached_root_name ?? 'Ordner',
                ]);
                break;
            }

            $response = Http::withToken($token)
                ->get(self::GRAPH_BASE."/drives/{$driveId}/items/{$currentId}", [
                    '$select' => 'id,name,parentReference',
                ]);

            if (! $response->successful()) {
                break;
            }

            $item = $response->json();
            array_unshift($crumbs, [
                'id' => $item['id'],
                'name' => $item['name'],
            ]);

            $parentId = $item['parentReference']['id'] ?? null;
            if (! $parentId || $parentId === $config->cached_root_item_id) {
                if ($config->cached_root_item_id && $parentId === $config->cached_root_item_id) {
                    array_unshift($crumbs, [
                        'id' => $config->cached_root_item_id,
                        'name' => $config->cached_root_name ?? 'Ordner',
                    ]);
                }
                break;
            }

            $currentId = $parentId;
        }

        if (empty($crumbs) && $config->cached_root_item_id) {
            return [[
                'id' => $config->cached_root_item_id,
                'name' => $config->cached_root_name ?? 'Ordner',
            ]];
        }

        return $crumbs;
    }
}
