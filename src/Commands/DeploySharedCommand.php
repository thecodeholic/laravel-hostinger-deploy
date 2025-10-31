<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

use Illuminate\Console\Command;
use TheCodeholic\LaravelHostingerDeploy\Services\SshConnectionService;
use TheCodeholic\LaravelHostingerDeploy\Services\GitHubActionsService;
use TheCodeholic\LaravelHostingerDeploy\Services\GitHubAPIService;

class DeploySharedCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:deploy-shared 
                            {--fresh : Delete and clone fresh repository}
                            {--site-dir= : Override site directory from config}
                            {--token= : GitHub Personal Access Token}';

    /**
     * The console command description.
     */
    protected $description = 'Deploy Laravel application to Hostinger shared hosting';

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

        // Initialize GitHub API (optional, for automatic deploy key management)
        $this->initializeGitHubAPI($repoUrl);

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
            try {
                $this->ssh->executeMultiple($commands);
                return true;
            } catch (\Exception $e) {
                // Check if this is a git clone authentication error
                if ($this->isGitAuthenticationError($e)) {
                    return $this->handleGitAuthenticationError($repoUrl, $siteDir, $cloneChoice);
                }
                throw $e; // Re-throw if it's not an auth error
            }
        } catch (\Exception $e) {
            // Check if this is a git authentication error that wasn't caught earlier
            if ($this->isGitAuthenticationError($e)) {
                return $this->handleGitAuthenticationError($repoUrl, $siteDir, 'clone_direct');
            }
            
            // Show user-friendly error message instead of technical details
            $this->error("❌ Deployment failed.");
            $this->line('');
            $this->warn('💡 This might be due to:');
            $this->line('   1. Server connection issues');
            $this->line('   2. Repository access problems');
            $this->line('   3. Missing dependencies on the server');
            $this->line('');
            $this->info('🔧 Please check your server configuration and try again.');
            return false;
        }
    }

    /**
     * Initialize GitHub API service (optional).
     */
    protected function initializeGitHubAPI(?string $repoUrl = null): void
    {
        try {
            $token = $this->option('token') ?: env('GITHUB_API_TOKEN');

            if (!$token) {
                $this->line('');
                $this->warn('⚠️  GitHub Personal Access Token is not set.');
                $this->line('');
                
                if (!$this->confirm('Do you want to proceed?', true)) {
                    // User chose "no" - show instructions and halt
                    $this->line('');
                    $this->showGitHubTokenInstructions();
                    $this->line('');
                    $this->info('📝 Please add GITHUB_API_TOKEN to your .env file and rerun the script.');
                    $this->line('');
                    exit(0);
                }
                
                // User chose "yes" - continue without token, deploy key will be shown manually
                $this->warn('⚠️  Continuing without Personal Access Token. Deploy key will be displayed for manual addition.');
                return;
            }

            $this->githubAPI = new GitHubAPIService($token);

            // Test API connection
            if (!$this->githubAPI->testConnection()) {
                $this->warn('⚠️  GitHub API connection failed. Deploy key will need to be added manually.');
                $this->githubAPI = null;
                return;
            }

            $this->info('✅ GitHub API connection successful');
        } catch (\Exception $e) {
            // API is optional, continue without it
            $this->warn('⚠️  GitHub API initialization failed: ' . $e->getMessage());
            $this->warn('   Deploy key will need to be added manually.');
            $this->githubAPI = null;
        }
    }

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
     * Setup SSH keys on server if needed and add deploy key via API if available.
     */
    protected function setupSshKeys(): void
    {
        if (!$this->ssh->sshKeyExists()) {
            $this->info('🔑 Generating SSH keys on server...');
            $this->ssh->generateSshKey();
        } else {
            $this->info('🔑 SSH keys already exist on server');
        }

        // Get public key
        $publicKey = $this->ssh->getPublicKey();
        
        if (!$publicKey) {
            $this->error('❌ Could not retrieve public key from server');
            return;
        }

        // Try to add deploy key via API if available
        if ($this->githubAPI) {
            $this->addDeployKeyViaAPI($publicKey);
        } else {
            // Fallback to manual method
            $this->warn('🔑 Add this SSH key to your GitHub repository:');
            $this->warn('   Settings → Deploy keys → Add deploy key');
            $this->line('');
            $this->line($publicKey);
            $this->line('');
            
            $this->ask('Press ENTER after adding the key to GitHub to continue...', '');
        }
    }

    /**
     * Add deploy key to GitHub repository via API.
     */
    protected function addDeployKeyViaAPI(string $publicKey): void
    {
        try {
            // Get repository information from git
            $repoInfo = $this->github->getRepositoryInfo();
            if (!$repoInfo) {
                $this->warn('⚠️  Could not detect repository information. Skipping automatic deploy key setup.');
                return;
            }

            $owner = $repoInfo['owner'];
            $repo = $repoInfo['name'];

            $this->info('🔑 Adding deploy key to GitHub repository via API...');

            // Check if key already exists
            if ($this->githubAPI->keyExists($owner, $repo, $publicKey)) {
                $this->info('✅ Deploy key already exists in repository');
                return;
            }

            // Create deploy key
            $this->githubAPI->createDeployKey($owner, $repo, $publicKey, 'Hostinger Server', false);
            $this->info('✅ Deploy key added successfully to repository');
        } catch (\Exception $e) {
            $this->warn('⚠️  Failed to add deploy key via API: ' . $e->getMessage());
            $this->warn('   You may need to add it manually.');
            
            // Show manual instructions as fallback
            $this->line('');
            $this->warn('🔑 Please add this SSH key manually to your GitHub repository:');
            $this->warn('   Settings → Deploy keys → Add deploy key');
            $this->line('');
            $this->line($publicKey);
            $this->line('');
            
            $this->ask('Press ENTER after adding the key to GitHub to continue...', '');
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

        $this->warn('⚠️  Folder not empty - checking contents...');

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

    /**
     * Check if the exception is a git authentication error.
     */
    protected function isGitAuthenticationError(\Exception $e): bool
    {
        $errorMessage = $e->getMessage();
        
        // Check for common git authentication error messages
        $authErrorPatterns = [
            'Repository not found',
            'Could not read from remote repository',
            'Permission denied',
            'Please make sure you have the correct access rights',
            'Host key verification failed',
            'Authentication failed',
        ];

        foreach ($authErrorPatterns as $pattern) {
            if (stripos($errorMessage, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle git authentication error by displaying public key and instructions.
     */
    protected function handleGitAuthenticationError(string $repoUrl, string $siteDir, string $cloneChoice): bool
    {
        $this->line('');
        $this->warn('🔑 Git authentication failed! The deploy key is not set up correctly.');
        $this->line('');

        // Get public key from server
        $publicKey = $this->ssh->getPublicKey();
        
        if (!$publicKey) {
            $this->error('❌ Could not retrieve public key from server. Generating new key...');
            if ($this->ssh->generateSshKey()) {
                $publicKey = $this->ssh->getPublicKey();
            }
        }

        if ($publicKey) {
            // Try to add deploy key via API if available
            if ($this->githubAPI) {
                try {
                    $repoInfo = $this->github->parseRepositoryUrl($repoUrl);
                    if ($repoInfo) {
                        $this->info('🔑 Attempting to add deploy key via API...');
                        
                        // Check if key already exists
                        if ($this->githubAPI->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey)) {
                            $this->info('✅ Deploy key already exists in repository');
                            // Retry deployment
                            $this->info('🔄 Retrying deployment...');
                            try {
                                $commands = $this->buildDeploymentCommands($repoUrl, $siteDir, $cloneChoice);
                                $this->ssh->executeMultiple($commands);
                                return true;
                            } catch (\Exception $e) {
                                $this->warn('⚠️  Deployment still failed: ' . $e->getMessage());
                            }
                        } else {
                            // Try to create the key
                            $this->githubAPI->createDeployKey($repoInfo['owner'], $repoInfo['name'], $publicKey, 'Hostinger Server', false);
                            $this->info('✅ Deploy key added successfully via API');
                            // Retry deployment
                            $this->info('🔄 Retrying deployment...');
                            try {
                                $commands = $this->buildDeploymentCommands($repoUrl, $siteDir, $cloneChoice);
                                $this->ssh->executeMultiple($commands);
                                return true;
                            } catch (\Exception $e) {
                                $this->warn('⚠️  Deployment still failed: ' . $e->getMessage());
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->warn('⚠️  Failed to add deploy key via API: ' . $e->getMessage());
                    $this->warn('   Falling back to manual method...');
                    $this->line('');
                }
            }

            // Fallback to manual method
            // Get repository information
            $repoInfo = $this->github->parseRepositoryUrl($repoUrl);
            
            $this->info('📋 Add this SSH public key to your GitHub repository:');
            $this->line('');
            
            if ($repoInfo) {
                $deployKeysUrl = $this->github->getDeployKeysUrl($repoInfo['owner'], $repoInfo['name']);
                $this->line("   Go to: {$deployKeysUrl}");
            } else {
                $this->line("   Go to: Your repository → Settings → Deploy keys");
            }
            
            $this->line('');
            $this->warn('   Steps:');
            $this->line('   1. Click "Add deploy key"');
            $this->line('   2. Give it a title (e.g., "Hostinger Server")');
            $this->line('   3. Paste the public key below');
            $this->line('   4. ✅ Check "Allow write access" (optional, for deployments)');
            $this->line('   5. Click "Add key"');
            $this->line('');
            $this->line('   ' . str_repeat('-', 60));
            $this->line($publicKey);
            $this->line('   ' . str_repeat('-', 60));
            $this->line('');
            
            // Retry loop - keep asking until deployment succeeds or user gives up
            $maxRetries = 3;
            $attempt = 0;
            
            while ($attempt < $maxRetries) {
                $this->ask('Press ENTER after you have added the deploy key to GitHub to continue...', '');
                $this->info('🔄 Retrying deployment...');
                
                try {
                    $commands = $this->buildDeploymentCommands($repoUrl, $siteDir, $cloneChoice);
                    $this->ssh->executeMultiple($commands);
                    
                    // Success - let main handle method display success message
                    return true;
                } catch (\Exception $e) {
                    $attempt++;
                    
                    // Check if it's still an authentication error
                    if ($this->isGitAuthenticationError($e) && $attempt < $maxRetries) {
                        $this->line('');
                        $this->warn("⚠️  Authentication still failed (attempt {$attempt}/{$maxRetries})");
                        $this->line('');
                        $this->warn('💡 Please make sure:');
                        $this->line('   1. You have copied the public key correctly');
                        $this->line('   2. You have added it as a deploy key (not SSH key)');
                        $this->line('   3. You have saved the deploy key');
                        $this->line('');
                        $this->info('📋 Here is your public key again:');
                        $this->line('');
                        $this->line('   ' . str_repeat('-', 60));
                        $this->line($publicKey);
                        $this->line('   ' . str_repeat('-', 60));
                        $this->line('');
                        continue;
                    } else {
                        // Not an auth error or max retries reached
                        return $this->handleDeploymentFailure($e, $repoUrl, $attempt >= $maxRetries);
                    }
                }
            }
            
            return false;
        } else {
            $this->error('❌ Could not retrieve or generate SSH public key.');
            return false;
        }
    }

    /**
     * Handle deployment failure with user-friendly error messages.
     */
    protected function handleDeploymentFailure(\Exception $e, string $repoUrl, bool $maxRetriesReached): bool
    {
        $this->line('');
        
        if ($maxRetriesReached) {
            $this->error('❌ Maximum retry attempts reached.');
            $this->line('');
            $this->warn('💡 Please check:');
            $this->line('   1. The deploy key has been added correctly to GitHub');
            $this->line('   2. The repository URL is correct: ' . $repoUrl);
            $this->line('   3. You have access to the repository');
            $this->line('   4. The deploy key has write access (if needed)');
            $this->line('');
            $this->info('🔧 You can try running the command again after fixing the issue.');
        } else {
            // Not an authentication error - show general deployment failure
            $this->error('❌ Deployment failed.');
            $this->line('');
            $this->warn('💡 This might be due to:');
            $this->line('   1. Server connection issues');
            $this->line('   2. Repository access problems');
            $this->line('   3. Missing dependencies on the server');
            $this->line('');
            $this->info('🔧 Please check your server configuration and try again.');
        }
        
        return false;
    }
}
