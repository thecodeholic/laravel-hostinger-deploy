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
        $this->info('📄 Publishing GitHub Actions workflow...');

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

        $this->info("📦 Repository: {$repoInfo['owner']}/{$repoInfo['name']}");
        $this->info("🌿 Branch: {$repoInfo['branch']}");

        // Get configuration
        $branch = $this->option('branch') ?: $this->getBranch();
        $phpVersion = $this->option('php-version') ?: config('hostinger-deploy.github.php_version', '8.3');
        $workflowFile = config('hostinger-deploy.github.workflow_file', '.github/workflows/hostinger-deploy.yml');

        // Create .github/workflows directory if it doesn't exist
        $workflowDir = dirname($workflowFile);
        if (!File::exists($workflowDir)) {
            File::makeDirectory($workflowDir, 0755, true);
            $this->info("📁 Created directory: {$workflowDir}");
        }

        // Generate workflow content
        $workflowContent = $this->generateWorkflowContent($branch, $phpVersion);

        // Write workflow file
        if (File::put($workflowFile, $workflowContent)) {
            $this->info("✅ Workflow file created: {$workflowFile}");
        } else {
            $this->error("❌ Failed to create workflow file: {$workflowFile}");
            return self::FAILURE;
        }

        // Display next steps
        $this->displayNextSteps($repoInfo);

        return self::SUCCESS;
    }

    /**
     * Get the branch to use for the workflow.
     */
    protected function getBranch(): string
    {
        $currentBranch = $this->github->getCurrentBranch();
        $defaultBranch = config('hostinger-deploy.github.default_branch', 'main');

        if ($currentBranch && $currentBranch !== $defaultBranch) {
            if ($this->confirm("Use current branch '{$currentBranch}' for the workflow? (default: {$defaultBranch})")) {
                return $currentBranch;
            }
        }

        return $defaultBranch;
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

    /**
     * Display next steps for the user.
     */
    protected function displayNextSteps(array $repoInfo): void
    {
        $this->line('');
        $this->info('🎉 GitHub Actions workflow published successfully!');
        $this->line('');
        $this->warn('📋 Next steps:');
        $this->line('');
        $this->line('1. Add the following secrets to your GitHub repository:');
        $this->line('   Go to: ' . $repoInfo['secrets_url']);
        $this->line('');
        $this->line('   Required secrets:');
        $this->line('   - SSH_HOST: Your Hostinger server IP address');
        $this->line('   - SSH_USERNAME: Your Hostinger SSH username');
        $this->line('   - SSH_PORT: Your Hostinger SSH port (usually 22)');
        $this->line('   - SSH_KEY: Your private SSH key');
        $this->line('');
        $this->line('2. Add the following variable to your GitHub repository:');
        $this->line('   Go to: ' . $repoInfo['variables_url']);
        $this->line('');
        $this->line('   Required variable:');
        $this->line('   - WEBSITE_FOLDER: Your Hostinger website folder name');
        $this->line('');
        $this->line('3. Run the auto-deploy command to get your SSH keys:');
        $this->line('   php artisan hostinger:auto-deploy');
        $this->line('');
        $this->line('4. Push changes to trigger the workflow:');
        $this->line('   git add .');
        $this->line('   git commit -m "Add Hostinger deployment workflow"');
        $this->line('   git push');
        $this->line('');
        $this->info('🚀 Your repository will now automatically deploy on push!');
    }
}
