<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class CreateMainDataPR extends Command
{
    protected $signature = 'main-data:create-pr {--dry-run : Show what would be done without creating PR}';
    protected $description = 'Create a GitHub PR with the updated main-tables-latest.json file';

    public function handle()
    {
        $this->info('🚀 Creating main data export and GitHub PR...');

        try {
            // Step 1: Read the JSON export file (should already exist from MainTablesController::export())
            // Use database_path to ensure we're always reading from backend/database/exports
            $jsonPath = database_path('exports/main-tables-latest.json');
            if (!file_exists($jsonPath)) {
                $this->error('JSON export file not found. Please export the main tables data first using the admin interface.');
                $this->line('  Expected location: ' . $jsonPath);
                return 1;
            }

            $this->info('📄 Reading JSON export file...');
            $jsonContent = file_get_contents($jsonPath);
            
            // Validate JSON
            $jsonData = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Invalid JSON in export file: ' . json_last_error_msg());
                return 1;
            }

            if (!isset($jsonData['_metadata'])) {
                $this->error('Invalid export file format - missing _metadata');
                return 1;
            }

            $this->info('  ✓ JSON file is valid');
            $tablesInMetadata = $jsonData['_metadata']['tables'] ?? [];
            $this->line('  - Tables in metadata: ' . count($tablesInMetadata));
            $this->line('  - Exported at: ' . ($jsonData['_metadata']['exported_at'] ?? 'unknown'));
            
            // Verify all tables from metadata exist in export data
            $missingTables = [];
            foreach ($tablesInMetadata as $table) {
                if (!isset($jsonData[$table])) {
                    $missingTables[] = $table;
                }
            }
            
            if (!empty($missingTables)) {
                $this->error('  ❌ Some tables from metadata are missing in export data: ' . implode(', ', $missingTables));
                $this->error('  Please re-export the main tables data.');
                return 1;
            }
            
            // Show table summary
            $this->line('  - Tables with data:');
            foreach ($tablesInMetadata as $table) {
                $recordCount = count($jsonData[$table] ?? []);
                $this->line("    • {$table}: {$recordCount} records");
            }

            // Step 2: Create GitHub PR
            if ($this->option('dry-run')) {
                $this->info('🔍 DRY RUN - Would create PR with:');
                $this->line('  - Branch: main-data-update-' . now()->format('Y-m-d-H-i-s'));
                $this->line('  - File: backend/database/exports/main-tables-latest.json');
                $this->line('  - Content length: ' . strlen($jsonContent) . ' characters');
                return 0;
            }

            $this->createGitHubPR($jsonContent);

            $this->info('✅ GitHub PR created successfully!');
            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Failed to create PR: ' . $e->getMessage());
            return 1;
        }
    }

    private function createGitHubPR($jsonContent)
    {
        $this->info('🐙 Creating GitHub PR...');

        // Configuration
        $repoOwner = 'hands-on-leipzig';
        $repoName = 'flow';
        $branchName = 'main-data-update-' . now()->format('Y-m-d-H-i-s');
        $baseBranch = 'main';

        $token = $this->githubToken();
        $http = Http::withToken($token)->withHeaders([
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'FLOW-MainData-Exporter',
        ]);

        // Step 1: Get current main branch SHA
        $this->line('  📋 Getting main branch reference...');
        $refResponse = $http->get("https://api.github.com/repos/{$repoOwner}/{$repoName}/git/ref/heads/{$baseBranch}");

        if (!$refResponse->successful()) {
            throw new \Exception($this->githubError('Failed to get main branch reference', $refResponse));
        }

        $mainSha = $refResponse->json()['object']['sha'];

        // Step 2: Create new branch
        $this->line('  🌿 Creating new branch: ' . $branchName);
        $branchResponse = $http->post("https://api.github.com/repos/{$repoOwner}/{$repoName}/git/refs", [
            'ref' => 'refs/heads/' . $branchName,
            'sha' => $mainSha
        ]);

        if (!$branchResponse->successful()) {
            throw new \Exception($this->githubError('Failed to create branch', $branchResponse));
        }

        // Step 3: Get current main-tables-latest.json SHA (if exists)
        $this->line('  📄 Getting current main-tables-latest.json SHA...');
        $fileResponse = $http->get("https://api.github.com/repos/{$repoOwner}/{$repoName}/contents/backend/database/exports/main-tables-latest.json");

        $fileSha = null;
        if ($fileResponse->successful()) {
            $fileSha = $fileResponse->json()['sha'];
        }

        // Step 4: Create/Update main-tables-latest.json
        $this->line('  💾 Uploading main-tables-latest.json...');
        $updateResponse = $http->put("https://api.github.com/repos/{$repoOwner}/{$repoName}/contents/backend/database/exports/main-tables-latest.json", [
            'message' => 'Update main-tables-latest.json with latest main data export',
            'content' => base64_encode($jsonContent),
            'branch' => $branchName,
            'sha' => $fileSha // null for new file, existing SHA for update
        ]);

        if (!$updateResponse->successful()) {
            throw new \Exception($this->githubError('Failed to update main-tables-latest.json', $updateResponse));
        }

        // Step 5: Create Pull Request
        $this->line('  🔀 Creating pull request...');
        $prResponse = $http->post("https://api.github.com/repos/{$repoOwner}/{$repoName}/pulls", [
                'title' => 'Update Main Data Export (JSON) - ' . now()->format('Y-m-d H:i:s'),
                'head' => $branchName,
                'base' => $baseBranch,
                'body' => $this->generatePRDescription(),
                'draft' => false
            ]);

        if (!$prResponse->successful()) {
            throw new \Exception($this->githubError('Failed to create pull request', $prResponse));
        }

        $prData = $prResponse->json();
        $this->info('🎉 Pull Request created: ' . $prData['html_url']);

        return $prData;
    }

    private function githubToken(): string
    {
        $configured = config('services.github.token');
        if (is_string($configured)) {
            $configured = $this->normalizeToken($configured);
            if ($configured !== '') {
                return $configured;
            }
        }

        $fromGh = $this->tokenFromGhCli();
        if ($fromGh !== null) {
            return $fromGh;
        }

        throw new \Exception('GITHUB_TOKEN is not set. Add it to .env, or sign in with `gh auth login`.');
    }

    private function tokenFromGhCli(): ?string
    {
        $gh = $this->ghBinary();
        if ($gh === null) {
            return null;
        }

        $result = Process::run([$gh, 'auth', 'token']);
        if (! $result->successful()) {
            return null;
        }

        $token = $this->normalizeToken($result->output());

        return $token !== '' ? $token : null;
    }

    private function normalizeToken(string $token): string
    {
        $token = trim($token);
        if (
            (str_starts_with($token, '"') && str_ends_with($token, '"'))
            || (str_starts_with($token, "'") && str_ends_with($token, "'"))
        ) {
            $token = substr($token, 1, -1);
        }

        $token = trim(preg_replace('/^(?:Bearer|token)\s+/i', '', trim($token)) ?? '');

        return $token;
    }

    private function githubError(string $action, Response $response): string
    {
        $decoded = json_decode($response->body(), true);
        $message = is_array($decoded) && is_string($decoded['message'] ?? null)
            ? $decoded['message']
            : trim(preg_replace('/\s+/', ' ', $response->body()) ?? '');
        $status = $response->status();
        $hint = match ($status) {
            401 => ' The GITHUB_TOKEN on this server was rejected.',
            404 => ' The token cannot read hands-on-leipzig/flow.',
            default => '',
        };

        return $action.': GitHub '.$status.' '.$message.'.'.$hint;
    }

    private function ghBinary(): ?string
    {
        $candidates = [
            '/opt/homebrew/bin/gh',
            '/usr/local/bin/gh',
            '/usr/bin/gh',
        ];

        $path = getenv('PATH') ?: '';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir !== '') {
                $candidates[] = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'gh';
            }
        }

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function generatePRDescription()
    {
        return "## 📊 Main Data Export Update

This PR contains an updated `main-tables-latest.json` file with the latest main data export.

### 📋 What's included:
- Updated JSON export file with current database state
- All `m_` table data preserved in JSON format
- The JSON file is used by `update_m_tables_from_json.php` during deployment

### 🚀 Deployment:
Once this PR is merged, the updated JSON file will be used during:
- Test environment deployment (`test` branch)
- Production environment deployment (`production` branch)
- The `update_m_tables_from_json.php` script will automatically read from `backend/database/exports/main-tables-latest.json`
- This script handles INSERT, UPDATE, DELETE operations with FK integrity checks enabled

### 📝 Generated on:
" . now()->format('Y-m-d H:i:s') . "

---
*This PR was automatically generated by the FLOW admin export system.*";
    }
}
