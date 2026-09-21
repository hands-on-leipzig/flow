<?php

namespace App\Console\Commands;

use App\Print\OverviewSheetPdf;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PrintOverviewSheetCommand extends Command
{
    protected $signature = 'print:overview-sheet {id : Print job UUID}';

    protected $description = 'Render a Publikum overview PDF via Gotenberg';

    public function handle(OverviewSheetPdf $pdf): int
    {
        $id = (string) $this->argument('id');
        if (! Str::isUuid($id)) {
            $this->error('Invalid id');

            return self::FAILURE;
        }

        $pdf->run($id);

        return self::SUCCESS;
    }
}
