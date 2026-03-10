<?php

namespace App\Services;

use InvalidArgumentException;

class AzurePubSubConfig
{
    public readonly string $endpoint;
    public readonly string $accessKey;
    public readonly string $version;
    public readonly string $hub;

    public function __construct(?string $connectionString = null, ?string $hub = null)
    {
        $connectionString = $connectionString ?? config('azure.connection_string');
        $this->hub = $hub ?? config('azure.hub', 'chat');

        if (empty($connectionString)) {
            throw new InvalidArgumentException('Azure Web PubSub connection string is not configured.');
        }

        $this->parseConnectionString($connectionString);
    }

    /**
     * Parse the Azure Web PubSub connection string.
     *
     * Format: Endpoint=https://<name>.webpubsub.azure.com;AccessKey=<key>;Version=1.0;
     */
    protected function parseConnectionString(string $connectionString): void
    {
        $parts = explode(';', $connectionString);
        $config = [];

        foreach ($parts as $part) {
            if (empty($part)) {
                continue;
            }

            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $config[trim($pair[0])] = trim($pair[1]);
            }
        }

        $this->endpoint = $config['Endpoint'] ?? throw new InvalidArgumentException('Missing Endpoint in connection string.');
        $this->accessKey = $config['AccessKey'] ?? throw new InvalidArgumentException('Missing AccessKey in connection string.');
        $this->version = $config['Version'] ?? '1.0';
    }

    /**
     * Get the base URL for the Web PubSub service.
     */
    public function getBaseUrl(): string
    {
        return rtrim($this->endpoint, '/');
    }

    /**
     * Get the Socket.IO client URL for the audience claim.
     * This is the exact URL the client will connect to.
     *
     * Format: {endpoint}/clients/socketio/hubs/{hub}
     */
    public function getSocketIOClientUrl(): string
    {
        return sprintf('%s/clients/socketio/hubs/%s', $this->getBaseUrl(), $this->hub);
    }

    /**
     * Get REST API URL for publishing to hub.
     * For Socket.IO mode, broadcasts to the default group (all connected clients).
     */
    public function getRestApiUrl(): string
    {
        // Use default group - in Socket.IO mode this should reach all clients
        return sprintf('%s/api/hubs/%s/groups/default/:send', $this->getBaseUrl(), $this->hub);
    }

    /**
     * Get the hub endpoint URL for JWT audience claim in REST API tokens.
     * This is the format Azure Web PubSub expects for REST API authentication.
     */
    public function getHubEndpoint(): string
    {
        return sprintf('%s/api/hubs/%s', $this->getBaseUrl(), $this->hub);
    }

    /**
     * Get REST API URL for sending to a specific user.
     */
    public function getRestApiUserUrl(string $userId): string
    {
        return sprintf('%s/api/hubs/%s/users/%s/:send', $this->getBaseUrl(), $this->hub, $userId);
    }

    /**
     * Get REST API URL for sending to a specific group.
     */
    public function getRestApiGroupUrl(string $group): string
    {
        return sprintf('%s/api/hubs/%s/groups/%s/:send', $this->getBaseUrl(), $this->hub, $group);
    }
}
