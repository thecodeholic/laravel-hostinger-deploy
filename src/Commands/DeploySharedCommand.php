<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

class DeploySharedCommand extends BaseHostingerCommand
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:deploy 
                            {--fresh : Delete and clone fresh repository}
                            {--site-dir= : Override site directory from config}
                            {--token= : GitHub Personal Access Token}';

    /**
     * The console command description.
     */
    protected $description = 'Deploy Laravel application to Hostinger shared hosting';

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
        $this->initializeGitHubAPI($repoUrl, false);

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

        $this->info('✅ Deployment completed successfully!');
        $this->info("🌐 Your Laravel application: https://{$this->getSiteDir()}");

        return self::SUCCESS;
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
            $this->setupSshKeysForDeployment();

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
     * Setup SSH keys on server if needed and add deploy key via API if available.
     * Does not display the key to user - only shows it on actual permission errors.
     */
    protected function setupSshKeysForDeployment(): void
    {
        if (!$this->setupSshKeys(false)) {
            return;
        }

        // Get public key
        $publicKey = $this->ssh->getPublicKey();
        
        if (!$publicKey) {
            $this->error('❌ Could not retrieve public key from server');
            return;
        }

        // Try to add deploy key via API if available (silently)
        if ($this->githubAPI) {
            // Try to add via API, but don't show manual instructions if it fails
            // The key will be shown only if git clone fails with permission error
            try {
                $repoInfo = $this->github->getRepositoryInfo();
                if ($repoInfo) {
                    if ($this->githubAPI->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey)) {
                        // Key already exists, nothing to do
                        return;
                    }
                    // Try to add the key
                    $this->githubAPI->createDeployKey($repoInfo['owner'], $repoInfo['name'], $publicKey, 'Hostinger Server', false);
                }
            } catch (\Exception $e) {
                // Silent failure - will be handled if git clone fails
            }
        }
        // If no API, we'll handle it when git clone fails with permission error
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
            // Get repository information
            $repoInfo = $this->github->parseRepositoryUrl($repoUrl);
            
            // Check if deploy key already exists (via API if available)
            $keyExists = false;
            if ($this->githubAPI && $repoInfo) {
                try {
                    $keyExists = $this->githubAPI->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey);
                    if ($keyExists) {
                        $this->info('✅ Deploy key already exists in repository');
                        // Retry deployment
                        $this->info('🔄 Retrying deployment...');
                        try {
                            $commands = $this->buildDeploymentCommands($repoUrl, $siteDir, $cloneChoice);
                            $this->ssh->executeMultiple($commands);
                            return true;
                        } catch (\Exception $e) {
                            $this->warn('⚠️  Deployment still failed: ' . $e->getMessage());
                            // Continue to show the key below
                        }
                    }
                } catch (\Exception $e) {
                    // If check fails, proceed to show the key
                }
            }
            
            // If key doesn't exist, try to add via API first
            if (!$keyExists && $this->githubAPI && $repoInfo) {
                try {
                    $this->info('🔑 Attempting to add deploy key via API...');
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
                        // Continue to show manual instructions below
                    }
                } catch (\Exception $e) {
                    $this->warn('⚠️  Failed to add deploy key via API: ' . $e->getMessage());
                    $this->warn('   Falling back to manual method...');
                    $this->line('');
                }
            }
            
            // Only show deploy key if it doesn't exist and needs to be added manually
            if (!$keyExists) {
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
                // Key already exists but deployment still failed - show error
                return $this->handleDeploymentFailure(new \Exception('Deployment failed even though deploy key exists'), $repoUrl, false);
            }
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
