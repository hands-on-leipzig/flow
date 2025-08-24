<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Alle PHP-Dateien direkt unter legacy/generator/extra
 * 1:1 per URL aufrufen – aber im Laravel-Kontext.
 *
 * Beispiel:
 *  URL: /legacy/generator/extra/generator_test_frame.php
 *  Datei: base_path('legacy/generator/extra/generator_test_frame.php')
 */
Route::any('legacy/generator/extra/{file}', function (string $file) {
    // Nur *.php erlauben (kein Slash im Dateinamen -> nur dieses Verzeichnis, keine Subordner)
    if (!preg_match('/^[A-Za-z0-9._-]+\.php$/', $file)) {
        abort(404);
    }

    $baseDir   = realpath(base_path('legacy/generator/extra'));
    $target    = realpath($baseDir . DIRECTORY_SEPARATOR . $file);

    // Sicherheitsgurt: existiert die Datei und liegt sie wirklich in baseDir?
    if (!$baseDir || !$target || strncmp($target, $baseDir . DIRECTORY_SEPARATOR, strlen($baseDir) + 1) !== 0) {
        abort(404);
    }

    // Ausführen im Laravel-Kontext, Output abfangen und zurückgeben
    $cwd = getcwd();
    ob_start();
    try {
        chdir(dirname($target));     // falls das Skript relative Includes nutzt
        require $target;
        $content = ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        chdir($cwd);
        throw $e;                    // Laravel-Handler zeigt den Fehler
    } finally {
        @chdir($cwd);
    }

    return response($content);
});

// Premiliary schedule overview routes TODO: remove when frontend has its own way to show the schedule overview
Route::get('/schedule/{plan}/show', function (int $plan) {
    $view = request()->query('view', 'roles');
    if (!in_array($view, ['roles','teams','rooms'], true)) $view = 'roles';

    return view('test.roles', [
        'plan'  => $plan,
        'view'  => $view,
        'event' => null,
    ]);
});
