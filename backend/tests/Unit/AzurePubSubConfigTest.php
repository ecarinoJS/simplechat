<?php

namespace Tests\Unit;

use App\Services\AzurePubSubConfig;
use InvalidArgumentException;
use Tests\TestCase;

class AzurePubSubConfigTest extends TestCase
{
    public function test_it_parses_connection_string_correctly()
    {
        $connectionString = 'Endpoint=https://test.webpubsub.azure.com;AccessKey=' . str_repeat('a', 32) . ';Version=1.0;';
        $hub = 'chat';

        $config = new AzurePubSubConfig($connectionString, $hub);

        $this->assertEquals('https://test.webpubsub.azure.com', $config->endpoint);
        $this->assertEquals(str_repeat('a', 32), $config->accessKey);
        $this->assertEquals('1.0', $config->version);
        $this->assertEquals('chat', $config->hub);
    }

    public function test_it_throws_exception_for_empty_connection_string()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Azure Web PubSub connection string is not configured.');

        new AzurePubSubConfig('');
    }

    public function test_it_throws_exception_for_missing_endpoint()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing Endpoint in connection string.');

        new AzurePubSubConfig('AccessKey=' . str_repeat('a', 32) . ';Version=1.0;');
    }

    public function test_it_throws_exception_for_missing_access_key()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing AccessKey in connection string.');

        new AzurePubSubConfig('Endpoint=https://test.webpubsub.azure.com;Version=1.0;');
    }

    public function test_it_defaults_version_to_1_0()
    {
        $connectionString = 'Endpoint=https://test.webpubsub.azure.com;AccessKey=' . str_repeat('a', 32) . ';';

        $config = new AzurePubSubConfig($connectionString);

        $this->assertEquals('1.0', $config->version);
    }

    public function test_it_gets_base_url()
    {
        $connectionString = 'Endpoint=https://test.webpubsub.azure.com/;AccessKey=' . str_repeat('a', 32) . ';Version=1.0;';

        $config = new AzurePubSubConfig($connectionString);

        $this->assertEquals('https://test.webpubsub.azure.com', $config->getBaseUrl());
    }

    public function test_it_gets_socket_io_client_url()
    {
        $connectionString = 'Endpoint=https://test.webpubsub.azure.com;AccessKey=' . str_repeat('a', 32) . ';Version=1.0;';
        $hub = 'chat';

        $config = new AzurePubSubConfig($connectionString, $hub);

        $expected = 'https://test.webpubsub.azure.com/clients/socketio/hubs/chat';
        $this->assertEquals($expected, $config->getSocketIOClientUrl());
    }

    public function test_it_gets_rest_api_url()
    {
        $connectionString = 'Endpoint=https://test.webpubsub.azure.com;AccessKey=' . str_repeat('a', 32) . ';Version=1.0;';
        $hub = 'chat';

        $config = new AzurePubSubConfig($connectionString, $hub);

        $expected = 'https://test.webpubsub.azure.com/api/hubs/chat';
        $this->assertEquals($expected, $config->getRestApiUrl());
    }

    public function test_it_gets_rest_api_user_url()
    {
        $connectionString = 'Endpoint=https://test.webpubsub.azure.com;AccessKey=' . str_repeat('a', 32) . ';Version=1.0;';
        $hub = 'chat';

        $config = new AzurePubSubConfig($connectionString, $hub);

        $expected = 'https://test.webpubsub.azure.com/api/hubs/chat/users/user123/:send';
        $this->assertEquals($expected, $config->getRestApiUserUrl('user123'));
    }

    public function test_it_gets_rest_api_group_url()
    {
        $connectionString = 'Endpoint=https://test.webpubsub.azure.com;AccessKey=' . str_repeat('a', 32) . ';Version=1.0;';
        $hub = 'chat';

        $config = new AzurePubSubConfig($connectionString, $hub);

        $expected = 'https://test.webpubsub.azure.com/api/hubs/chat/groups/general/:send';
        $this->assertEquals($expected, $config->getRestApiGroupUrl('general'));
    }

    public function test_it_handles_whitespace_in_connection_string()
    {
        $connectionString = ' Endpoint = https://test.webpubsub.azure.com ; AccessKey = ' . str_repeat('a', 32) . ' ; Version = 1.0 ; ';

        $config = new AzurePubSubConfig($connectionString);

        $this->assertEquals('https://test.webpubsub.azure.com', $config->endpoint);
        $this->assertEquals(str_repeat('a', 32), $config->accessKey);
        $this->assertEquals('1.0', $config->version);
    }

    public function test_it_ignores_empty_parts_in_connection_string()
    {
        $connectionString = 'Endpoint=https://test.webpubsub.azure.com;;AccessKey=' . str_repeat('a', 32) . ';;Version=1.0;';

        $config = new AzurePubSubConfig($connectionString);

        $this->assertEquals('https://test.webpubsub.azure.com', $config->endpoint);
        $this->assertEquals(str_repeat('a', 32), $config->accessKey);
        $this->assertEquals('1.0', $config->version);
    }
}
