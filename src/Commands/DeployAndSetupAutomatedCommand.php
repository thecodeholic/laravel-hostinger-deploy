<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

class DeployAndSetupAutomatedCommand extends BaseHostingerCommand
{

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:deploy-and-setup-cicd 
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
        $deployExitCode = $this->call('hostinger:deploy', $deployOptions);
        
        // If token was provided interactively in deploy command, capture it
        // (Note: This won't work if entered interactively in sub-command, so we'll prompt before Step 2 instead)
        
        if ($deployExitCode !== self::SUCCESS) {
            $this->line('');
            $this->error('❌ Deployment to server failed. Cannot proceed with automated setup.');
            return self::FAILURE;
        }

        $this->line('');

        // Step 2: Setup automated deployment
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('Step 2: Setting up Automated Deployment');
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');

        // Pass token to setup command if available (let setup-cicd handle the prompt if missing)
        $setupOptions = [];
        if ($token) {
            $setupOptions['--token'] = $token;
        }
        if ($this->option('branch')) {
            $setupOptions['--branch'] = $this->option('branch');
        }
        if ($this->option('php-version')) {
            $setupOptions['--php-version'] = $this->option('php-version');
        }

        // Call the setup command - output will be shown in real-time
        // Pass through verbosity level to ensure all output is shown
        $setupOptions['-v'] = true;
        $setupExitCode = $this->call('hostinger:setup-cicd', $setupOptions);
        
        if ($setupExitCode !== self::SUCCESS) {
            $this->line('');
            $this->error('❌ Automated deployment setup failed.');
            return self::FAILURE;
        }

        $siteDir = $this->getSiteDir();
        
        $this->line('');
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('🎉 Complete Setup Finished Successfully!');
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');
        $this->info("🌐 Your Laravel application: https://{$siteDir}");
        $this->line('');
        $this->info('🚀 Next steps:');
        $this->line('   1. Review the workflow file at .github/workflows/hostinger-deploy.yml');
        $this->line('   2. Commit and push the workflow file:');
        $this->line('      git add .github/workflows/hostinger-deploy.yml');
        $this->line('      git commit -m "Add Hostinger deployment workflow"');
        $this->line('      git push');
        $this->line('   3. Monitor deployments in the Actions tab on GitHub');
        $this->line('   4. Your application will automatically deploy on push');
        $this->line('');

        return self::SUCCESS;
    }
}

