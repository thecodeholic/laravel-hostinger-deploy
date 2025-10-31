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
     * Get all deploy keys for a repository.
     */
    public function getDeployKeys(string $owner, string $repo): array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github.v3+json',
            'Authorization' => "Bearer {$this->token}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->get("{$this->baseUrl}/repos/{$owner}/{$repo}/keys");

        if (!$response->successful()) {
            throw new Exception("Failed to get deploy keys: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Check if a deploy key already exists (by comparing the key content).
     */
    public function keyExists(string $owner, string $repo, string $publicKey): bool
    {
        try {
            $keys = $this->getDeployKeys($owner, $repo);
            
            // Extract the key part from the public key (remove comment, whitespace, etc.)
            $normalizeKey = function($key) {
                // Remove comment part and normalize whitespace
                $parts = explode(' ', trim($key));
                return isset($parts[1]) ? $parts[0] . ' ' . $parts[1] : $key;
            };
            
            $normalizedTargetKey = $normalizeKey($publicKey);
            
            foreach ($keys as $key) {
                if (isset($key['key'])) {
                    $normalizedExistingKey = $normalizeKey($key['key']);
                    if ($normalizedExistingKey === $normalizedTargetKey) {
                        return true;
                    }
                }
            }
            
            return false;
        } catch (Exception $e) {
            // If we can't check, assume it doesn't exist
            return false;
        }
    }

    /**
     * Create a deploy key for a repository.
     * 
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $publicKey The public SSH key
     * @param string $title Optional title for the deploy key (default: "Hostinger Server")
     * @param bool $readOnly Whether the key should be read-only (default: false)
     * @return array The created deploy key data
     */
    public function createDeployKey(string $owner, string $repo, string $publicKey, string $title = 'Hostinger Server', bool $readOnly = false): array
    {
        // Check if key already exists
        if ($this->keyExists($owner, $repo, $publicKey)) {
            throw new Exception("Deploy key already exists for this repository");
        }

        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github.v3+json',
            'Authorization' => "Bearer {$this->token}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->post(
            "{$this->baseUrl}/repos/{$owner}/{$repo}/keys",
            [
                'title' => $title,
                'key' => trim($publicKey),
                'read_only' => $readOnly,
            ]
        );

        if (!$response->successful()) {
            throw new Exception("Failed to create deploy key: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Delete a deploy key by ID.
     */
    public function deleteDeployKey(string $owner, string $repo, int $keyId): bool
    {
        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github.v3+json',
            'Authorization' => "Bearer {$this->token}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->delete("{$this->baseUrl}/repos/{$owner}/{$repo}/keys/{$keyId}");

        if (!$response->successful()) {
            throw new Exception("Failed to delete deploy key: " . $response->body());
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
