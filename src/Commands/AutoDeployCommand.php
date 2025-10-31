<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

class AutoDeployCommand extends BaseHostingerCommand
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:auto-deploy';

    /**
     * The console command description.
     */
    protected $description = 'Setup SSH keys and display GitHub secrets for automated deployment';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔧 Setting up automated deployment...');

        // Validate configuration
        if (!$this->validateConfiguration()) {
            return self::FAILURE;
        }

        // Get repository information
        $repoInfo = $this->getRepositoryInfo();
        if (!$repoInfo) {
            $this->error('❌ Could not detect repository information. Please run this command from a Git repository.');
            return self::FAILURE;
        }

        $this->info("📦 Repository: {$repoInfo['owner']}/{$repoInfo['name']}");

        // Setup SSH connection
        $this->setupSshConnection();

        // Test SSH connection
        if (!$this->ssh->testConnection()) {
            $this->error('❌ SSH connection failed. Please check your SSH configuration.');
            return self::FAILURE;
        }

        $this->info('✅ SSH connection successful');

        // Setup SSH keys
        if (!$this->setupSshKeys(true)) {
            $this->error('❌ Failed to setup SSH keys');
            return self::FAILURE;
        }

        // Display GitHub secrets
        $this->displayGitHubSecrets($repoInfo);
        
        // Display next steps
        $this->displayNextSteps();

        return self::SUCCESS;
    }


    /**
     * Display next steps after manual setup.
     */
    protected function displayNextSteps(): void
    {
        // Display next steps
        $this->info('🎉 Setup completed! Next steps:');
        $this->line('');
        $this->line('1. Add all the secrets shown above to GitHub');
        $this->line('2. Add the deploy key to your repository');
        $this->line('3. Run: php artisan hostinger:publish-workflow');
        $this->line('4. Push your changes to trigger the workflow');
        $this->line('');
        $this->info('🚀 Your repository will now automatically deploy on push!');
    }
}
