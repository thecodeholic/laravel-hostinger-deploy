<?php

namespace TheCodeholic\LaravelHostingerDeploy\Services;

use Illuminate\Support\Facades\Process;

class GitHubActionsService
{
    /**
     * Get the current Git repository URL.
     */
    public function getRepositoryUrl(): ?string
    {
        try {
            $result = Process::run('git config --get remote.origin.url');
            
            if (!$result->successful()) {
                return null;
            }
            
            return trim($result->output());
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the current branch name.
     */
    public function getCurrentBranch(): string
    {
        try {
            $result = Process::run('git branch --show-current');
            
            if (!$result->successful()) {
                return 'main';
            }
            
            $branch = trim($result->output());
            return $branch ?: 'main';
        } catch (\Exception $e) {
            return 'main';
        }
    }

    /**
     * Extract repository owner and name from Git URL.
     */
    public function parseRepositoryUrl(string $url): ?array
    {
        // Handle SSH URLs: git@github.com:owner/repo.git
        if (preg_match('/git@github\.com:([^\/]+)\/([^\/]+)\.git/', $url, $matches)) {
            return [
                'owner' => $matches[1],
                'name' => $matches[2],
                'url' => "https://github.com/{$matches[1]}/{$matches[2]}"
            ];
        }
        
        // Handle HTTPS URLs: https://github.com/owner/repo.git
        if (preg_match('/https:\/\/github\.com\/([^\/]+)\/([^\/]+)\.git/', $url, $matches)) {
            return [
                'owner' => $matches[1],
                'name' => $matches[2],
                'url' => "https://github.com/{$matches[1]}/{$matches[2]}"
            ];
        }
        
        return null;
    }

    /**
     * Get GitHub repository settings URL for secrets.
     */
    public function getSecretsUrl(string $owner, string $name): string
    {
        return "https://github.com/{$owner}/{$name}/settings/secrets/actions";
    }

    /**
     * Get GitHub repository settings URL for variables.
     */
    public function getVariablesUrl(string $owner, string $name): string
    {
        return "https://github.com/{$owner}/{$name}/settings/variables/actions";
    }

    /**
     * Get GitHub repository settings URL for deploy keys.
     */
    public function getDeployKeysUrl(string $owner, string $name): string
    {
        return "https://github.com/{$owner}/{$name}/settings/keys";
    }

    /**
     * Check if we're in a Git repository.
     */
    public function isGitRepository(): bool
    {
        try {
            $result = Process::run('git rev-parse --git-dir');
            return $result->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get repository information for display.
     */
    public function getRepositoryInfo(): ?array
    {
        if (!$this->isGitRepository()) {
            return null;
        }

        $url = $this->getRepositoryUrl();
        if (!$url) {
            return null;
        }

        $parsed = $this->parseRepositoryUrl($url);
        if (!$parsed) {
            return null;
        }

        return [
            'url' => $url,
            'owner' => $parsed['owner'],
            'name' => $parsed['name'],
            'branch' => $this->getCurrentBranch(),
            'secrets_url' => $this->getSecretsUrl($parsed['owner'], $parsed['name']),
            'variables_url' => $this->getVariablesUrl($parsed['owner'], $parsed['name']),
            'deploy_keys_url' => $this->getDeployKeysUrl($parsed['owner'], $parsed['name']),
        ];
    }
}
