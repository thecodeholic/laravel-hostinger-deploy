<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

use Illuminate\Support\Facades\File;

class PublishWorkflowCommand extends BaseHostingerCommand
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

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if we're in a Git repository
        $repoInfo = $this->getRepositoryInfo();
        if (!$repoInfo) {
            $this->error('❌ Not in a Git repository or could not detect repository information. Please run this command from a Git repository.');
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


}
