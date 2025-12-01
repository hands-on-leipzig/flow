<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class ExportMainDataToCsv extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'main-data:export-csv {--zip : Create a ZIP file containing all CSV files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export all m_ tables to CSV files for review';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('🔄 Exporting m_ tables to CSV files...');
        
        // Dynamically discover all m_ tables from the database
        $masterTables = $this->discoverMTables();
        
        if (empty($masterTables)) {
            $this->error('No m_ tables found in the database');
            return 1;
        }
        
        $this->info('Found ' . count($masterTables) . ' m_ tables');
        
        // Create export directory
        $exportDir = storage_path('app/exports/m_tables_' . date('Y-m-d_His'));
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }
        
        $exportedFiles = [];
        $errors = [];
        
        foreach ($masterTables as $table) {
            try {
                $this->info("Exporting {$table}...");
                
                // Get data from database
                $rows = DB::table($table)->get();
                
                if ($rows->isEmpty()) {
                    $this->warn("  ⚠ No data found in {$table}");
                    continue;
                }
                
                // Convert to array
                $data = $rows->map(function($row) {
                    return (array) $row;
                })->toArray();
                
                // Get column names from first row
                $columns = array_keys($data[0]);
                
                // Create CSV file
                $csvFile = $exportDir . '/' . $table . '.csv';
                $handle = fopen($csvFile, 'w');
                
                if ($handle === false) {
                    throw new \Exception("Failed to create CSV file: {$csvFile}");
                }
                
                // Write UTF-8 BOM for Excel compatibility
                fwrite($handle, "\xEF\xBB\xBF");
                
                // Write header row
                fputcsv($handle, $columns);
                
                // Write data rows
                foreach ($data as $row) {
                    // Convert array values to strings, handling nulls
                    $csvRow = array_map(function($value) {
                        if ($value === null) {
                            return '';
                        }
                        if (is_bool($value)) {
                            return $value ? '1' : '0';
                        }
                        return (string) $value;
                    }, array_values($row));
                    
                    fputcsv($handle, $csvRow);
                }
                
                fclose($handle);
                
                $exportedFiles[] = $csvFile;
                $this->line("  ✓ Exported " . count($data) . " records to {$table}.csv");
                
            } catch (\Exception $e) {
                $errorMsg = "Failed to export {$table}: " . $e->getMessage();
                $this->error("  ✗ {$errorMsg}");
                $errors[] = $errorMsg;
            }
        }
        
        if (empty($exportedFiles)) {
            $this->error('No files were exported');
            rmdir($exportDir);
            return 1;
        }
        
        $this->info("\n✅ Successfully exported " . count($exportedFiles) . " tables");
        
        if (!empty($errors)) {
            $this->warn("\n⚠ Errors occurred:");
            foreach ($errors as $error) {
                $this->warn("  - {$error}");
            }
        }
        
        // Create ZIP file if requested
        if ($this->option('zip')) {
            $zipFile = storage_path('app/exports/m_tables_' . date('Y-m-d_His') . '.zip');
            $zip = new ZipArchive();
            
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach ($exportedFiles as $file) {
                    $zip->addFile($file, basename($file));
                }
                $zip->close();
                
                $this->info("\n📦 Created ZIP file: {$zipFile}");
                $this->line("   Size: " . $this->formatBytes(filesize($zipFile)));
                
                // Clean up individual CSV files
                foreach ($exportedFiles as $file) {
                    unlink($file);
                }
                rmdir($exportDir);
                
                $this->info("\n📁 Export location: {$zipFile}");
            } else {
                $this->error("Failed to create ZIP file");
                $this->info("\n📁 Export location: {$exportDir}");
            }
        } else {
            $this->info("\n📁 Export location: {$exportDir}");
        }
        
        return 0;
    }
    
    /**
     * Dynamically discover all m_ tables from the database
     */
    private function discoverMTables(): array
    {
        $databaseName = DB::connection()->getDatabaseName();
        $tables = DB::select("SHOW TABLES");
        $tableKey = "Tables_in_{$databaseName}";
        
        $mTableNames = [];
        foreach ($tables as $table) {
            $tableName = $table->$tableKey;
            if (str_starts_with($tableName, 'm_')) {
                $mTableNames[] = $tableName;
            }
        }
        
        // Sort alphabetically for consistency
        sort($mTableNames);
        
        return $mTableNames;
    }
    
    /**
     * Format bytes to human-readable size
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

