<?php

namespace App\Services;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class AzurePubSubTokenService
{
    protected AzurePubSubConfig $config;
    protected Configuration $jwtConfig;

    public function __construct(?AzurePubSubConfig $config = null)
    {
        $this->config = $config ?? new AzurePubSubConfig();

        // Configure JWT with HMAC-SHA256 signing
        $this->jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->config->accessKey)
        );
    }

    /**
     * Generate a client access token for Socket.IO connection.
     *
     * @param string|null $userId The user identifier for the connection
     * @param array<string> $roles Optional roles to assign to the connection
     * @param int|null $expirationMinutes Token expiration time in minutes
     * @return array{endpoint: string, hub: string, token: string, expires: int}
     */
    public function generateClientToken(
        ?string $userId = null,
        array $roles = [],
        ?int $expirationMinutes = null
    ): array {
        $expirationMinutes = $expirationMinutes ?? config('azure.token_expiration', 60);
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify("+{$expirationMinutes} minutes");

        // Build the JWT token
        $builder = $this->jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->permittedFor($this->config->getSocketIOClientUrl()); // Critical: aud claim must match client URL

        // Add user ID as the subject claim
        if ($userId !== null) {
            $builder = $builder->relatedTo($userId); // sub claim
        }

        // Add roles as a custom claim
        if (!empty($roles)) {
            $builder = $builder->withClaim('role', $roles);
        }

        $token = $builder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return [
            'endpoint' => $this->config->getBaseUrl(),
            'hub' => $this->config->hub,
            'token' => $token->toString(),
            'expires' => $expiresAt->getTimestamp(),
        ];
    }

    /**
     * Generate a service access token for REST API operations.
     * This token is used for server-to-server communication.
     *
     * @param string|null $audience Optional custom audience (must match exact URL being called)
     * @param int|null $expirationMinutes Token expiration time in minutes
     * @return string The JWT token string
     */
    public function generateServiceToken(?string $audience = null, ?int $expirationMinutes = null): string
    {
        $expirationMinutes = $expirationMinutes ?? config('azure.token_expiration', 60);
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify("+{$expirationMinutes} minutes");

        // For Azure Web PubSub REST API, the audience must be the exact endpoint being called
        $audience = $audience ?? $this->config->getRestApiUrl();

        $token = $this->jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->permittedFor($audience) // Use exact REST API URL as audience
            ->withClaim('role', ['webpubsub.sendToGroup']) // Service role for REST API access
            ->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return $token->toString();
    }
}
