<?php

namespace TheCodeholic\LaravelHostingerDeploy\Commands;

use Illuminate\Support\Facades\File;

class SetupAutomatedDeployCommand extends BaseHostingerCommand
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:setup-cicd 
                            {--token= : GitHub Personal Access Token}
                            {--branch= : Override default branch}
                            {--php-version= : Override PHP version}';

    /**
     * The console command description.
     */
    protected $description = 'Setup automated deployment (publishes workflow file and creates secrets)';

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
        $apiInitialized = $this->initializeGitHubAPI(null, true);
        if (!$apiInitialized) {
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
        if (!$this->setupSshKeys(true)) {
            $this->error('❌ Failed to setup SSH keys');
            return self::FAILURE;
        }

        // Check if GitHub API is available for automated setup
        if ($this->githubAPI) {
            // Automated setup via API
            $publicKey = $this->ssh->getPublicKey();
            if ($publicKey) {
                $this->addDeployKeyViaAPI($publicKey, $repoInfo);
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
            $siteDir = $this->getSiteDir();
            
            // Create secrets (including WEBSITE_FOLDER)
            if (!$this->createSecrets($repoInfo, $sshHost, $sshUsername, $sshPort, $privateKey, $siteDir)) {
                return self::FAILURE;
            }
            
            $this->line('');
            $this->info('✅ Automated deployment setup completed successfully!');
        } else {
            // Manual setup - display secrets and deploy key
            // Create workflow file
            if (!$this->createWorkflowFile($repoInfo)) {
                return self::FAILURE;
            }

            // Display all secrets and deploy key for manual setup
            $this->displayGitHubSecrets($repoInfo);
            
            // Display next steps
            $this->line('');
            $this->info('✅ Setup completed! Next steps:');
            $this->line('');
            $this->line('1. Add all the secrets shown above to GitHub');
            $this->line('2. Add the deploy key to your repository');
            $this->line('3. Commit and push the workflow file:');
            $this->line('      git add .github/workflows/hostinger-deploy.yml');
            $this->line('      git commit -m "Add Hostinger deployment workflow"');
            $this->line('      git push');
            $this->line('4. Your repository will automatically deploy on push!');
            $this->line('');
        }

        return self::SUCCESS;
    }


    /**
     * Publish workflow file locally.
     */
    protected function createWorkflowFile(array $repoInfo): bool
    {
        try {
            $this->info('📄 Publishing GitHub Actions workflow file locally...');

            // Get branch
            $branch = $this->option('branch') ?: $this->github->getCurrentBranch() ?: config('hostinger-deploy.github.default_branch', 'main');
            $phpVersion = $this->option('php-version') ?: config('hostinger-deploy.github.php_version', '8.3');
            
            // Get workflow file path
            $workflowFile = config('hostinger-deploy.github.workflow_file', '.github/workflows/hostinger-deploy.yml');

            // Create .github/workflows directory if it doesn't exist
            $workflowDir = dirname($workflowFile);
            if (!File::exists($workflowDir)) {
                File::makeDirectory($workflowDir, 0755, true);
                $this->info("📁 Created directory: {$workflowDir}");
            }

            // Check if file already exists
            if (File::exists($workflowFile)) {
                if (!$this->confirm("Workflow file already exists at {$workflowFile}. Overwrite it?", true)) {
                    $this->warn('⚠️  Skipping workflow file creation. Using existing file.');
                    return true;
                }
            }

            // Generate workflow content
            $workflowContent = $this->generateWorkflowContent($branch, $phpVersion);

            // Write workflow file
            if (File::put($workflowFile, $workflowContent)) {
                $this->info("✅ Workflow file published: {$workflowFile}");
                $this->warn('⚠️  Please review the workflow file, commit it, and push to trigger deployments.');
                return true;
            } else {
                $this->error("❌ Failed to create workflow file: {$workflowFile}");
                return false;
            }
        } catch (\Exception $e) {
            $this->error("❌ Failed to create workflow file: " . $e->getMessage());
            return false;
        }
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
