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
        
        if (!$this->confirm('Use this repository for deployment?', 'yes')) {
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

            // Check folder status and get deployment choice
            $cloneChoice = $this->getDeploymentChoice($siteDir, $isFresh);

            // Prepare deployment commands
            $commands = $this->buildDeploymentCommands($repoUrl, $siteDir, $cloneChoice);

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
     * Check folder status and get deployment choice from user.
     */
    protected function getDeploymentChoice(string $siteDir, bool $forceFresh): string
    {
        if ($forceFresh) {
            return 'delete_and_clone_direct';
        }

        $this->info('🔍 Checking website folder...');

        // Check if folder is empty
        $folderStatus = $this->checkFolderStatus($siteDir);

        if ($folderStatus === 'empty') {
            $this->info('✅ Empty folder - ready to deploy');
            return 'clone_direct';
        }

        // Check if it's a Laravel project
        $isLaravel = $this->isLaravelProject($siteDir);

        $fullPath = $this->getAbsoluteSitePath($siteDir);

        if ($isLaravel) {
            $this->info("✅ Found existing Laravel project in: {$fullPath}");
            $this->line('');
            $this->line('1. Replace with fresh deployment');
            $this->line('2. Keep existing and continue');
            $this->line('');

            $choice = $this->ask('Choose [1/2]', '2');

            if ($choice === '1') {
                $this->info('🔄 Will replace existing project');
                return 'delete_and_clone_direct';
            } else {
                $this->info('⏭️  Keeping existing project');
                return 'skip';
            }
        } else {
            $this->error("❌ Non-Laravel project detected in: {$fullPath}");
            $this->line('');
            $this->line('1. Replace with Laravel project');
            $this->line('2. Cancel deployment');
            $this->line('');

            $choice = $this->ask('Choose [1/2]', '2');

            if ($choice === '1') {
                $this->info('🔄 Will replace with Laravel project');
                return 'delete_and_clone_direct';
            } else {
                $this->info('❌ Deployment cancelled');
                throw new \Exception('Deployment cancelled by user');
            }
        }
    }

    /**
     * Check if the folder is empty or not.
     */
    protected function checkFolderStatus(string $siteDir): string
    {
        $absolutePath = $this->getAbsoluteSitePath($siteDir);
        
        // First check if directory exists
        if (!$this->ssh->directoryExists($absolutePath)) {
            return 'empty';
        }
        
        // Then check if it's empty
        if ($this->ssh->directoryIsEmpty($absolutePath)) {
            return 'empty';
        }
        
        return 'not_empty';
    }

    /**
     * Check if the folder contains a Laravel project.
     */
    protected function isLaravelProject(string $siteDir): bool
    {
        $absolutePath = $this->getAbsoluteSitePath($siteDir);
        
        // Check if directory exists
        if (!$this->ssh->directoryExists($absolutePath)) {
            return false;
        }
        
        // Check for Laravel-specific files
        $artisanPath = $absolutePath . '/artisan';
        $composerPath = $absolutePath . '/composer.json';
        
        if (!$this->ssh->fileExists($artisanPath) || !$this->ssh->fileExists($composerPath)) {
            return false;
        }
        
        // Check if composer.json contains laravel/framework
        try {
            // Path is escaped by buildSshCommand, so use single quotes inside
            $grepCommand = "grep -q 'laravel/framework' '{$composerPath}' 2>/dev/null && echo 'yes' || echo 'no'";
            $result = trim($this->ssh->execute($grepCommand));
            return trim($result) === 'yes';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build deployment commands.
     */
    protected function buildDeploymentCommands(string $repoUrl, string $siteDir, string $cloneChoice): array
    {
        $commands = [];
        $absolutePath = $this->getAbsoluteSitePath($siteDir);

        // Create site directory
        $commands[] = "mkdir -p {$absolutePath}";
        $commands[] = "cd {$absolutePath}";

        // Remove public_html if exists
        $commands[] = "rm -rf public_html";

        switch ($cloneChoice) {
            case 'clone_direct':
                // Fresh deployment - clone directly
                $commands[] = "git clone {$repoUrl} .";
                break;
            case 'delete_and_clone_direct':
                // Replace existing - delete everything and clone
                $commands[] = "rm -rf * .[^.]* 2>/dev/null || true";
                $commands[] = "git clone {$repoUrl} .";
                break;
            case 'skip':
                // Keep existing - just update dependencies
                break;
            default:
                // Default: check if repository exists
                $commands[] = "if [ -d .git ]; then git pull; else git clone {$repoUrl} .; fi";
                break;
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

    /**
     * Get absolute path for the site directory.
     */
    protected function getAbsoluteSitePath(string $siteDir): string
    {
        $username = config('hostinger-deploy.ssh.username');
        return "/home/{$username}/domains/{$siteDir}";
    }
}
