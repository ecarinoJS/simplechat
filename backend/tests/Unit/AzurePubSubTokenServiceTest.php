<?php

namespace Tests\Unit;

use App\Services\AzurePubSubConfig;
use App\Services\AzurePubSubTokenService;
use Tests\TestCase;

class AzurePubSubTokenServiceTest extends TestCase
{
    private AzurePubSubConfig $config;
    private AzurePubSubTokenService $tokenService;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionString = 'Endpoint=https://test.webpubsub.azure.com;AccessKey=' . str_repeat('a', 32) . ';Version=1.0;';
        $this->config = new AzurePubSubConfig($connectionString, 'chat');
        $this->tokenService = new AzurePubSubTokenService($this->config);
    }

    public function test_it_generates_client_token_with_user_id()
    {
        $tokenData = $this->tokenService->generateClientToken('user123');

        $this->assertArrayHasKey('endpoint', $tokenData);
        $this->assertArrayHasKey('hub', $tokenData);
        $this->assertArrayHasKey('token', $tokenData);
        $this->assertArrayHasKey('expires', $tokenData);

        $this->assertEquals('https://test.webpubsub.azure.com', $tokenData['endpoint']);
        $this->assertEquals('chat', $tokenData['hub']);
        $this->assertIsString($tokenData['token']);
        $this->assertIsInt($tokenData['expires']);
        $this->assertGreaterThan(time(), $tokenData['expires']);
    }

    public function test_it_generates_client_token_without_user_id()
    {
        $tokenData = $this->tokenService->generateClientToken();

        $this->assertArrayHasKey('endpoint', $tokenData);
        $this->assertArrayHasKey('hub', $tokenData);
        $this->assertArrayHasKey('token', $tokenData);
        $this->assertArrayHasKey('expires', $tokenData);
    }

    public function test_it_generates_client_token_with_roles()
    {
        $roles = ['admin', 'moderator'];
        $tokenData = $this->tokenService->generateClientToken('user123', $roles);

        $this->assertIsString($tokenData['token']);

        // Decode JWT to verify claims (basic validation)
        $tokenParts = explode('.', $tokenData['token']);
        $this->assertCount(3, $tokenParts); // header, payload, signature

        $payload = json_decode(base64_decode($tokenParts[1]), true);
        $this->assertEquals('user123', $payload['sub']);
        $this->assertEquals($roles, $payload['role']);
    }

    public function test_it_generates_service_token()
    {
        $token = $this->tokenService->generateServiceToken();

        $this->assertIsString($token);

        // Decode JWT to verify claims (basic validation)
        $tokenParts = explode('.', $token);
        $this->assertCount(3, $tokenParts); // header, payload, signature

        $payload = json_decode(base64_decode($tokenParts[1]), true);
        $this->assertArrayHasKey('role', $payload);
        $this->assertEquals(['webpubsub.service'], $payload['role']);
    }

    public function test_it_uses_custom_expiration_for_client_token()
    {
        $customExpiration = 120; // 2 hours
        $tokenData = $this->tokenService->generateClientToken('user123', [], $customExpiration);

        $expectedExpires = time() + ($customExpiration * 60);
        $this->assertEqualsWithDelta($expectedExpires, $tokenData['expires'], 5); // Allow 5 seconds tolerance
    }

    public function test_it_uses_custom_expiration_for_service_token()
    {
        $customExpiration = 120; // 2 hours
        $token = $this->tokenService->generateServiceToken($customExpiration);

        // Decode JWT to check expiration
        $tokenParts = explode('.', $token);
        $payload = json_decode(base64_decode($tokenParts[1]), true);

        $expectedExpires = time() + ($customExpiration * 60);
        $this->assertEqualsWithDelta($expectedExpires, $payload['exp'], 5); // Allow 5 seconds tolerance
    }

    public function test_client_token_has_correct_audience()
    {
        $tokenData = $this->tokenService->generateClientToken('user123');
        $token = $tokenData['token'];

        // Decode JWT to verify audience claim
        $tokenParts = explode('.', $token);
        $payload = json_decode(base64_decode($tokenParts[1]), true);

        $expectedAudience = 'https://test.webpubsub.azure.com/clients/socketio/hubs/chat';
        $this->assertEquals($expectedAudience, $payload['aud']);
    }

    public function test_service_token_has_correct_audience()
    {
        $token = $this->tokenService->generateServiceToken();

        // Decode JWT to verify audience claim
        $tokenParts = explode('.', $token);
        $payload = json_decode(base64_decode($tokenParts[1]), true);

        $expectedAudience = 'https://test.webpubsub.azure.com';
        $this->assertEquals($expectedAudience, $payload['aud']);
    }

    public function test_token_contains_issued_at_claim()
    {
        $token = $this->tokenService->generateServiceToken();

        // Decode JWT to check issued at claim
        $tokenParts = explode('.', $token);
        $payload = json_decode(base64_decode($tokenParts[1]), true);

        $this->assertArrayHasKey('iat', $payload);
        $this->assertEqualsWithDelta(time(), $payload['iat'], 5); // Allow 5 seconds tolerance
    }

    public function test_token_contains_expiration_claim()
    {
        $token = $this->tokenService->generateServiceToken();

        // Decode JWT to check expiration claim
        $tokenParts = explode('.', $token);
        $payload = json_decode(base64_decode($tokenParts[1]), true);

        $this->assertArrayHasKey('exp', $payload);
        $this->assertGreaterThan(time(), $payload['exp']);
    }
}
