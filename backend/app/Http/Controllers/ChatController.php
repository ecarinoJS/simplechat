<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\AzurePubSubPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    /**
     * Handle sending a message to all connected clients.
     *
     * @param Request $request
     * @param AzurePubSubPublisher $publisher
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendMessage(Request $request, AzurePubSubPublisher $publisher)
    {
        try {
            // Validate the request
            $validated = $request->validate([
                'content' => 'required|string|max:1000',
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Store message in database
            $dbMessage = Message::create([
                'user_id' => $user->id,
                'content' => $validated['content'],
            ]);

            // Create message payload for broadcasting
            $message = $dbMessage->toArray();

            Log::info('Broadcasting message', [
                'event' => 'message',
                'userId' => $user->id,
                'userName' => $user->name,
                'data' => $message,
            ]);

            // Try to broadcast to Azure Web PubSub (with fallback)
            $publisher->broadcast('message', $message);

            Log::info('Message processed successfully', [
                'messageId' => $message['id'],
                'storedInDb' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $th) {
            Log::error('Error sending message', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to send message'], 500);
        }
    }

    /**
     * Get recent messages.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMessages(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $limit = min($request->get('limit', 50), 100);
            $after = $request->get('after');

            $query = Message::with('user')
                ->orderBy('created_at', 'desc')
                ->limit($limit);

            if ($after) {
                // Parse the ISO 8601 timestamp properly with Carbon
                $afterDate = \Carbon\Carbon::parse($after);
                $query->where('created_at', '>', $afterDate);
            }

            $messages = $query->get()->reverse();

            return response()->json([
                'success' => true,
                'messages' => $messages->map(fn($message) => $message->toArray())->toArray(),
            ]);
        } catch (\Throwable $th) {
            Log::error('Error getting messages', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to get messages'], 500);
        }
    }
}
