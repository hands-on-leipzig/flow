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
            'folder_url' => $config->folder_url,
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
        $folderWebUrl = $this->getItemWebUrl($driveId, $itemId, $token)
            ?: trim((string) $config->folder_url);

        return [
            'items' => $children,
            'breadcrumbs' => $breadcrumbs,
            'current_item_id' => $itemId,
            'drive_id' => $driveId,
            'folder_name' => $folderName ?? ($breadcrumbs[count($breadcrumbs) - 1]['name'] ?? null),
            'folder_web_url' => $folderWebUrl ?: null,
        ];
    }

    /**
     * Resolve a guest-accessible URL for a file in the configured folder.
     *
     * @return array{url: string, via: string, use_stream: bool, drive_id: string, item_id: string}
     */
    public function resolveGuestFileLink(string $driveId, string $itemId): array
    {
        $driveId = trim($driveId);
        $itemId = trim($itemId);
        if ($driveId === '' || $itemId === '') {
            throw new \RuntimeException('drive_id und item_id sind erforderlich.');
        }

        $config = SharepointConfig::instance();
        $token = $this->getAccessToken($config);
        $this->assertItemAllowed($driveId, $itemId, $token);

        $folderShareUrl = trim((string) $config->folder_url);
        $resolved = $this->resolveGuestFileLinkInternal($driveId, $itemId, $token, $folderShareUrl);

        if ($resolved['url'] !== '') {
            return [
                'url' => $resolved['url'],
                'via' => $resolved['via'],
                'use_stream' => false,
                'drive_id' => $driveId,
                'item_id' => $itemId,
            ];
        }

        return [
            'url' => '',
            'via' => 'stream_proxy',
            'use_stream' => true,
            'drive_id' => $driveId,
            'item_id' => $itemId,
        ];
    }

    /**
     * @return array{body: string, content_type: string, filename: string}
     */
    public function streamFileContent(string $driveId, string $itemId): array
    {
        $driveId = trim($driveId);
        $itemId = trim($itemId);
        if ($driveId === '' || $itemId === '') {
            throw new \RuntimeException('drive_id und item_id sind erforderlich.');
        }

        $config = SharepointConfig::instance();
        $token = $this->getAccessToken($config);
        $this->assertItemAllowed($driveId, $itemId, $token);

        $binary = $this->fetchDriveItemContentBinary($driveId, $itemId, $token);
        if ($binary === null) {
            throw new \RuntimeException('Datei konnte nicht aus SharePoint geladen werden.');
        }

        return $binary;
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

    private function getItemWebUrl(string $driveId, string $itemId, string $token): ?string
    {
        $response = Http::withToken($token)
            ->get(self::GRAPH_BASE.'/drives/'.rawurlencode($driveId)
                .'/items/'.rawurlencode($itemId), [
                    '$select' => 'webUrl',
                ]);

        if (! $response->successful()) {
            return null;
        }

        $url = trim((string) ($response->json('webUrl') ?? ''));

        return $url !== '' ? $url : null;
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
                'drive_id' => $driveId,
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

    private function assertItemAllowed(string $driveId, string $itemId, string $token): void
    {
        if (! $this->isItemUnderRoot($driveId, $itemId, $token)) {
            throw new \RuntimeException('Datei liegt nicht im konfigurierten SharePoint-Ordner.');
        }
    }

    private function isItemUnderRoot(string $driveId, string $itemId, string $token): bool
    {
        $config = SharepointConfig::instance();
        $root = $this->resolveRootFolder($config, $token);
        $rootDriveId = $root['drive_id'];
        $rootItemId = $root['id'];

        if ($driveId !== $rootDriveId) {
            return false;
        }
        if ($itemId === $rootItemId) {
            return true;
        }

        $current = $itemId;
        for ($depth = 0; $depth < 40; $depth++) {
            $response = Http::withToken($token)
                ->get(self::GRAPH_BASE."/drives/{$driveId}/items/{$current}", [
                    '$select' => 'id,parentReference',
                ]);

            if (! $response->successful()) {
                return false;
            }

            $item = $response->json();
            if (($item['id'] ?? null) === $rootItemId) {
                return true;
            }

            $parentId = $item['parentReference']['id'] ?? null;
            if (! $parentId || $parentId === $current) {
                return false;
            }

            $current = $parentId;
        }

        return false;
    }

    /**
     * @return array{url: string, via: string}
     */
    private function resolveGuestFileLinkInternal(
        string $driveId,
        string $itemId,
        string $token,
        string $folderShareUrl,
    ): array {
        $out = ['url' => '', 'via' => ''];

        $tryAccept = function (string $candidate, string $via) use (&$out, $driveId, $itemId, $token): bool {
            $candidate = trim($candidate);
            if ($candidate === '') {
                return false;
            }
            if ($this->guestUrlTargetsFile($candidate, $driveId, $itemId, $token)) {
                $out['url'] = $candidate;
                $out['via'] = $via;

                return true;
            }

            return false;
        };

        $existing = $this->findAnonymousLinkOnItem($driveId, $itemId, $token);
        if ($tryAccept($existing, 'existing_anonymous_link')) {
            return $out;
        }

        $viaShares = $this->guestUrlViaItemSharesApi($driveId, $itemId, $token);
        if ($tryAccept($viaShares, 'shares_driveItem')) {
            return $out;
        }

        if ($folderShareUrl !== '') {
            $colonFile = $this->colonFileUrlFromFolderShare($folderShareUrl, $itemId);
            if ($tryAccept($colonFile, 'colon_file_from_folder')) {
                return $out;
            }
        }

        $createUrl = self::GRAPH_BASE.'/drives/'.rawurlencode($driveId)
            .'/items/'.rawurlencode($itemId).'/createLink';
        $createAttempts = [
            ['scope' => 'anonymous', 'retainInheritedPermissions' => false, 'via' => 'createLink_anonymous_file'],
            ['scope' => 'anonymous', 'retainInheritedPermissions' => true, 'via' => 'createLink_anonymous_inherit'],
            ['scope' => 'organization', 'retainInheritedPermissions' => true, 'via' => 'createLink_organization'],
        ];

        foreach ($createAttempts as $attempt) {
            $response = Http::withToken($token)
                ->acceptJson()
                ->post($createUrl, [
                    'type' => 'view',
                    'retainInheritedPermissions' => ! empty($attempt['retainInheritedPermissions']),
                    'scope' => $attempt['scope'],
                ]);

            if ($response->successful()) {
                $webUrl = $response->json('link.webUrl');
                if ($webUrl && $tryAccept($webUrl, $attempt['via'])) {
                    return $out;
                }
            }
        }

        return $out;
    }

    private function guestUrlTargetsFile(string $candidateUrl, string $driveId, string $itemId, string $token): bool
    {
        $candidateUrl = trim($candidateUrl);
        $itemId = trim($itemId);
        if ($candidateUrl === '' || $itemId === '') {
            return false;
        }

        $meta = $this->shareUrlToDriveItemMeta($candidateUrl, $token);
        if ($meta['id'] !== '') {
            return $meta['id'] === $itemId && ! $meta['is_folder'];
        }

        if (stripos($candidateUrl, $itemId) !== false && stripos($candidateUrl, '/:f:/') === false) {
            return true;
        }

        return false;
    }

    private function findAnonymousLinkOnItem(string $driveId, string $itemId, string $token): string
    {
        $response = Http::withToken($token)
            ->get(self::GRAPH_BASE.'/drives/'.rawurlencode($driveId)
                .'/items/'.rawurlencode($itemId).'/permissions');

        if (! $response->successful()) {
            return '';
        }

        foreach ($response->json('value', []) as $perm) {
            if (! is_array($perm) || empty($perm['link']) || ! is_array($perm['link'])) {
                continue;
            }
            $link = $perm['link'];
            $scope = isset($link['scope']) ? strtolower((string) $link['scope']) : '';
            if ($scope !== 'anonymous') {
                continue;
            }
            if (! empty($link['webUrl'])) {
                $candidate = trim((string) $link['webUrl']);
                if ($this->guestUrlTargetsFile($candidate, $driveId, $itemId, $token)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function guestUrlViaItemSharesApi(string $driveId, string $itemId, string $token): string
    {
        $response = Http::withToken($token)
            ->get(self::GRAPH_BASE.'/drives/'.rawurlencode($driveId)
                .'/items/'.rawurlencode($itemId), [
                    '$select' => 'webUrl,id,file',
                ]);

        if (! $response->successful()) {
            return '';
        }

        $meta = $response->json();
        if (empty($meta['webUrl']) || empty($meta['file'])) {
            return '';
        }

        $candidates = array_unique(array_filter([
            trim((string) $meta['webUrl']),
            preg_replace('/\?.*$/', '', trim((string) $meta['webUrl'])),
        ]));

        foreach ($candidates as $url) {
            $shareId = $this->encodeShareUrl($url);
            $shareResponse = Http::withToken($token)
                ->get(self::GRAPH_BASE.'/shares/'.rawurlencode($shareId).'/driveItem', [
                    '$select' => 'webUrl,id,folder,file',
                ]);

            if (! $shareResponse->successful()) {
                continue;
            }

            $di = $shareResponse->json();
            if (! empty($di['driveItem']) && is_array($di['driveItem'])) {
                $di = $di['driveItem'];
            }
            if (empty($di['id']) || (string) $di['id'] !== $itemId || ! empty($di['folder'])) {
                continue;
            }
            if (! empty($di['webUrl'])) {
                $guest = trim((string) $di['webUrl']);
                if ($this->guestUrlTargetsFile($guest, $driveId, $itemId, $token)) {
                    return $guest;
                }
                if (stripos($guest, $itemId) !== false) {
                    return $guest;
                }
            }
        }

        return '';
    }

    private function colonFileUrlFromFolderShare(string $folderShareUrl, string $fileItemId): string
    {
        $folderShareUrl = trim($folderShareUrl);
        $fileItemId = trim($fileItemId);
        if ($folderShareUrl === '' || $fileItemId === '') {
            return '';
        }

        if (! preg_match('#^(https?://[^/]+).*/:f:/([rs])/([^/]+)/([^/?#]+)#i', $folderShareUrl, $m)) {
            return '';
        }

        $origin = $m[1];
        $kind = strtolower((string) $m[2]);
        $siteSlug = (string) $m[3];
        $query = '';
        if (preg_match('/\?([^#]+)/', $folderShareUrl, $qm)) {
            $query = '?'.$qm[1];
        }

        foreach (['b', 'w', 'x'] as $seg) {
            $candidate = $origin.'/:'. $seg.':/'.$kind.'/'.$siteSlug.'/'.$fileItemId.$query;
            if ($candidate !== $folderShareUrl) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @return array{id: string, is_folder: bool}
     */
    private function shareUrlToDriveItemMeta(string $shareUrl, string $token): array
    {
        $empty = ['id' => '', 'is_folder' => false];
        $shareUrl = trim($shareUrl);
        if ($shareUrl === '') {
            return $empty;
        }

        $shareId = $this->encodeShareUrl($shareUrl);
        $response = Http::withToken($token)
            ->get(self::GRAPH_BASE.'/shares/'.rawurlencode($shareId).'/driveItem', [
                '$select' => 'id,folder,file',
            ]);

        if (! $response->successful()) {
            return $empty;
        }

        $item = $response->json();
        if (! empty($item['driveItem']) && is_array($item['driveItem'])) {
            $item = $item['driveItem'];
        }

        return [
            'id' => ! empty($item['id']) ? trim((string) $item['id']) : '',
            'is_folder' => ! empty($item['folder']),
        ];
    }

    /**
     * @return array{body: string, content_type: string, filename: string}|null
     */
    private function fetchDriveItemContentBinary(string $driveId, string $itemId, string $token): ?array
    {
        $metaResponse = Http::withToken($token)
            ->get(self::GRAPH_BASE.'/drives/'.rawurlencode($driveId)
                .'/items/'.rawurlencode($itemId), [
                    '$select' => 'name,file',
                ]);

        $name = 'download';
        if ($metaResponse->successful()) {
            $meta = $metaResponse->json();
            if (! empty($meta['name'])) {
                $name = (string) $meta['name'];
            }
            if (empty($meta['file'])) {
                return null;
            }
        }

        $contentResponse = Http::withToken($token)
            ->withOptions(['allow_redirects' => true])
            ->get(self::GRAPH_BASE.'/drives/'.rawurlencode($driveId)
                .'/items/'.rawurlencode($itemId).'/content');

        if (! $contentResponse->successful()) {
            Log::error('SharePoint file stream failed', [
                'status' => $contentResponse->status(),
                'drive_id' => $driveId,
                'item_id' => $itemId,
            ]);

            return null;
        }

        $body = $contentResponse->body();
        if ($body === '') {
            return null;
        }

        $contentType = $contentResponse->header('Content-Type') ?: 'application/octet-stream';

        return [
            'body' => $body,
            'content_type' => $contentType,
            'filename' => $name,
        ];
    }
}
