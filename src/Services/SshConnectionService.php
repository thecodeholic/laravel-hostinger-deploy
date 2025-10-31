<?php

namespace TheCodeholic\LaravelHostingerDeploy\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Process\Exceptions\ProcessFailedException;

class SshConnectionService
{
    protected string $host;
    protected string $username;
    protected int $port;
    protected int $timeout;

    public function __construct(string $host, string $username, int $port = 22, int $timeout = 30)
    {
        $this->host = $host;
        $this->username = $username;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    /**
     * Execute a command on the remote server via SSH.
     */
    public function execute(string $command): string
    {
        $sshCommand = $this->buildSshCommand($command);
        
        try {
            $result = Process::timeout($this->timeout)
                ->run($sshCommand);
            
            if (!$result->successful()) {
                // Build detailed error message with error output and exit code
                $errorOutput = $result->errorOutput();
                $exitCode = $result->exitCode();
                $output = $result->output();
                
                $errorMessage = "SSH command failed";
                if ($exitCode !== null) {
                    $errorMessage .= " (exit code: {$exitCode})";
                }
                $errorMessage .= ": Command exited with non-zero status";
                
                // Include error output if available
                if (!empty(trim($errorOutput))) {
                    $errorMessage .= "\nError output: " . trim($errorOutput);
                }
                
                // Include regular output if it contains useful info and is different from error output
                if (!empty(trim($output)) && trim($errorOutput) !== trim($output)) {
                    $errorMessage .= "\nOutput: " . trim($output);
                }
                
                throw new \Exception($errorMessage);
            }
            
            return $result->output();
        } catch (\Exception $e) {
            // If it's already our formatted exception, re-throw it
            if (strpos($e->getMessage(), 'SSH command failed') === 0) {
                throw $e;
            }
            
            // For other exceptions (like ProcessFailedException), preserve the message
            throw new \Exception("SSH command failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute multiple commands on the remote server.
     */
    public function executeMultiple(array $commands): string
    {
        $combinedCommand = implode(' && ', $commands);
        return $this->execute($combinedCommand);
    }

    /**
     * Check if SSH connection is working.
     */
    public function testConnection(): bool
    {
        try {
            $this->execute('echo "SSH connection test successful"');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the public key from the server.
     */
    public function getPublicKey(): ?string
    {
        try {
            return trim($this->execute('cat ~/.ssh/id_rsa.pub 2>/dev/null || echo ""'));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the private key from the server.
     */
    public function getPrivateKey(): ?string
    {
        try {
            return trim($this->execute('cat ~/.ssh/id_rsa 2>/dev/null || echo ""'));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generate SSH key pair on the server if it doesn't exist.
     */
    public function generateSshKey(): bool
    {
        try {
            $this->execute('ssh-keygen -t rsa -b 4096 -C "github-deploy-key" -N "" -f ~/.ssh/id_rsa');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Add a public key to authorized_keys if it doesn't already exist.
     */
    public function addToAuthorizedKeys(string $publicKey): bool
    {
        try {
            // Check if the key already exists in authorized_keys
            $keyExists = $this->keyExistsInAuthorizedKeys($publicKey);
            
            if ($keyExists) {
                // Key already exists, don't add it again
                return true;
            }

            // Key doesn't exist, add it
            $this->execute("echo '{$publicKey}' >> ~/.ssh/authorized_keys");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a public key already exists in authorized_keys.
     */
    public function keyExistsInAuthorizedKeys(string $publicKey): bool
    {
        try {
            // Extract the key part (without comment) for comparison
            $keyParts = explode(' ', trim($publicKey));
            if (count($keyParts) < 2) {
                return false;
            }
            
            $keyData = $keyParts[1]; // The actual key data (middle part)
            
            // Check if this key data exists in authorized_keys
            // Use grep with escaped key data to avoid special character issues
            $escapedKeyData = escapeshellarg($keyData);
            $command = "grep -Fq {$escapedKeyData} ~/.ssh/authorized_keys 2>/dev/null && echo 'exists' || echo 'not_exists'";
            $result = trim($this->execute($command));
            
            return $result === 'exists';
        } catch (\Exception $e) {
            // If we can't check, assume it doesn't exist
            return false;
        }
    }

    /**
     * Check if SSH key exists on the server.
     */
    public function sshKeyExists(): bool
    {
        try {
            $result = $this->execute('test -f ~/.ssh/id_rsa && echo "exists" || echo "not_exists"');
            return trim($result) === 'exists';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build the SSH command string.
     * Uses bash -c with proper escaping for reliable command execution.
     */
    protected function buildSshCommand(string $command): string
    {
        $sshOptions = [
            '-p ' . $this->port,
            '-o ConnectTimeout=' . $this->timeout,
            '-o StrictHostKeyChecking=no',
            '-o UserKnownHostsFile=/dev/null',
        ];

        // Use proper escaping for SSH command execution
        // Escape the command properly for the shell
        $escapedCommand = escapeshellarg($command);
        $sshCommand = 'ssh ' . implode(' ', $sshOptions) . ' ' . $this->username . '@' . $this->host . ' ' . $escapedCommand;
        
        return $sshCommand;
    }

    /**
     * Check if a directory exists.
     */
    public function directoryExists(string $path): bool
    {
        try {
            // Path is escaped by buildSshCommand, so use single quotes inside
            $result = $this->execute("test -d '{$path}' && echo 'exists' || echo 'not_exists'");
            return trim($result) === 'exists';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a file exists.
     */
    public function fileExists(string $path): bool
    {
        try {
            // Path is escaped by buildSshCommand, so use single quotes inside
            $result = $this->execute("test -f '{$path}' && echo 'exists' || echo 'not_exists'");
            return trim($result) === 'exists';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a directory is empty.
     */
    public function directoryIsEmpty(string $path): bool
    {
        try {
            // Path is escaped by buildSshCommand, so use single quotes inside
            $result = $this->execute("test -d '{$path}' && [ -z \"\$(ls -A '{$path}' 2>/dev/null)\" ] && echo 'empty' || echo 'not_empty'");
            return trim($result) === 'empty';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Execute a command in a specific directory.
     */
    public function executeInDirectory(string $path, string $command): string
    {
        $fullCommand = "cd " . escapeshellarg($path) . " && " . $command;
        return $this->execute($fullCommand);
    }

    /**
     * Get connection details for display.
     */
    public function getConnectionString(): string
    {
        return "ssh -p {$this->port} {$this->username}@{$this->host}";
    }
}
