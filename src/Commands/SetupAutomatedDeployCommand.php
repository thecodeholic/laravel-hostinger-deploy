<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use TheCodeholic\LaravelHostingerDeploy\Services\SshConnectionService;
use TheCodeholic\LaravelHostingerDeploy\Services\GitHubActionsService;
use TheCodeholic\LaravelHostingerDeploy\Services\GitHubAPIService;

class SetupAutomatedDeployCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:setup-automated-deploy 
                            {--token= : GitHub API token}
                            {--branch= : Override default branch}
                            {--php-version= : Override PHP version}';

    /**
     * The console command description.
     */
    protected $description = 'Setup automated deployment using GitHub API (creates workflow and secrets)';

    protected SshConnectionService $ssh;
    protected GitHubActionsService $github;
    protected ?GitHubAPIService $githubAPI = null;

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
        $this->info('🚀 Setting up automated deployment via GitHub API...');

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

        // Initialize GitHub API
        if (!$this->initializeGitHubAPI()) {
            return self::FAILURE;
        }

        // Setup SSH connection
        $this->setupSshConnection();

        // Test SSH connection
        if (!$this->ssh->testConnection()) {
            $this->error('❌ SSH connection failed. Please check your SSH configuration.');
            return self::FAILURE;
        }

        $this->info('✅ SSH connection successful');

        // Setup SSH keys on server
        if (!$this->setupSshKeys()) {
            $this->error('❌ Failed to setup SSH keys');
            return self::FAILURE;
        }

        // Get SSH information
        $sshHost = config('hostinger-deploy.ssh.host');
        $sshUsername = config('hostinger-deploy.ssh.username');
        $sshPort = config('hostinger-deploy.ssh.port', 22);
        $privateKey = $this->ssh->getPrivateKey();

        if (!$privateKey) {
            $this->error('❌ Could not retrieve private key from server');
            return self::FAILURE;
        }

        // Create workflow file
        if (!$this->createWorkflowFile($repoInfo)) {
            return self::FAILURE;
        }

        // Get site directory
        $siteDir = config('hostinger-deploy.deployment.site_dir');
        
        // Create secrets (including WEBSITE_FOLDER)
        if (!$this->createSecrets($repoInfo, $sshHost, $sshUsername, $sshPort, $privateKey, $siteDir)) {
            return self::FAILURE;
        }
        
        $this->line('');
        $this->info('🎉 Automated deployment setup completed successfully!');
        $this->line('');
        $this->info("🌐 Your Laravel application: https://{$siteDir}");
        $this->line('');
        $this->info('🚀 Your repository will now automatically deploy on push to the configured branch!');

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
     * Initialize GitHub API service.
     */
    protected function initializeGitHubAPI(): bool
    {
        try {
            $token = $this->option('token') ?: env('GITHUB_API_TOKEN');

            if (!$token) {
                $this->error('❌ GitHub API token is required.');
                $this->line('');
                $this->warn('💡 How to provide your GitHub API token:');
                $this->line('   Option 1: Set GITHUB_API_TOKEN in your .env file');
                $this->line('   Option 2: Use --token=YOUR_TOKEN option when running this command');
                $this->line('');
                $this->info('🔑 To create a GitHub Personal Access Token:');
                $this->line('   1. Go to: https://github.com/settings/personal-access-tokens');
                $this->line('   2. Click "Generate new token" → "Generate new token (classic)"');
                $this->line('   3. Give your token a descriptive name (e.g., "Hostinger Deploy")');
                $this->line('   4. Set expiration (or no expiration)');
                $this->line('   5. Select the following permissions:');
                $this->line('');
                $this->info('   📋 Required Permissions:');
                $this->info('      ✓ Contents → Read and write');
                $this->info('        (Allows creating/updating files, commits, and branches)');
                $this->info('      ✓ Workflows → Read and write');
                $this->info('        (Allows creating/updating GitHub Actions workflows)');
                $this->info('      ✓ Metadata → Read-only');
                $this->info('        (Automatically selected, required for API access)');
                $this->line('');
                $this->warn('   6. Click "Generate token" and copy the token immediately');
                $this->warn('      ⚠️  You won\'t be able to see it again!');
                return false;
            }

            $this->githubAPI = new GitHubAPIService($token);

            // Test API connection
            if (!$this->githubAPI->testConnection()) {
                $this->error('❌ Failed to authenticate with GitHub API. Please check your token.');
                return false;
            }

            $this->info('✅ GitHub API connection successful');
            return true;
        } catch (\Exception $e) {
            $this->error("❌ GitHub API error: " . $e->getMessage());
            return false;
        }
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
            if (!$this->ssh->sshKeyExists()) {
                $this->info('🔑 Generating SSH keys on server...');
                if (!$this->ssh->generateSshKey()) {
                    $this->error('❌ Failed to generate SSH keys');
                    return false;
                }
            } else {
                $this->info('🔑 SSH keys already exist on server');
            }

            // Add public key to authorized_keys
            $publicKey = $this->ssh->getPublicKey();
            if ($publicKey && !$this->ssh->addToAuthorizedKeys($publicKey)) {
                $this->warn('⚠️  Could not add public key to authorized_keys (may already exist)');
            }

            $this->info('✅ SSH keys setup completed');
            return true;
        } catch (\Exception $e) {
            $this->error("SSH keys setup error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create workflow file via GitHub API.
     */
    protected function createWorkflowFile(array $repoInfo): bool
    {
        try {
            $this->info('📄 Creating GitHub Actions workflow file...');

            // Get branch
            $branch = $this->option('branch') ?: $this->github->getCurrentBranch() ?: config('hostinger-deploy.github.default_branch', 'main');
            $phpVersion = $this->option('php-version') ?: config('hostinger-deploy.github.php_version', '8.3');

            // Generate workflow content
            $workflowContent = $this->generateWorkflowContent($branch, $phpVersion);

            // Create or update workflow file via API
            $this->githubAPI->createOrUpdateWorkflowFile(
                $repoInfo['owner'],
                $repoInfo['name'],
                $branch,
                $workflowContent
            );

            $this->info('✅ Workflow file created successfully');
            return true;
        } catch (\Exception $e) {
            $this->error("❌ Failed to create workflow file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate workflow content.
     */
    protected function generateWorkflowContent(string $branch, string $phpVersion): string
    {
        $stubPath = __DIR__ . '/../../stubs/hostinger-deploy.yml';
        
        if (!File::exists($stubPath)) {
            throw new \Exception("Workflow stub not found: {$stubPath}");
        }

        $content = File::get($stubPath);
        $content = str_replace('{{BRANCH}}', $branch, $content);
        $content = str_replace('{{PHP_VERSION}}', $phpVersion, $content);

        return $content;
    }

    /**
     * Create secrets via GitHub API.
     */
    protected function createSecrets(array $repoInfo, string $sshHost, string $sshUsername, int $sshPort, string $sshKey, string $siteDir): bool
    {
        try {
            $this->info('🔒 Creating GitHub secrets...');

            $secrets = [
                'SSH_HOST' => $sshHost,
                'SSH_USERNAME' => $sshUsername,
                'SSH_PORT' => (string) $sshPort,
                'SSH_KEY' => $sshKey,
                'WEBSITE_FOLDER' => $siteDir,
            ];

            foreach ($secrets as $name => $value) {
                $this->githubAPI->createOrUpdateSecret(
                    $repoInfo['owner'],
                    $repoInfo['name'],
                    $name,
                    $value
                );
                $this->info("   ✅ {$name} created");
            }

            $this->info('✅ All secrets created successfully');
            return true;
        } catch (\Exception $e) {
            $this->error("❌ Failed to create secrets: " . $e->getMessage());
            return false;
        }
    }

}
