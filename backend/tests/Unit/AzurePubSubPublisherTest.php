<?php

namespace Tests\Unit;

use App\Services\AzurePubSubConfig;
use App\Services\AzurePubSubPublisher;
use App\Services\AzurePubSubTokenService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class AzurePubSubPublisherTest extends TestCase
{
    private AzurePubSubConfig $config;
    private AzurePubSubTokenService $tokenService;
    private AzurePubSubPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionString = 'Endpoint=https://test.webpubsub.azure.com;AccessKey=' . str_repeat('a', 32) . ';Version=1.0;';
        $this->config = new AzurePubSubConfig($connectionString, 'chat');
        $this->tokenService = new AzurePubSubTokenService($this->config);
        $this->publisher = new AzurePubSubPublisher($this->config, $this->tokenService);

        // Spy on logging to prevent interference
        Log::spy();
    }

    public function test_broadcast_sends_message_to_correct_endpoint()
    {
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat' => Http::response('', 200),
        ]);

        $event = 'message';
        $data = ['content' => 'Hello, world!'];

        $result = $this->publisher->broadcast($event, $data);

        $this->assertTrue($result);

        Http::assertSent(function ($request) use ($event, $data) {
            $payload = $request->data();
            return $request->url() === 'https://test.webpubsub.azure.com/api/hubs/chat' &&
                   $payload[0] === $event &&
                   $payload[1] === $data &&
                   $request->hasHeader('Authorization') &&
                   $request->hasHeader('Content-Type', 'application/json') &&
                   $request->hasHeader('Ce-Type', 'azure-webpubsub.socketio.v1');
        });
    }

    public function test_send_to_user_sends_message_to_correct_endpoint()
    {
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat/users/user123/:send' => Http::response('', 200),
        ]);

        $userId = 'user123';
        $event = 'private_message';
        $data = ['content' => 'Private message'];

        $result = $this->publisher->sendToUser($userId, $event, $data);

        $this->assertTrue($result);

        Http::assertSent(function ($request) use ($userId, $event, $data) {
            $expectedUrl = 'https://test.webpubsub.azure.com/api/hubs/chat/users/user123/:send';
            $payload = $request->data();
            return $request->url() === $expectedUrl &&
                   $payload[0] === $event &&
                   $payload[1] === $data;
        });
    }

    public function test_send_to_group_sends_message_to_correct_endpoint()
    {
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat/groups/general/:send' => Http::response('', 200),
        ]);

        $group = 'general';
        $event = 'group_message';
        $data = ['content' => 'Group message'];

        $result = $this->publisher->sendToGroup($group, $event, $data);

        $this->assertTrue($result);

        Http::assertSent(function ($request) use ($group, $event, $data) {
            $expectedUrl = 'https://test.webpubsub.azure.com/api/hubs/chat/groups/general/:send';
            $payload = $request->data();
            return $request->url() === $expectedUrl &&
                   $payload[0] === $event &&
                   $payload[1] === $data;
        });
    }

    public function test_add_user_to_group_sends_correct_request()
    {
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat/groups/general/users/user123' => Http::response('', 200),
        ]);

        $userId = 'user123';
        $group = 'general';

        $result = $this->publisher->addUserToGroup($userId, $group);

        $this->assertTrue($result);

        Http::assertSent(function ($request) use ($userId, $group) {
            $expectedUrl = 'https://test.webpubsub.azure.com/api/hubs/chat/groups/general/users/user123';
            return $request->url() === $expectedUrl &&
                   $request->method() === 'PUT' &&
                   $request->hasHeader('Authorization') &&
                   $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_remove_user_from_group_sends_correct_request()
    {
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat/groups/general/users/user123' => Http::response('', 200),
        ]);

        $userId = 'user123';
        $group = 'general';

        $result = $this->publisher->removeUserFromGroup($userId, $group);

        $this->assertTrue($result);

        Http::assertSent(function ($request) use ($userId, $group) {
            $expectedUrl = 'https://test.webpubsub.azure.com/api/hubs/chat/groups/general/users/user123';
            return $request->url() === $expectedUrl &&
                   $request->method() === 'DELETE' &&
                   $request->hasHeader('Authorization') &&
                   $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_broadcast_returns_false_on_http_error()
    {
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat' => Http::response('', 500),
        ]);

        Log::shouldReceive('error')->once()->with('Azure PubSub publish failed', Mockery::type('array'));

        $result = $this->publisher->broadcast('message', ['content' => 'test']);

        $this->assertFalse($result);
    }

    public function test_broadcast_returns_false_on_connection_exception()
    {
        // Mock the HTTP facade to throw a ConnectionException
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat' => function () {
                throw new ConnectionException('Connection failed');
            },
        ]);

        Log::shouldReceive('error')->once()->with('Azure PubSub connection error', Mockery::type('array'));

        $result = $this->publisher->broadcast('message', ['content' => 'test']);

        $this->assertFalse($result);
    }

    public function test_add_user_to_group_returns_false_on_http_error()
    {
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat/groups/general/users/user123' => Http::response('', 404),
        ]);

        Log::shouldReceive('error')->once()->with('Azure PubSub add user to group failed', Mockery::type('array'));

        $result = $this->publisher->addUserToGroup('user123', 'general');

        $this->assertFalse($result);
    }

    public function test_remove_user_from_group_returns_false_on_http_error()
    {
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat/groups/general/users/user123' => Http::response('', 404),
        ]);

        Log::shouldReceive('error')->once()->with('Azure PubSub remove user from group failed', Mockery::type('array'));

        $result = $this->publisher->removeUserFromGroup('user123', 'general');

        $this->assertFalse($result);
    }

    public function test_add_user_to_group_returns_false_on_connection_exception()
    {
        // Mock the HTTP facade to throw a ConnectionException
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat/groups/general/users/user123' => function () {
                throw new ConnectionException('Connection failed');
            },
        ]);

        Log::shouldReceive('error')->once()->with('Azure PubSub connection error', Mockery::type('array'));

        $result = $this->publisher->addUserToGroup('user123', 'general');

        $this->assertFalse($result);
    }

    public function test_remove_user_from_group_returns_false_on_connection_exception()
    {
        // Mock the HTTP facade to throw a ConnectionException
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat/groups/general/users/user123' => function () {
                throw new ConnectionException('Connection failed');
            },
        ]);

        Log::shouldReceive('error')->once()->with('Azure PubSub connection error', Mockery::type('array'));

        $result = $this->publisher->removeUserFromGroup('user123', 'general');

        $this->assertFalse($result);
    }

    public function test_broadcast_logs_successful_response()
    {
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat' => Http::response('Success', 200),
        ]);

        Log::shouldReceive('info')->once()->with('Azure PubSub response', Mockery::type('array'));

        $this->publisher->broadcast('message', ['content' => 'test']);
    }

    public function test_payload_format_is_correct()
    {
        Http::fake([
            'https://test.webpubsub.azure.com/api/hubs/chat' => Http::response('', 200),
        ]);

        $event = 'test_event';
        $data = ['key' => 'value'];

        $this->publisher->broadcast($event, $data);

        Http::assertSent(function ($request) use ($event, $data) {
            $payload = $request->data();
            return is_array($payload) &&
                   count($payload) === 2 &&
                   $payload[0] === $event &&
                   $payload[1] === $data;
        });
    }
}
