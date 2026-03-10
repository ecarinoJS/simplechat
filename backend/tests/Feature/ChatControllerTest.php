<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AzurePubSubPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Don't interfere with actual logging during tests
        Log::spy();
    }

    public function test_authenticated_user_can_send_message()
    {
        $user = User::factory()->create();

        $mockPublisher = Mockery::mock(AzurePubSubPublisher::class);
        $mockPublisher->shouldReceive('broadcast')
            ->once()
            ->with('message', Mockery::type('array'))
            ->andReturn(true);

        $this->app->instance(AzurePubSubPublisher::class, $mockPublisher);

        $messageData = [
            'content' => 'Hello, world!',
        ];

        $response = $this->actingAs($user)->postJson('/api/messages/send', $messageData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message' => [
                    'id',
                    'userId',
                    'userName',
                    'content',
                    'timestamp',
                ],
            ]);

        $responseData = $response->json();
        $this->assertTrue($responseData['success']);
        $this->assertEquals('Hello, world!', $responseData['message']['content']);
        $this->assertEquals($user->id, $responseData['message']['userId']);
        $this->assertEquals($user->name, $responseData['message']['userName']);
    }

    public function test_guest_cannot_send_message()
    {
        $messageData = [
            'content' => 'Hello, world!',
        ];

        $response = $this->postJson('/api/messages/send', $messageData);

        $response->assertStatus(401);
    }

    public function test_message_validation_requires_content()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/messages/send', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    public function test_message_content_has_maximum_length()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/messages/send', [
            'content' => str_repeat('a', 1001),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    public function test_send_message_handles_broadcast_failure()
    {
        $user = User::factory()->create();

        $mockPublisher = Mockery::mock(AzurePubSubPublisher::class);
        $mockPublisher->shouldReceive('broadcast')
            ->once()
            ->with('message', Mockery::type('array'))
            ->andReturn(false);

        $this->app->instance(AzurePubSubPublisher::class, $mockPublisher);

        Log::shouldReceive('error')
            ->once()
            ->with('Failed to broadcast message to Azure PubSub', Mockery::type('array'));

        $messageData = [
            'content' => 'Hello, world!',
        ];

        $response = $this->actingAs($user)->postJson('/api/messages/send', $messageData);

        $response->assertStatus(500)
            ->assertJson(['error' => 'Failed to send message']);
    }

    public function test_message_id_is_uuid()
    {
        $user = User::factory()->create();

        $mockPublisher = Mockery::mock(AzurePubSubPublisher::class);
        $mockPublisher->shouldReceive('broadcast')
            ->once()
            ->with('message', Mockery::type('array'))
            ->andReturn(true);

        $this->app->instance(AzurePubSubPublisher::class, $mockPublisher);

        $messageData = [
            'content' => 'Hello, world!',
        ];

        $response = $this->actingAs($user)->postJson('/api/messages/send', $messageData);

        $responseData = $response->json();
        $messageId = $responseData['message']['id'];

        // Check if the ID is a valid UUID format
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $messageId
        );
    }

    public function test_timestamp_is_iso8601_format()
    {
        $user = User::factory()->create();

        $mockPublisher = Mockery::mock(AzurePubSubPublisher::class);
        $mockPublisher->shouldReceive('broadcast')
            ->once()
            ->with('message', Mockery::type('array'))
            ->andReturn(true);

        $this->app->instance(AzurePubSubPublisher::class, $mockPublisher);

        $messageData = [
            'content' => 'Hello, world!',
        ];

        $response = $this->actingAs($user)->postJson('/api/messages/send', $messageData);

        $responseData = $response->json();
        $timestamp = $responseData['message']['timestamp'];

        // Check if the timestamp is in ISO 8601 format
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}$/',
            $timestamp
        );
    }
}
