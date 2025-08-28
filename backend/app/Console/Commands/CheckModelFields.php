<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckModelFields extends Command
{
    protected $signature = 'check:modelfields';
    protected $description = 'Vergleicht die $fillable-Felder der Models mit der tatsächlichen Tabellenstruktur';

    protected $models = [
        \App\Models\QRun::class,
        \App\Models\QPlan::class,
        \App\Models\QPlanTeam::class,
        \App\Models\QPlanMatch::class,
    ];

    public function handle()
    {
        foreach ($this->models as $modelClass) {
            $model = new $modelClass();
            $table = $model->getTable();
            $fillable = $model->getFillable();
            $columns = DB::getSchemaBuilder()->getColumnListing($table);

            $missingInModel = array_diff($columns, $fillable);
            $missingInTable = array_diff($fillable, $columns);

            $this->info("Tabelle: $table  (Model: " . class_basename($modelClass) . ")");
            
            if (empty($missingInModel) && empty($missingInTable)) {
                $this->line("  ✅ Alles passt.\n");
            } else {
                if ($missingInModel) {
                    $this->warn("  ❌ Fehlt im Model (\$fillable): " . implode(', ', $missingInModel));
                }
                if ($missingInTable) {
                    $this->warn("  ❌ Nicht in Tabelle: " . implode(', ', $missingInTable));
                }
                $this->line('');
            }
        }

        return 0;
    }
}