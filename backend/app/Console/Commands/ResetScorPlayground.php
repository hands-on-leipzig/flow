<?php

namespace App\Console\Commands;

use App\Scor\ScorLifecycle;
use Illuminate\Console\Command;

class ResetScorPlayground extends Command
{
    protected $signature = 'scor:reset-playground';

    protected $description = 'Clear SCOR practice data the day before an event';

    public function handle(ScorLifecycle $lifecycle): int
    {
        $count = $lifecycle->resetPlaygroundDue();
        $this->info('Spielwiese zurückgesetzt: '.$count);

        return self::SUCCESS;
    }
}
