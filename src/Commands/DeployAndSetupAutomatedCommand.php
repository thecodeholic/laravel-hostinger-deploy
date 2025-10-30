<?php

namespace Zura\HostingerDeploy\Commands;

use Illuminate\Console\Command;

class DeployAndSetupAutomatedCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:deploy-and-setup-automated 
                            {--fresh : Delete and clone fresh repository}
                            {--site-dir= : Override site directory from config}
                            {--token= : GitHub API token}
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

        // Call the deploy command - output will be shown in real-time
        // Pass through verbosity level to ensure all output is shown
        $deployOptions['-v'] = true;
        $deployExitCode = $this->call('hostinger:deploy-shared', $deployOptions);
        
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

        $setupOptions = [];
        if ($this->option('token')) {
            $setupOptions['--token'] = $this->option('token');
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

