<?php

namespace App\Http\Controllers;

use App\Services\AzurePubSubPublisher;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Azure Web PubSub Event Handler
 *
 * Handles events from Azure Web PubSub Socket.IO:
 * - connect: When a new client connects
 * - connected: When connection is established
 * - disconnected: When a client disconnects
 * - userEvent: Custom events from clients (like 'join')
 */
class WebPubSubEventHandler extends Controller
{
    protected AzurePubSubPublisher $publisher;

    public function __construct(AzurePubSubPublisher $publisher)
    {
        $this->publisher = $publisher;
    }

    /**
     * Handle events from Azure Web PubSub.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        Log::info('WebPubSub event received', [
            'body' => $request->getContent(),
        ]);

        $events = $request->input('events', []);

        foreach ($events as $event) {
            $this->processEvent($event);
        }

        return response()->json();
    }

    /**
     * Process a single event from Azure Web PubSub.
     *
     * @param array $event
     * @return void
     */
    protected function processEvent(array $event): void
    {
        $eventType = $event['type'] ?? null;
        $eventName = $event['eventName'] ?? null;
        $data = $event['data'] ?? [];
        $connectionId = $data['connectionId'] ?? null;

        Log::info('Processing WebPubSub event', [
            'type' => $eventType,
            'eventName' => $eventName,
            'connectionId' => $connectionId,
        ]);

        switch ($eventType) {
            case 'connected':
            case 'connect':
                $this->handleConnect($connectionId, $data);
                break;

            case 'disconnected':
                $this->handleDisconnect($connectionId, $data);
                break;

            case 'userEvent':
                $this->handleUserEvent($eventName, $connectionId, $data);
                break;

            default:
                Log::info('Unhandled event type', ['type' => $eventType]);
        }
    }

    /**
     * Handle a new client connection.
     * Automatically add the connection to the default group.
     *
     * @param string|null $connectionId
     * @param array $data
     * @return void
     */
    protected function handleConnect(?string $connectionId, array $data): void
    {
        if (!$connectionId) {
            Log::warning('Connect event missing connectionId');
            return;
        }

        Log::info('Client connected', ['connectionId' => $connectionId]);

        // Automatically add to default group for broadcast messages
        $success = $this->publisher->addConnectionToGroup($connectionId, 'default');

        if ($success) {
            Log::info('Added connection to default group', [
                'connectionId' => $connectionId,
            ]);
        } else {
            Log::error('Failed to add connection to default group', [
                'connectionId' => $connectionId,
            ]);
        }
    }

    /**
     * Handle a client disconnection.
     *
     * @param string|null $connectionId
     * @param array $data
     * @return void
     */
    protected function handleDisconnect(?string $connectionId, array $data): void
    {
        if (!$connectionId) {
            return;
        }

        Log::info('Client disconnected', ['connectionId' => $connectionId]);

        // Optionally remove from groups (Azure does this automatically)
        $this->publisher->removeConnectionFromGroup($connectionId, 'default');
    }

    /**
     * Handle a user event (like 'join').
     *
     * @param string|null $eventName
     * @param string|null $connectionId
     * @param array $data
     * @return void
     */
    protected function handleUserEvent(?string $eventName, ?string $connectionId, array $data): void
    {
        if (!$eventName || !$connectionId) {
            Log::warning('UserEvent missing required fields', [
                'eventName' => $eventName,
                'connectionId' => $connectionId,
            ]);
            return;
        }

        Log::info('User event received', [
            'eventName' => $eventName,
            'connectionId' => $connectionId,
            'data' => $data,
        ]);

        switch ($eventName) {
            case 'join':
                $group = $data['group'] ?? 'default';
                $this->publisher->addConnectionToGroup($connectionId, $group);
                Log::info('Added connection to group', [
                    'connectionId' => $connectionId,
                    'group' => $group,
                ]);
                break;

            case 'leave':
                $group = $data['group'] ?? 'default';
                $this->publisher->removeConnectionFromGroup($connectionId, $group);
                Log::info('Removed connection from group', [
                    'connectionId' => $connectionId,
                    'group' => $group,
                ]);
                break;

            default:
                Log::info('Unhandled user event', ['eventName' => $eventName]);
        }
    }
}
