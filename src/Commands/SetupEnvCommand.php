<?php

namespace Zura\HostingerDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SetupEnvCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hostinger:setup-env 
                            {--force : Overwrite existing values}
                            {--host= : SSH host address}
                            {--username= : SSH username}
                            {--port= : SSH port}
                            {--site-dir= : Website folder name}';

    /**
     * The console command description.
     */
    protected $description = 'Add Hostinger environment variables to .env file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔧 Setting up Hostinger environment variables...');

        $envPath = base_path('.env');
        
        if (!File::exists($envPath)) {
            $this->error('❌ .env file not found. Please create it first.');
            return self::FAILURE;
        }

        $envContent = File::get($envPath);
        $envLines = explode("\n", $envContent);

        // Check if Hostinger section already exists
        $hostingerSectionExists = $this->hasHostingerSection($envLines);
        
        if ($hostingerSectionExists && !$this->option('force')) {
            $this->warn('⚠️  Hostinger environment variables already exist in .env file.');
            
            if (!$this->confirm('Do you want to update them?')) {
                $this->info('Skipping environment setup.');
                return self::SUCCESS;
            }
        }

        // Get values from options or prompt user
        $values = $this->getEnvironmentValues();

        // Add or update environment variables
        $this->addEnvironmentVariables($envLines, $values);

        // Write updated .env file
        $updatedContent = implode("\n", $envLines);
        
        if (File::put($envPath, $updatedContent)) {
            $this->info('✅ Hostinger environment variables added to .env file');
            $this->displayAddedVariables($values);
        } else {
            $this->error('❌ Failed to write to .env file');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Check if Hostinger section already exists in .env file.
     */
    protected function hasHostingerSection(array $envLines): bool
    {
        foreach ($envLines as $line) {
            if (strpos($line, 'HOSTINGER_') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get environment values from options or use empty values.
     */
    protected function getEnvironmentValues(): array
    {
        $values = [];

        // SSH Host - use option value or empty string
        $values['HOSTINGER_SSH_HOST'] = $this->option('host') ?: '';

        // SSH Username - use option value or empty string
        $values['HOSTINGER_SSH_USERNAME'] = $this->option('username') ?: '';

        // SSH Port - use option value or default '22'
        $values['HOSTINGER_SSH_PORT'] = $this->option('port') ?: '22';

        // Site Directory - use option value or empty string
        $values['HOSTINGER_SITE_DIR'] = $this->option('site-dir') ?: '';

        return $values;
    }

    /**
     * Add or update environment variables in .env file.
     */
    protected function addEnvironmentVariables(array &$envLines, array $values): void
    {
        $hostingerSectionIndex = $this->findHostingerSectionIndex($envLines);
        
        if ($hostingerSectionIndex === -1) {
            // Add new Hostinger section at the end
            $envLines[] = '';
            $envLines[] = '# Hostinger Deployment Configuration';
            $hostingerSectionIndex = count($envLines) - 1;
        }

        // Add or update each variable
        foreach ($values as $key => $value) {
            $this->addOrUpdateVariable($envLines, $key, $value, $hostingerSectionIndex);
        }
    }

    /**
     * Find the index where Hostinger section starts.
     */
    protected function findHostingerSectionIndex(array $envLines): int
    {
        foreach ($envLines as $index => $line) {
            if (strpos($line, '# Hostinger Deployment Configuration') === 0) {
                return $index;
            }
        }
        return -1;
    }

    /**
     * Add or update a specific environment variable.
     */
    protected function addOrUpdateVariable(array &$envLines, string $key, ?string $value, int $sectionIndex): void
    {
        // Ensure value is always a string (handle null/empty)
        $value = $value ?? '';
        $variableLine = "{$key}={$value}";
        
        // Check if variable already exists
        for ($i = 0; $i < count($envLines); $i++) {
            if (strpos($envLines[$i], "{$key}=") === 0) {
                // Update existing variable
                $envLines[$i] = $variableLine;
                return;
            }
        }
        
        // Add new variable after the section header
        array_splice($envLines, $sectionIndex + 1, 0, [$variableLine]);
    }

    /**
     * Display the added variables to the user.
     */
    protected function displayAddedVariables(array $values): void
    {
        $this->line('');
        $this->info('📋 Added environment variables:');
        $this->line('');
        
        foreach ($values as $key => $value) {
            $displayValue = $value ?: '(empty - please fill in)';
            $this->line("   {$key}={$displayValue}");
        }
        
        $this->line('');
        $this->info('💡 Please fill in the empty values in your .env file, then run: php artisan hostinger:deploy-shared');
    }
}
