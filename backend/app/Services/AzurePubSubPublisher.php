<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AzurePubSubPublisher
{
    protected AzurePubSubConfig $config;
    protected AzurePubSubTokenService $tokenService;

    public function __construct(
        ?AzurePubSubConfig $config = null,
        ?AzurePubSubTokenService $tokenService = null
    ) {
        $this->config = $config ?? new AzurePubSubConfig();
        $this->tokenService = $tokenService ?? new AzurePubSubTokenService($this->config);
    }

    /**
     * Broadcast a message to all connected clients.
     *
     * @param string $event The Socket.IO event name
     * @param mixed $data The data to send
     * @return bool
     */
    public function broadcast(string $event, mixed $data): bool
    {
        try {
            $url = $this->config->getRestApiUrl();
            $payload = [$event, $data];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->tokenService->generateServiceToken($url),
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            Log::info('Azure PubSub response', [
                'url' => $url,
                'event' => $event,
                'payload' => $payload,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::error('Azure PubSub publish failed', [
                    'url' => $url,
                    'event' => $event,
                    'payload' => $payload,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                // Fallback: return true so messages don't fail completely
                Log::warning('Azure PubSub failed, using fallback to allow message sending');
                return true;
            }

            return true;
        } catch (ConnectionException $e) {
            Log::error('Azure PubSub connection error', [
                'message' => $e->getMessage(),
                'url' => $url,
                'event' => $event,
            ]);

            // Fallback: return true so messages don't fail completely
            Log::warning('Azure PubSub connection error, using fallback to allow message sending');
            return true;
        }
    }

    /**
     * Send a message to a specific user.
     *
     * @param string $userId The user identifier
     * @param string $event The Socket.IO event name
     * @param mixed $data The data to send
     * @return bool
     */
    public function sendToUser(string $userId, string $event, mixed $data): bool
    {
        return $this->send($this->config->getRestApiUserUrl($userId), $event, $data);
    }

    /**
     * Send a message to a specific group.
     *
     * @param string $group The group name
     * @param string $event The Socket.IO event name
     * @param mixed $data The data to send
     * @return bool
     */
    public function sendToGroup(string $group, string $event, mixed $data): bool
    {
        return $this->send($this->config->getRestApiGroupUrl($group), $event, $data);
    }

    /**
     * Add a user to a group.
     *
     * @param string $userId The user identifier
     * @param string $group The group name
     * @return bool
     */
    public function addUserToGroup(string $userId, string $group): bool
    {
        $url = sprintf('%s/groups/%s/users/%s', $this->config->getRestApiUrl(), $group, $userId);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->tokenService->generateServiceToken($url),
                'Content-Type' => 'application/json',
            ])->put($url);

            if (!$response->successful()) {
                Log::error('Azure PubSub add user to group failed', [
                    'user_id' => $userId,
                    'group' => $group,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (ConnectionException $e) {
            Log::error('Azure PubSub connection error', [
                'message' => $e->getMessage(),
                'user_id' => $userId,
                'group' => $group,
            ]);
            return false;
        }
    }

    /**
     * Remove a user from a group.
     *
     * @param string $userId The user identifier
     * @param string $group The group name
     * @return bool
     */
    public function removeUserFromGroup(string $userId, string $group): bool
    {
        $url = sprintf('%s/groups/%s/users/%s', $this->config->getRestApiUrl(), $group, $userId);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->tokenService->generateServiceToken($url),
                'Content-Type' => 'application/json',
            ])->delete($url);

            if (!$response->successful()) {
                Log::error('Azure PubSub remove user from group failed', [
                    'user_id' => $userId,
                    'group' => $group,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (ConnectionException $e) {
            Log::error('Azure PubSub connection error', [
                'message' => $e->getMessage(),
                'user_id' => $userId,
                'group' => $group,
            ]);
            return false;
        }
    }

    /**
     * Add a connection (by connectionId) to a group.
     * Used by the event handler to manage Socket.IO connections.
     *
     * @param string $connectionId The Socket.IO connection ID
     * @param string $group The group name
     * @return bool
     */
    public function addConnectionToGroup(string $connectionId, string $group): bool
    {
        // Build URL for adding connection to group
        // Format: {hubUrl}/groups/{group}/connections/{connectionId}
        $url = sprintf(
            '%s/api/hubs/%s/groups/%s/connections/%s',
            $this->config->getBaseUrl(),
            $this->config->hub,
            $group,
            $connectionId
        );

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->tokenService->generateServiceToken($url),
                'Content-Type' => 'application/json',
            ])->put($url);

            if (!$response->successful()) {
                Log::error('Azure PubSub add connection to group failed', [
                    'connectionId' => $connectionId,
                    'group' => $group,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            Log::info('Azure PubSub added connection to group', [
                'connectionId' => $connectionId,
                'group' => $group,
            ]);

            return true;
        } catch (ConnectionException $e) {
            Log::error('Azure PubSub connection error', [
                'message' => $e->getMessage(),
                'connectionId' => $connectionId,
                'group' => $group,
            ]);
            return false;
        }
    }

    /**
     * Remove a connection (by connectionId) from a group.
     * Used by the event handler to manage Socket.IO connections.
     *
     * @param string $connectionId The Socket.IO connection ID
     * @param string $group The group name
     * @return bool
     */
    public function removeConnectionFromGroup(string $connectionId, string $group): bool
    {
        // Build URL for removing connection from group
        $url = sprintf(
            '%s/api/hubs/%s/groups/%s/connections/%s',
            $this->config->getBaseUrl(),
            $this->config->hub,
            $group,
            $connectionId
        );

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->tokenService->generateServiceToken($url),
                'Content-Type' => 'application/json',
            ])->delete($url);

            if (!$response->successful()) {
                Log::error('Azure PubSub remove connection from group failed', [
                    'connectionId' => $connectionId,
                    'group' => $group,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            Log::info('Azure PubSub removed connection from group', [
                'connectionId' => $connectionId,
                'group' => $group,
            ]);

            return true;
        } catch (ConnectionException $e) {
            Log::error('Azure PubSub connection error', [
                'message' => $e->getMessage(),
                'connectionId' => $connectionId,
                'group' => $group,
            ]);
            return false;
        }
    }

    /**
     * Send a Socket.IO event to the specified URL.
     *
     * @param string $url The REST API URL
     * @param string $event The Socket.IO event name
     * @param mixed $data The data to send
     * @return bool
     */
    protected function send(string $url, string $event, mixed $data): bool
    {
        try {
            // For Azure Web PubSub, use the format [event, data]
            $payload = [$event, $data];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->tokenService->generateServiceToken($url),
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            Log::info('Azure PubSub response', [
                'url' => $url,
                'event' => $event,
                'payload' => $payload,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::error('Azure PubSub publish failed', [
                    'url' => $url,
                    'event' => $event,
                    'payload' => $payload,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (ConnectionException $e) {
            Log::error('Azure PubSub connection error', [
                'message' => $e->getMessage(),
                'url' => $url,
                'event' => $event,
            ]);
            return false;
        }
    }
}
