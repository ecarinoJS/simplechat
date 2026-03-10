<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class LocalWebSocketService implements MessageComponentInterface
{
    protected $clients;
    protected $subscriptions;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->subscriptions = [];
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        Log::info("New WebSocket connection: {$conn->resourceId}");
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        // Handle WebSocket messages if needed
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        Log::info("WebSocket connection closed: {$conn->resourceId}");
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        Log::error("WebSocket error: " . $e->getMessage());
        $conn->close();
    }

    public function broadcast($event, $data)
    {
        $message = json_encode([
            'event' => $event,
            'data' => $data
        ]);

        foreach ($this->clients as $client) {
            try {
                $client->send($message);
                Log::info("Message sent to client: {$client->resourceId}");
            } catch (\Exception $e) {
                Log::error("Failed to send message to client: {$e->getMessage()}");
            }
        }

        return true;
    }

    public static function startServer()
    {
        $server = IoServer::factory(
            new HttpServer(
                new WsServer(
                    new LocalWebSocketService()
                )
            ),
            8080
        );

        Log::info("WebSocket server started on port 8080");
        $server->run();
    }
}
