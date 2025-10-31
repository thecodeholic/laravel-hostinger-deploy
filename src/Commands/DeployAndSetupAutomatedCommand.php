<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

use Illuminate\Console\Command;

class DeployAndSetupAutomatedCommand extends Command
{
    /**
     * Show instructions for generating GitHub Personal Access Token.
     */
    protected function showGitHubTokenInstructions(): void
    {
        $this->info('🔑 To create a GitHub Personal Access Token:');
        $this->line('');
        $this->line('   1. Go to: https://github.com/settings/personal-access-tokens');
        $this->line('   2. Click "Generate new token" → "Generate new token (classic)"');
        $this->line('   3. Give your token a descriptive name (e.g., "Hostinger Deploy")');
        $this->line('   4. Set expiration (or no expiration)');
        $this->line('   5. Select the following permissions:');
        $this->line('');
        $this->info('   📋 Required Permissions:');
        $this->info('      ✓ Administration → Read and write');
        $this->info('        (Allows managing deploy keys for the repository)');
        $this->info('      ✓ Secrets → Read and write');
        $this->info('        (Allows creating/updating GitHub Actions secrets)');
        $this->info('      ✓ Metadata → Read-only');
        $this->info('        (Automatically selected, required for API access)');
        $this->line('');
        $this->warn('   6. Click "Generate token" and copy the token immediately');
        $this->warn('      ⚠️  You won\'t be able to see it again!');
        $this->line('');
        $this->info('💡 Tip: You can also set GITHUB_API_TOKEN in your .env file to skip this prompt.');
    }

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:deploy-and-setup-automated 
                            {--fresh : Delete and clone fresh repository}
                            {--site-dir= : Override site directory from config}
                            {--token= : GitHub Personal Access Token}
                            {--branch= : Override default branch}
                            {--php-version= : Override PHP version}';

    /**
     * The console command description.
     */
    protected $description = 'Deploy Laravel application to Hostinger and setup automated deployment via GitHub API';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Starting complete deployment and automated setup...');
        $this->line('');

        // Get or prompt for GitHub Personal Access Token if needed for Step 2
        $token = $this->option('token') ?: env('GITHUB_API_TOKEN');
        
        // Step 1: Deploy to server
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('Step 1: Deploying to Hostinger Server');
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');

        $deployOptions = [];
        if ($this->option('fresh')) {
            $deployOptions['--fresh'] = true;
        }
        if ($this->option('site-dir')) {
            $deployOptions['--site-dir'] = $this->option('site-dir');
        }
        
        // Pass token to deploy command if available
        if ($token) {
            $deployOptions['--token'] = $token;
        }

        // Call the deploy command - output will be shown in real-time
        // Pass through verbosity level to ensure all output is shown
        $deployOptions['-v'] = true;
        $deployExitCode = $this->call('hostinger:deploy-shared', $deployOptions);
        
        // If token was provided interactively in deploy command, capture it
        // (Note: This won't work if entered interactively in sub-command, so we'll prompt before Step 2 instead)
        
        if ($deployExitCode !== self::SUCCESS) {
            $this->line('');
            $this->error('❌ Deployment to server failed. Cannot proceed with automated setup.');
            return self::FAILURE;
        }

        $this->line('');
        $this->info('✅ Deployment to server completed successfully!');
        $this->line('');

        // Step 2: Setup automated deployment
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('Step 2: Setting up Automated Deployment');
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');

        // Check if token is needed for Step 2 (setup-automated-deploy requires it)
        if (!$token) {
            $this->line('');
            $this->info('💡 GitHub Personal Access Token is required for automated deployment setup.');
            $this->line('');
            $this->showGitHubTokenInstructions();
            $this->line('');
            
            $token = $this->secret('Enter your GitHub Personal Access Token (or press ENTER to skip):');
            
            if (empty(trim($token))) {
                $this->error('❌ GitHub Personal Access Token is required for automated deployment setup.');
                $this->warn('⚠️  Your application is deployed but automated deployment cannot be configured without a token.');
                return self::FAILURE;
            }
        }

        $setupOptions = [];
        $setupOptions['--token'] = $token;
        if ($this->option('branch')) {
            $setupOptions['--branch'] = $this->option('branch');
        }
        if ($this->option('php-version')) {
            $setupOptions['--php-version'] = $this->option('php-version');
        }

        // Call the setup command - output will be shown in real-time
        // Pass through verbosity level to ensure all output is shown
        $setupOptions['-v'] = true;
        $setupExitCode = $this->call('hostinger:setup-automated-deploy', $setupOptions);
        
        if ($setupExitCode !== self::SUCCESS) {
            $this->line('');
            $this->error('❌ Automated deployment setup failed.');
            $this->warn('⚠️  Your application is deployed but automated deployment is not configured.');
            return self::FAILURE;
        }

        $siteDir = $this->option('site-dir') ?: config('hostinger-deploy.deployment.site_dir');
        
        $this->line('');
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('🎉 Complete Setup Finished Successfully!');
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');
        $this->info('✅ Your Laravel application is deployed and configured for automated deployment!');
        $this->line('');
        $this->info("🌐 Your Laravel application: https://{$siteDir}");
        $this->line('');
        $this->info('🚀 Next steps:');
        $this->line('   1. Push your code to trigger the GitHub Actions workflow');
        $this->line('   2. Monitor deployments in the Actions tab on GitHub');
        $this->line('   3. Your application will automatically deploy on push');
        $this->line('');

        return self::SUCCESS;
    }
}

