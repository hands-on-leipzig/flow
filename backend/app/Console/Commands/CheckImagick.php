<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckImagick extends Command
{
    protected $signature = 'check:imagick';
    protected $description = 'Prüft, ob Imagick installiert und funktionsfähig ist.';

    public function handle()
    {
        if (extension_loaded('imagick')) {
            $version = \Imagick::getVersion();
            $this->info('Imagick ist installiert: ' . $version['versionString']);
        } else {
            $this->error('Imagick ist NICHT installiert oder nicht geladen.');
        }
    }
}