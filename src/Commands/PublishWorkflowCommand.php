<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use TheCodeholic\LaravelHostingerDeploy\Services\GitHubActionsService;

class PublishWorkflowCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:publish-workflow 
                            {--branch= : Override default branch}
                            {--php-version= : Override PHP version}';

    /**
     * The console command description.
     */
    protected $description = 'Publish GitHub Actions workflow file for automated deployment';

    protected GitHubActionsService $github;

    public function __construct()
    {
        parent::__construct();
        $this->github = new GitHubActionsService();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if we're in a Git repository
        if (!$this->github->isGitRepository()) {
            $this->error('❌ Not in a Git repository. Please run this command from a Git repository.');
            return self::FAILURE;
        }

        // Get repository information
        $repoInfo = $this->github->getRepositoryInfo();
        if (!$repoInfo) {
            $this->error('❌ Could not detect repository information.');
            return self::FAILURE;
        }

        // Get configuration
        $branch = $this->option('branch') ?: $this->github->getCurrentBranch() ?: config('hostinger-deploy.github.default_branch', 'main');
        $phpVersion = $this->option('php-version') ?: config('hostinger-deploy.github.php_version', '8.3');
        $workflowFile = config('hostinger-deploy.github.workflow_file', '.github/workflows/hostinger-deploy.yml');

        // Check if file already exists
        if (File::exists($workflowFile)) {
            $choice = $this->choice(
                "Workflow file already exists at {$workflowFile}. What would you like to do?",
                ['Overwrite', 'Skip'],
                0
            );

            if ($choice === 'Skip') {
                $this->info('⚠️  Skipping workflow file creation.');
                return self::SUCCESS;
            }
        }

        // Create .github/workflows directory if it doesn't exist
        $workflowDir = dirname($workflowFile);
        if (!File::exists($workflowDir)) {
            File::makeDirectory($workflowDir, 0755, true);
        }

        // Generate workflow content
        $workflowContent = $this->generateWorkflowContent($branch, $phpVersion);

        // Write workflow file
        if (File::put($workflowFile, $workflowContent)) {
            $this->info("✅ Workflow file published: {$workflowFile}");
        } else {
            $this->error("❌ Failed to create workflow file: {$workflowFile}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Generate workflow content with placeholders replaced.
     */
    protected function generateWorkflowContent(string $branch, string $phpVersion): string
    {
        $stubPath = __DIR__ . '/../../stubs/hostinger-deploy.yml';
        
        if (!File::exists($stubPath)) {
            throw new \Exception("Workflow stub not found: {$stubPath}");
        }

        $content = File::get($stubPath);
        
        // Replace placeholders
        $content = str_replace('{{BRANCH}}', $branch, $content);
        $content = str_replace('{{PHP_VERSION}}', $phpVersion, $content);

        return $content;
    }

}
