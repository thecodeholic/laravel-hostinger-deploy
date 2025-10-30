<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

use Illuminate\Console\Command;
use TheCodeholic\LaravelHostingerDeploy\Services\SshConnectionService;
use TheCodeholic\LaravelHostingerDeploy\Services\GitHubActionsService;

class AutoDeployCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:auto-deploy';

    /**
     * The console command description.
     */
    protected $description = 'Setup SSH keys and display GitHub secrets for automated deployment';

    protected SshConnectionService $ssh;
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
        if (!$this->setupSshKeys()) {
            $this->error('❌ Failed to setup SSH keys');
            return self::FAILURE;
        }

        // Display GitHub secrets
        $this->displayGitHubSecrets($repoInfo);

        return self::SUCCESS;
    }

    /**
     * Validate required configuration.
     */
    protected function validateConfiguration(): bool
    {
        $required = [
            'HOSTINGER_SSH_HOST' => config('hostinger-deploy.ssh.host'),
            'HOSTINGER_SSH_USERNAME' => config('hostinger-deploy.ssh.username'),
            'HOSTINGER_SITE_DIR' => config('hostinger-deploy.deployment.site_dir'),
        ];

        foreach ($required as $key => $value) {
            if (empty($value)) {
                $this->error("❌ Missing required environment variable: {$key}");
                $this->info("Please add {$key} to your .env file");
                return false;
            }
        }

        return true;
    }

    /**
     * Get repository information.
     */
    protected function getRepositoryInfo(): ?array
    {
        if (!$this->github->isGitRepository()) {
            return null;
        }

        return $this->github->getRepositoryInfo();
    }

    /**
     * Setup SSH connection service.
     */
    protected function setupSshConnection(): void
    {
        $this->ssh = new SshConnectionService(
            config('hostinger-deploy.ssh.host'),
            config('hostinger-deploy.ssh.username'),
            config('hostinger-deploy.ssh.port', 22),
            config('hostinger-deploy.ssh.timeout', 30)
        );
    }

    /**
     * Setup SSH keys on the server.
     */
    protected function setupSshKeys(): bool
    {
        try {
            // Generate SSH keys if they don't exist
            if (!$this->ssh->sshKeyExists()) {
                $this->info('🔑 Generating SSH keys on server...');
                if (!$this->ssh->generateSshKey()) {
                    $this->error('❌ Failed to generate SSH keys');
                    return false;
                }
            } else {
                $this->info('🔑 SSH keys already exist on server');
            }

            // Get the public key
            $publicKey = $this->ssh->getPublicKey();
            if (!$publicKey) {
                $this->error('❌ Could not retrieve public key from server');
                return false;
            }

            // Add public key to authorized_keys
            $this->info('🔐 Adding public key to authorized_keys...');
            if (!$this->ssh->addToAuthorizedKeys($publicKey)) {
                $this->error('❌ Failed to add public key to authorized_keys');
                return false;
            }

            $this->info('✅ SSH keys setup completed');

            return true;
        } catch (\Exception $e) {
            $this->error("SSH keys setup error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Display GitHub secrets and variables for manual setup.
     */
    protected function displayGitHubSecrets(array $repoInfo): void
    {
        $this->line('');
        $this->info('🔒 GitHub Secrets and Variables Setup');
        $this->line('');

        // Get private key
        $privateKey = $this->ssh->getPrivateKey();
        if (!$privateKey) {
            $this->error('❌ Could not retrieve private key from server');
            return;
        }

        // Display secrets
        $this->warn('📋 Add these secrets to your GitHub repository:');
        $this->line('Go to: ' . $repoInfo['secrets_url']);
        $this->line('');

        $secrets = [
            'SSH_HOST' => config('hostinger-deploy.ssh.host'),
            'SSH_USERNAME' => config('hostinger-deploy.ssh.username'),
            'SSH_PORT' => (string) config('hostinger-deploy.ssh.port', 22),
            'SSH_KEY' => $privateKey,
        ];

        foreach ($secrets as $name => $value) {
            $this->line("🔑 {$name}:");
            if ($name === 'SSH_KEY') {
                $this->line('   [Copy the private key below]');
                $this->line('');
                $this->line('   ' . str_repeat('-', 50));
                $this->line($value);
                $this->line('   ' . str_repeat('-', 50));
            } else {
                $this->line("   {$value}");
            }
            $this->line('');
        }

        // Display variables
        $this->warn('📊 Add this variable to your GitHub repository:');
        $this->line('Go to: ' . $repoInfo['variables_url']);
        $this->line('');

        $this->line("📊 WEBSITE_FOLDER:");
        $this->line("   " . config('hostinger-deploy.deployment.site_dir'));
        $this->line('');

        // Display deploy key information
        $this->warn('🔑 Deploy Key Information:');
        $this->line('Go to: ' . $repoInfo['deploy_keys_url']);
        $this->line('');

        $publicKey = $this->ssh->getPublicKey();
        $this->line('Add this public key as a Deploy Key:');
        $this->line('');
        $this->line('   ' . str_repeat('-', 50));
        $this->line($publicKey);
        $this->line('   ' . str_repeat('-', 50));
        $this->line('');

        // Display next steps
        $this->info('🎉 Setup completed! Next steps:');
        $this->line('');
        $this->line('1. Add all the secrets and variables shown above to GitHub');
        $this->line('2. Add the deploy key to your repository');
        $this->line('3. Run: php artisan hostinger:publish-workflow');
        $this->line('4. Push your changes to trigger the workflow');
        $this->line('');
        $this->info('🚀 Your repository will now automatically deploy on push!');
    }
}
