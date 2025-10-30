<?php

namespace Zura\HostingerDeploy\Services;

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
                throw new ProcessFailedException($result);
            }
            
            return $result->output();
        } catch (ProcessFailedException $e) {
            throw new \Exception("SSH command failed: " . $e->getMessage());
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
     * Add a public key to authorized_keys.
     */
    public function addToAuthorizedKeys(string $publicKey): bool
    {
        try {
            $this->execute("echo '{$publicKey}' >> ~/.ssh/authorized_keys");
            return true;
        } catch (\Exception $e) {
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
