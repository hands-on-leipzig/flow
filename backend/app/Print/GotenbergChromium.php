<?php

namespace App\Print;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GotenbergChromium
{
    public const WAIT_FOR_PRINT_READY = 'document.querySelector(\'[data-print-ready="true"]\') !== null';

    public const HELLO_WORLD_HTML = <<<'HTML'
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Hallo Welt</title>
</head>
<body style="font-family: sans-serif; padding: 2.5rem;">
  <h1>Hallo Welt</h1>
  <p>FLOW erreicht den PDF-Dienst.</p>
</body>
</html>
HTML;

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
        $a3 = $paper === 'a3';

        return $this->postConvert('/forms/chromium/convert/url', [
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
    }

    /**
     * @throws RuntimeException
     */
    public function convertHtml(string $html): string
    {
        return $this->postConvert(
            '/forms/chromium/convert/html',
            [
                'paperWidth' => '8.27',
                'paperHeight' => '11.7',
                'printBackground' => 'true',
            ],
            [
                'name' => 'files',
                'contents' => $html,
                'filename' => 'index.html',
            ],
        );
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array{name: string, contents: string, filename: string}|null  $file
     *
     * @throws RuntimeException
     */
    private function postConvert(string $path, array $fields, ?array $file = null): string
    {
        $base = rtrim((string) config('services.gotenberg.url'), '/');
        if ($base === '') {
            throw new RuntimeException('PDF-Dienst ist nicht konfiguriert.');
        }

        $timeout = max(10, (int) config('services.gotenberg.timeout', 90));
        $username = (string) config('services.gotenberg.username');
        $password = (string) config('services.gotenberg.password');

        try {
            $request = Http::timeout($timeout)->connectTimeout(5);
            if ($username !== '' && $password !== '') {
                $request = $request->withBasicAuth($username, $password);
            }
            $request = $file === null
                ? $request->asMultipart()
                : $request->attach($file['name'], $file['contents'], $file['filename']);
            $response = $request->post($base.$path, $fields);
        } catch (ConnectionException $e) {
            throw new RuntimeException('PDF-Dienst nicht erreichbar.', 0, $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('PDF-Dienst: Anmeldung fehlgeschlagen.');
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
