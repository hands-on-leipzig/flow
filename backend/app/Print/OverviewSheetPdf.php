<?php

namespace App\Print;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class OverviewSheetPdf
{
    public const TTL_MINUTES = 15;

    public static function cacheKey(string $id): string
    {
        return 'print.overview.'.$id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function remember(string $id, array $payload): void
    {
        Cache::put(self::cacheKey($id), $payload, now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(string $id): ?array
    {
        $payload = Cache::get(self::cacheKey($id));

        return is_array($payload) ? $payload : null;
    }

    public function run(string $id): void
    {
        $payload = $this->payload($id);
        if ($payload === null) {
            return;
        }

        try {
            $gotenberg = app(GotenbergChromium::class);
            $planId = (int) ($payload['plan_id'] ?? 0);
            $roleId = (int) ($payload['role_id'] ?? 0);
            $paper = (string) ($payload['paper'] ?? 'a4');
            $bytes = $gotenberg->convertUrl($gotenberg->printPageUrl($planId, $roleId, $paper), $paper);
            $this->merge($id, [
                'status' => 'done',
                'pdf' => base64_encode($bytes),
            ]);
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'PDF-Erzeugung fehlgeschlagen.';
            $this->merge($id, [
                'status' => 'failed',
                'error' => $message,
            ]);
        }
    }

    public function spawn(string $id): void
    {
        if (app()->runningUnitTests()) {
            $this->run($id);

            return;
        }

        // php artisan serve has one thread: Chromium must not wait on this
        // same process, so start a second PHP. php-fpm (FLOW cloud) kills that
        // child when the request ends — keep working after the 202 instead.
        if (PHP_SAPI === 'cli-server') {
            $cmd = sprintf(
                'nohup %s %s print:overview-sheet %s > /dev/null 2>&1 &',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(base_path('artisan')),
                escapeshellarg($id),
            );
            exec($cmd);

            return;
        }

        dispatch(function () use ($id): void {
            ignore_user_abort(true);
            set_time_limit(120);
            app(self::class)->run($id);
        })->afterResponse();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function merge(string $id, array $extra): void
    {
        $current = $this->payload($id) ?? [];
        $this->remember($id, array_merge($current, $extra));
    }
}
