<?php

namespace App\Print;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GotenbergChromium
{
    public const WAIT_FOR_PRINT_READY = 'document.querySelector(\'[data-print-ready="true"]\') !== null';

    public function configured(): bool
    {
        return filled(config('services.gotenberg.url'));
    }

    public function printPageUrl(int $planId, int $roleId, string $paper = 'a4'): string
    {
        $base = rtrim((string) (config('services.gotenberg.print_page_base_url') ?: config('app.frontend_url')), '/');
        $url = $base.'/public-schedule/'.$planId.'/print?role='.$roleId;
        if ($paper === 'a3') {
            $url .= '&size=a3';
        }

        return $url;
    }

    /**
     * @throws RuntimeException
     */
    public function convertUrl(string $pageUrl, string $paper = 'a4'): string
    {
        $base = rtrim((string) config('services.gotenberg.url'), '/');
        if ($base === '') {
            throw new RuntimeException('PDF-Dienst ist nicht konfiguriert.');
        }

        $timeout = max(10, (int) config('services.gotenberg.timeout', 90));
        $a3 = $paper === 'a3';

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(5)
                ->asMultipart()
                ->post($base.'/forms/chromium/convert/url', [
                    'url' => $pageUrl,
                    'waitForExpression' => self::WAIT_FOR_PRINT_READY,
                    'preferCssPageSize' => 'true',
                    'paperWidth' => $a3 ? '11.69' : '8.27',
                    'paperHeight' => $a3 ? '16.54' : '11.7',
                    'marginTop' => '0.12',
                    'marginBottom' => '0.12',
                    'marginLeft' => '0.12',
                    'marginRight' => '0.12',
                    'scale' => '1',
                    'printBackground' => 'true',
                    'emulatedMediaType' => 'print',
                    'skipNetworkIdleEvent' => 'true',
                    'failOnHttpStatusCodes' => '[499,599]',
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('PDF-Dienst nicht erreichbar.', 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('PDF-Erzeugung fehlgeschlagen.');
        }

        $body = $response->body();
        if (! str_starts_with($body, '%PDF')) {
            throw new RuntimeException('PDF-Erzeugung fehlgeschlagen.');
        }

        return $body;
    }
}
