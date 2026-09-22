<?php

namespace App\Services;

use App\Models\DisplaySession;
use App\Models\Event;
use App\Models\OneLinkAccess;
use App\Models\SurfaceAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicAccessRecorder
{
    public const SURFACE_KINDS = [
        'form_volunteer',
        'form_team',
        'app_check_in',
        'app_cockpit',
    ];

    public const DISPLAY_KIND = 'carousel';

    public const HEARTBEAT_GAP_MINUTES = 15;

    public function isPreviewRequest(Request $request): bool
    {
        foreach (['preview', '_pv'] as $key) {
            $query = $request->query($key);
            if ($query !== null && $query !== '') {
                return true;
            }
            $body = $request->input($key);
            if ($body !== null && $body !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{success: bool, skipped?: bool, error?: string, status?: int}
     */
    public function recordOneLink(Request $request, int $eventId): array
    {
        if ($this->isPreviewRequest($request)) {
            return ['success' => true, 'skipped' => true];
        }

        if (! Event::query()->where('id', $eventId)->exists()) {
            return ['success' => false, 'error' => 'Event not found', 'status' => 400];
        }

        try {
            OneLinkAccess::create($this->accessAttributes($request, $eventId));

            return ['success' => true];
        } catch (\Throwable $e) {
            Log::error('Failed to log one-link access', [
                'error' => $e->getMessage(),
                'event_id' => $eventId,
            ]);

            return ['success' => false, 'error' => 'Failed to log access', 'status' => 500];
        }
    }

    /**
     * @return array{success: bool, skipped?: bool, error?: string, status?: int}
     */
    public function recordSurface(Request $request, int $eventId, string $kind): array
    {
        if (! in_array($kind, self::SURFACE_KINDS, true)) {
            return ['success' => true, 'skipped' => true];
        }

        if ($this->isPreviewRequest($request)) {
            return ['success' => true, 'skipped' => true];
        }

        if (! Event::query()->where('id', $eventId)->exists()) {
            return ['success' => false, 'error' => 'Event not found', 'status' => 400];
        }

        try {
            SurfaceAccess::create(array_merge($this->accessAttributes($request, $eventId), [
                'kind' => $kind,
            ]));

            return ['success' => true];
        } catch (\Throwable $e) {
            Log::error('Failed to log surface access', [
                'error' => $e->getMessage(),
                'event_id' => $eventId,
                'kind' => $kind,
            ]);

            return ['success' => false, 'error' => 'Failed to log access', 'status' => 500];
        }
    }

    /**
     * @return array{success: bool, skipped?: bool, error?: string, status?: int}
     */
    public function heartbeatDisplay(Request $request, int $eventId, string $deviceId): array
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $deviceId)) {
            return ['success' => false, 'error' => 'device_id is invalid', 'status' => 400];
        }

        if ($this->isPreviewRequest($request)) {
            return ['success' => true, 'skipped' => true];
        }

        if (! Event::query()->where('id', $eventId)->exists()) {
            return ['success' => false, 'error' => 'Event not found', 'status' => 400];
        }

        $now = Carbon::now();

        try {
            $existing = DisplaySession::query()
                ->where('event', $eventId)
                ->where('device_id', $deviceId)
                ->where('kind', self::DISPLAY_KIND)
                ->where('last_seen', '>=', $now->copy()->subMinutes(self::HEARTBEAT_GAP_MINUTES))
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                $existing->last_seen = $now;
                $existing->save();
            } else {
                DisplaySession::create([
                    'event' => $eventId,
                    'device_id' => $deviceId,
                    'kind' => self::DISPLAY_KIND,
                    'start' => $now,
                    'last_seen' => $now,
                    'user_agent' => $request->userAgent(),
                    'ip_hash' => $this->ipHash($request),
                ]);
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            Log::error('Failed to record display heartbeat', [
                'error' => $e->getMessage(),
                'event_id' => $eventId,
            ]);

            return ['success' => false, 'error' => 'Failed to log access', 'status' => 500];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function accessAttributes(Request $request, int $eventId): array
    {
        $referrer = $request->header('referer');
        $acceptLanguage = $request->header('accept-language');
        $connectionType = $request->input('connection_type');
        $source = $request->input('source', 'unknown');
        if ($source === 'qr') {
            $source = 'qr';
        } elseif ($referrer) {
            $source = 'referrer';
        } else {
            $source = 'direct';
        }

        return [
            'event' => $eventId,
            'access_date' => Carbon::now()->toDateString(),
            'access_time' => Carbon::now(),
            'user_agent' => $request->userAgent(),
            'referrer' => $referrer,
            'ip_hash' => $this->ipHash($request),
            'accept_language' => $acceptLanguage ? substr($acceptLanguage, 0, 50) : null,
            'screen_width' => $request->input('screen_width'),
            'screen_height' => $request->input('screen_height'),
            'viewport_width' => $request->input('viewport_width'),
            'viewport_height' => $request->input('viewport_height'),
            'device_pixel_ratio' => $request->input('device_pixel_ratio'),
            'touch_support' => $request->input('touch_support'),
            'connection_type' => $connectionType ? substr((string) $connectionType, 0, 20) : null,
            'source' => $source,
        ];
    }

    private function ipHash(Request $request): string
    {
        return hash('sha256', $request->ip().config('app.key'));
    }
}
