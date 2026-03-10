<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AzurePubSubTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PubSubControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_negotiate()
    {
        $user = User::factory()->create();

        $mockTokenService = Mockery::mock(AzurePubSubTokenService::class);
        $mockTokenService->shouldReceive('generateClientToken')
            ->once()
            ->with($user->id, [])
            ->andReturn([
                'endpoint' => 'https://test.webpubsub.azure.com',
                'hub' => 'chat',
                'token' => 'mock-jwt-token',
                'expires' => time() + 3600,
            ]);

        $this->app->instance(AzurePubSubTokenService::class, $mockTokenService);

        $response = $this->actingAs($user)->getJson('/api/negotiate');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'endpoint',
                'hub',
                'token',
                'expires',
            ]);
    }

    public function test_guest_cannot_negotiate()
    {
        $response = $this->getJson('/api/negotiate');

        $response->assertStatus(401);
    }

    public function test_negotiate_includes_user_roles()
    {
        $user = User::factory()->create();

        $mockTokenService = Mockery::mock(AzurePubSubTokenService::class);
        $mockTokenService->shouldReceive('generateClientToken')
            ->once()
            ->with($user->id, ['admin', 'moderator'])
            ->andReturn([
                'endpoint' => 'https://test.webpubsub.azure.com',
                'hub' => 'chat',
                'token' => 'mock-jwt-token',
                'expires' => time() + 3600,
            ]);

        $this->app->instance(AzurePubSubTokenService::class, $mockTokenService);

        // Create a custom controller to test role assignment
        $controller = new class($mockTokenService) extends \App\Http\Controllers\PubSubController {
            public function __construct($tokenService)
            {
                parent::__construct($tokenService);
            }

            protected function getUserRoles(mixed $user): array
            {
                return ['admin', 'moderator'];
            }
        };

        $request = \Illuminate\Http\Request::create('/api/negotiate');
        $request->setUserResolver(fn() => $user);

        $response = $controller->negotiate($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('token', $data);
    }
}
