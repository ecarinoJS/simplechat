<?php

namespace App\Http\Controllers;

use App\Services\AzurePubSubTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PubSubController extends Controller
{
    protected AzurePubSubTokenService $tokenService;

    public function __construct(AzurePubSubTokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    /**
     * Handle the negotiate request to get a client access token.
     *
     * This endpoint is called by the frontend to obtain the credentials
     * needed to establish a Socket.IO connection to Azure Web PubSub.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function negotiate(Request $request): JsonResponse
    {
        $user = $request->user();

        // Generate token with the authenticated user's ID
        $tokenData = $this->tokenService->generateClientToken(
            userId: $user?->id,
            roles: $this->getUserRoles($user)
        );

        return response()->json($tokenData);
    }

    /**
     * Get the roles to assign to the user's connection.
     *
     * Override this method to implement custom role logic.
     *
     * @param mixed $user
     * @return array<string>
     */
    protected function getUserRoles(mixed $user): array
    {
        // Default: no special roles
        // Override to add roles like 'admin', 'moderator', etc.
        return [];
    }
}
