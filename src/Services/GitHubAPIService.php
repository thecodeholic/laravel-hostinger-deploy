<?php

namespace TheCodeholic\LaravelHostingerDeploy\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class GitHubAPIService
{
    protected string $token;
    protected string $baseUrl = 'https://api.github.com';

    public function __construct(?string $token = null)
    {
        $this->token = $token ?: config('hostinger-deploy.github.api_token') ?: env('GITHUB_API_TOKEN');
        
        if (!$this->token) {
            throw new Exception('GitHub API token is required. Set GITHUB_API_TOKEN in your .env file.');
        }
    }

    /**
     * Get repository public key for encrypting secrets.
     */
    public function getRepositoryPublicKey(string $owner, string $repo): array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github.v3+json',
            'Authorization' => "Bearer {$this->token}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->get("{$this->baseUrl}/repos/{$owner}/{$repo}/actions/secrets/public-key");

        if (!$response->successful()) {
            throw new Exception("Failed to get repository public key: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Encrypt a secret value using LibSodium (GitHub uses NaCl Box encryption).
     * Based on GitHub API documentation: https://docs.github.com/en/rest/actions/secrets
     */
    public function encryptSecret(string $plaintext, string $publicKey, string $keyId): array
    {
        if (!extension_loaded('sodium')) {
            throw new Exception('LibSodium extension is required for encrypting secrets. Install php-sodium extension.');
        }

        // Decode the base64 public key
        $publicKeyBinary = base64_decode($publicKey, true);
        
        if ($publicKeyBinary === false) {
            throw new Exception('Failed to decode public key');
        }

        // GitHub uses NaCl Box sealed encryption (anonymous encryption)
        // This automatically handles ephemeral key pair generation
        $encrypted = sodium_crypto_box_seal($plaintext, $publicKeyBinary);
        
        if ($encrypted === false) {
            throw new Exception('Failed to encrypt secret');
        }

        // Encode to base64 for API
        // Sealed box automatically includes ephemeral public key in the ciphertext
        $encryptedValue = base64_encode($encrypted);

        return [
            'encrypted_value' => $encryptedValue,
            'key_id' => $keyId,
        ];
    }

    /**
     * Create or update a repository secret.
     */
    public function createOrUpdateSecret(string $owner, string $repo, string $secretName, string $plaintextValue): bool
    {
        // Get public key
        $publicKeyData = $this->getRepositoryPublicKey($owner, $repo);
        $publicKey = $publicKeyData['key'];
        $keyId = $publicKeyData['key_id'];

        // Encrypt the secret
        $encryptedData = $this->encryptSecret($plaintextValue, $publicKey, $keyId);

        // Create or update the secret
        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github.v3+json',
            'Authorization' => "Bearer {$this->token}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->put(
            "{$this->baseUrl}/repos/{$owner}/{$repo}/actions/secrets/{$secretName}",
            $encryptedData
        );

        if (!$response->successful()) {
            throw new Exception("Failed to create/update secret {$secretName}: " . $response->body());
        }

        return true;
    }

    /**
     * Create or update a repository variable.
     */
    public function createOrUpdateVariable(string $owner, string $repo, string $variableName, string $value): bool
    {
        // Check if variable exists first
        $existingResponse = Http::withHeaders([
            'Accept' => 'application/vnd.github.v3+json',
            'Authorization' => "Bearer {$this->token}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->get("{$this->baseUrl}/repos/{$owner}/{$repo}/actions/variables/{$variableName}");

        $method = $existingResponse->successful() ? 'PATCH' : 'POST';
        $url = "{$this->baseUrl}/repos/{$owner}/{$repo}/actions/variables" . 
               ($method === 'PATCH' ? "/{$variableName}" : '');

        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github.v3+json',
            'Authorization' => "Bearer {$this->token}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->{strtolower($method)}($url, [
            'name' => $variableName,
            'value' => $value,
        ]);

        if (!$response->successful()) {
            throw new Exception("Failed to create/update variable {$variableName}: " . $response->body());
        }

        return true;
    }

    /**
     * Create or update a workflow file.
     */
    public function createOrUpdateWorkflowFile(string $owner, string $repo, string $branch, string $workflowContent): bool
    {
        $filePath = '.github/workflows/hostinger-deploy.yml';

        // Check if file exists
        $existingResponse = Http::withHeaders([
            'Accept' => 'application/vnd.github.v3+json',
            'Authorization' => "Bearer {$this->token}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->get("{$this->baseUrl}/repos/{$owner}/{$repo}/contents/{$filePath}");

        $sha = null;
        if ($existingResponse->successful()) {
            $sha = $existingResponse->json()['sha'];
        }

        $data = [
            'message' => $sha ? 'Update Hostinger deployment workflow' : 'Add Hostinger deployment workflow',
            'content' => base64_encode($workflowContent),
            'branch' => $branch,
        ];

        if ($sha) {
            $data['sha'] = $sha;
        }

        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github.v3+json',
            'Authorization' => "Bearer {$this->token}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->put(
            "{$this->baseUrl}/repos/{$owner}/{$repo}/contents/{$filePath}",
            $data
        );

        if (!$response->successful()) {
            throw new Exception("Failed to create/update workflow file: " . $response->body());
        }

        return true;
    }

    /**
     * Test API connection.
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github.v3+json',
                'Authorization' => "Bearer {$this->token}",
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->get("{$this->baseUrl}/user");

            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }
}
