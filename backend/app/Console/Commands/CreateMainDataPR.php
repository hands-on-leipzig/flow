<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class CreateMainDataPR extends Command
{
    protected $signature = 'main-data:create-pr {--dry-run : Show what would be done without creating PR}';
    protected $description = 'Export main data and create a GitHub PR with the updated MainDataSeeder.php';

    public function handle()
    {
        $this->info('🚀 Creating main data export and GitHub PR...');

        try {
            // Step 1: Export current data
            $this->info('📤 Exporting current main data...');
            $this->call('main-data:export');

            // Step 2: Generate MainDataSeeder.php
            $this->info('🔧 Generating MainDataSeeder.php...');
            $this->call('main-data:generate-seeder');

            // Step 3: Read the generated seeder
            $seederPath = database_path('seeders/MainDataSeeder.php');
            if (!file_exists($seederPath)) {
                $this->error('MainDataSeeder.php not found after generation');
                return 1;
            }

            $seederContent = file_get_contents($seederPath);

            // Step 4: Create GitHub PR
            if ($this->option('dry-run')) {
                $this->info('🔍 DRY RUN - Would create PR with:');
                $this->line('  - Branch: main-data-update-' . now()->format('Y-m-d-H-i-s'));
                $this->line('  - File: database/seeders/MainDataSeeder.php');
                $this->line('  - Content length: ' . strlen($seederContent) . ' characters');
                return 0;
            }

            $this->createGitHubPR($seederContent);

            $this->info('✅ GitHub PR created successfully!');
            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Failed to create PR: ' . $e->getMessage());
            return 1;
        }
    }

    private function createGitHubPR($seederContent)
    {
        $this->info('🐙 Creating GitHub PR...');

        // Configuration
        $repoOwner = 'hands-on-leipzig';
        $repoName = 'flow';
        $branchName = 'main-data-update-' . now()->format('Y-m-d-H-i-s');
        $baseBranch = 'main';

        // GitHub token from environment
        $token = env('GITHUB_TOKEN');
        if (!$token) {
            throw new \Exception('GITHUB_TOKEN environment variable not set');
        }

        $headers = [
            'Authorization' => 'token ' . $token,
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'FLOW-MainData-Exporter'
        ];

        // Step 1: Get current main branch SHA
        $this->line('  📋 Getting main branch reference...');
        $refResponse = Http::withHeaders($headers)
            ->get("https://api.github.com/repos/{$repoOwner}/{$repoName}/git/refs/heads/{$baseBranch}");

        if (!$refResponse->successful()) {
            throw new \Exception('Failed to get main branch reference: ' . $refResponse->body());
        }

        $mainSha = $refResponse->json()['object']['sha'];

        // Step 2: Create new branch
        $this->line('  🌿 Creating new branch: ' . $branchName);
        $branchResponse = Http::withHeaders($headers)
            ->post("https://api.github.com/repos/{$repoOwner}/{$repoName}/git/refs", [
                'ref' => 'refs/heads/' . $branchName,
                'sha' => $mainSha
            ]);

        if (!$branchResponse->successful()) {
            throw new \Exception('Failed to create branch: ' . $branchResponse->body());
        }

        // Step 3: Get current MainDataSeeder.php SHA (if exists)
        $this->line('  📄 Getting current MainDataSeeder.php SHA...');
        $fileResponse = Http::withHeaders($headers)
            ->get("https://api.github.com/repos/{$repoOwner}/{$repoName}/contents/database/seeders/MainDataSeeder.php");

        $fileSha = null;
        if ($fileResponse->successful()) {
            $fileSha = $fileResponse->json()['sha'];
        }

        // Step 4: Create/Update MainDataSeeder.php
        $this->line('  💾 Uploading MainDataSeeder.php...');
        $updateResponse = Http::withHeaders($headers)
            ->put("https://api.github.com/repos/{$repoOwner}/{$repoName}/contents/database/seeders/MainDataSeeder.php", [
                'message' => 'Update MainDataSeeder.php with latest main data export',
                'content' => base64_encode($seederContent),
                'branch' => $branchName,
                'sha' => $fileSha // null for new file, existing SHA for update
            ]);

        if (!$updateResponse->successful()) {
            throw new \Exception('Failed to update MainDataSeeder.php: ' . $updateResponse->body());
        }

        // Step 5: Create Pull Request
        $this->line('  🔀 Creating pull request...');
        $prResponse = Http::withHeaders($headers)
            ->post("https://api.github.com/repos/{$repoOwner}/{$repoName}/pulls", [
                'title' => 'Update Main Data Seeder - ' . now()->format('Y-m-d H:i:s'),
                'head' => $branchName,
                'base' => $baseBranch,
                'body' => $this->generatePRDescription(),
                'draft' => false
            ]);

        if (!$prResponse->successful()) {
            throw new \Exception('Failed to create pull request: ' . $prResponse->body());
        }

        $prData = $prResponse->json();
        $this->info('🎉 Pull Request created: ' . $prData['html_url']);

        return $prData;
    }

    private function generatePRDescription()
    {
        return "## 📊 Main Data Export Update

This PR contains an updated `MainDataSeeder.php` file generated from the current main data export.

### 📋 What's included:
- Updated main data seeder with current database state
- All `m_` table data preserved
- Ready for deployment to test/production environments

### 🚀 Deployment:
Once this PR is merged, the updated seeder will be used during:
- Test environment deployment (`test` branch)
- Production environment deployment (`production` branch)

### 📝 Generated on:
" . now()->format('Y-m-d H:i:s') . "

---
*This PR was automatically generated by the FLOW admin export system.*";
    }
}
