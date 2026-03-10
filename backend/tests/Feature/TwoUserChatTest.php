<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AzurePubSubPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class TwoUserChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    public function test_two_users_can_exchange_messages()
    {
        // Create two test users
        $user1 = User::factory()->create([
            'name' => 'Test User 1',
            'email' => 'testuser1@example.com',
        ]);

        $user2 = User::factory()->create([
            'name' => 'Test User 2',
            'email' => 'testuser2@example.com',
        ]);

        // Mock the publisher to track broadcast calls
        $mockPublisher = Mockery::mock(AzurePubSubPublisher::class);
        $broadcastedMessages = [];

        $mockPublisher->shouldReceive('broadcast')
            ->twice()
            ->with('message', Mockery::on(function ($message) use (&$broadcastedMessages) {
                $broadcastedMessages[] = $message;
                return true;
            }))
            ->andReturn(true);

        $this->app->instance(AzurePubSubPublisher::class, $mockPublisher);

        // User 1 sends a message
        $response1 = $this->actingAs($user1)->postJson('/api/messages/send', [
            'content' => 'Hello from User 1!',
        ]);

        $response1->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => [
                    'userId' => (string) $user1->id,
                    'userName' => 'Test User 1',
                    'content' => 'Hello from User 1!',
                ],
            ]);

        // User 2 sends a message
        $response2 = $this->actingAs($user2)->postJson('/api/messages/send', [
            'content' => 'Hi from User 2!',
        ]);

        $response2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => [
                    'userId' => (string) $user2->id,
                    'userName' => 'Test User 2',
                    'content' => 'Hi from User 2!',
                ],
            ]);

        // Verify both messages were broadcast
        $this->assertCount(2, $broadcastedMessages);
        $this->assertEquals('Hello from User 1!', $broadcastedMessages[0]['content']);
        $this->assertEquals('Hi from User 2!', $broadcastedMessages[1]['content']);
    }

    public function test_messages_have_unique_ids()
    {
        $user1 = User::factory()->create(['name' => 'User 1']);
        $user2 = User::factory()->create(['name' => 'User 2']);

        $mockPublisher = Mockery::mock(AzurePubSubPublisher::class);
        $messageIds = [];

        $mockPublisher->shouldReceive('broadcast')
            ->twice()
            ->with('message', Mockery::on(function ($message) use (&$messageIds) {
                $messageIds[] = $message['id'];
                return true;
            }))
            ->andReturn(true);

        $this->app->instance(AzurePubSubPublisher::class, $mockPublisher);

        // Send messages from both users
        $this->actingAs($user1)->postJson('/api/messages/send', [
            'content' => 'Message 1',
        ]);

        $this->actingAs($user2)->postJson('/api/messages/send', [
            'content' => 'Message 2',
        ]);

        // Verify IDs are unique
        $this->assertNotEquals($messageIds[0], $messageIds[1]);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $messageIds[0]
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $messageIds[1]
        );
    }

    public function test_concurrent_message_exchange()
    {
        $user1 = User::factory()->create(['name' => 'Alice']);
        $user2 = User::factory()->create(['name' => 'Bob']);

        $mockPublisher = Mockery::mock(AzurePubSubPublisher::class);
        $broadcastedMessages = [];

        $mockPublisher->shouldReceive('broadcast')
            ->times(4)
            ->with('message', Mockery::on(function ($message) use (&$broadcastedMessages) {
                $broadcastedMessages[] = $message;
                return true;
            }))
            ->andReturn(true);

        $this->app->instance(AzurePubSubPublisher::class, $mockPublisher);

        // Simulate a conversation
        $this->actingAs($user1)->postJson('/api/messages/send', ['content' => 'Hey Bob!']);
        $this->actingAs($user2)->postJson('/api/messages/send', ['content' => 'Hi Alice!']);
        $this->actingAs($user1)->postJson('/api/messages/send', ['content' => 'How are you?']);
        $this->actingAs($user2)->postJson('/api/messages/send', ['content' => 'Great, thanks!']);

        // Verify all messages were broadcast in order
        $this->assertCount(4, $broadcastedMessages);
        $this->assertEquals('Hey Bob!', $broadcastedMessages[0]['content']);
        $this->assertEquals('Hi Alice!', $broadcastedMessages[1]['content']);
        $this->assertEquals('How are you?', $broadcastedMessages[2]['content']);
        $this->assertEquals('Great, thanks!', $broadcastedMessages[3]['content']);

        // Verify senders are correct
        $this->assertEquals('Alice', $broadcastedMessages[0]['userName']);
        $this->assertEquals('Bob', $broadcastedMessages[1]['userName']);
        $this->assertEquals('Alice', $broadcastedMessages[2]['userName']);
        $this->assertEquals('Bob', $broadcastedMessages[3]['userName']);
    }
}
