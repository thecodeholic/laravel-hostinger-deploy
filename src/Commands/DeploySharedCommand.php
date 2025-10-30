<?php

namespace Zura\HostingerDeploy\Commands;

use Illuminate\Console\Command;
use Zura\HostingerDeploy\Services\SshConnectionService;
use Zura\HostingerDeploy\Services\GitHubActionsService;

class DeploySharedCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:deploy-shared 
                            {--fresh : Delete and clone fresh repository}
                            {--site-dir= : Override site directory from config}';

    /**
     * The console command description.
     */
    protected $description = 'Deploy Laravel application to Hostinger shared hosting';

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
        $this->info('🚀 Starting Hostinger deployment...');

        // Validate configuration
        if (!$this->validateConfiguration()) {
            return self::FAILURE;
        }

        // Get repository URL
        $repoUrl = $this->getRepositoryUrl();
        if (!$repoUrl) {
            $this->error('❌ Could not detect Git repository URL. Please run this command from a Git repository.');
            return self::FAILURE;
        }

        $this->info("📦 Repository: {$repoUrl}");

        // Setup SSH connection
        $this->setupSshConnection();

        // Test SSH connection
        if (!$this->ssh->testConnection()) {
            $this->error('❌ SSH connection failed. Please check your SSH configuration.');
            return self::FAILURE;
        }

        $this->info('✅ SSH connection successful');

        // Deploy to server
        if (!$this->deployToServer($repoUrl)) {
            $this->error('❌ Deployment failed');
            return self::FAILURE;
        }

        $this->info('🎉 Deployment completed successfully!');
        $this->info("🌐 Your Laravel application is now live at: https://{$this->getSiteDir()}");

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
            'HOSTINGER_SITE_DIR' => $this->getSiteDir(),
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
     * Get repository URL from Git.
     */
    protected function getRepositoryUrl(): ?string
    {
        $repoUrl = $this->github->getRepositoryUrl();
        
        if (!$repoUrl) {
            return null;
        }

        $this->info("✅ Detected Git repository: {$repoUrl}");
        
        if (!$this->confirm('Use this repository for deployment?')) {
            $repoUrl = $this->ask('Enter your Git repository URL');
        }

        return $repoUrl;
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
     * Deploy application to server.
     */
    protected function deployToServer(string $repoUrl): bool
    {
        $siteDir = $this->getSiteDir();
        $isFresh = $this->option('fresh');

        try {
            // Setup SSH keys if needed
            $this->setupSshKeys();

            // Prepare deployment commands
            $commands = $this->buildDeploymentCommands($repoUrl, $siteDir, $isFresh);

            // Execute deployment
            $this->info('📦 Deploying application...');
            $this->ssh->executeMultiple($commands);

            return true;
        } catch (\Exception $e) {
            $this->error("Deployment error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Setup SSH keys on server if needed.
     */
    protected function setupSshKeys(): void
    {
        if (!$this->ssh->sshKeyExists()) {
            $this->info('🔑 Generating SSH keys on server...');
            $this->ssh->generateSshKey();
            
            $publicKey = $this->ssh->getPublicKey();
            if ($publicKey) {
                $this->warn('🔑 Add this SSH key to your GitHub repository:');
                $this->warn('   Settings → Deploy keys → Add deploy key');
                $this->line('');
                $this->line($publicKey);
                $this->line('');
                
                if (!$this->confirm('Press ENTER after adding the key to GitHub...')) {
                    throw new \Exception('SSH key setup cancelled');
                }
            }
        } else {
            $this->info('🔑 SSH keys already configured');
        }
    }

    /**
     * Build deployment commands.
     */
    protected function buildDeploymentCommands(string $repoUrl, string $siteDir, bool $isFresh): array
    {
        $commands = [];

        // Navigate to site directory
        $commands[] = "mkdir -p domains/{$siteDir}";
        $commands[] = "cd domains/{$siteDir}";

        // Remove public_html if exists
        $commands[] = "rm -rf public_html";

        if ($isFresh) {
            // Fresh deployment - delete everything and clone
            $commands[] = "rm -rf * .[^.]* 2>/dev/null || true";
            $commands[] = "git clone {$repoUrl} .";
        } else {
            // Check if repository exists
            $commands[] = "if [ -d .git ]; then git pull; else git clone {$repoUrl} .; fi";
        }

        // Install dependencies
        $composerFlags = config('hostinger-deploy.deployment.composer_flags', '--no-dev --optimize-autoloader');
        $commands[] = "composer install {$composerFlags}";

        // Copy .env.example to .env
        $commands[] = "if [ -f .env.example ]; then cp .env.example .env; fi";

        // Create symbolic link for Laravel public folder
        $commands[] = "if [ -d public ]; then ln -s public public_html; fi";

        // Laravel setup
        $commands[] = "php artisan key:generate --quiet";

        if (config('hostinger-deploy.deployment.run_migrations', true)) {
            $commands[] = "echo 'yes' | php artisan migrate --quiet";
        }

        if (config('hostinger-deploy.deployment.run_storage_link', true)) {
            $commands[] = "php artisan storage:link --quiet";
        }

        return $commands;
    }

    /**
     * Get site directory from option or config.
     */
    protected function getSiteDir(): string
    {
        return $this->option('site-dir') ?: config('hostinger-deploy.deployment.site_dir');
    }
}
