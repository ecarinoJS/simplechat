<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Azure Web PubSub Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Azure Web PubSub for Socket.IO real-time messaging.
    | The connection string should be stored in the .env file and contains
    | the endpoint, access key, and version information.
    |
    */

    'connection_string' => env('AZURE_PUBSUB_CONNECTION_STRING'),

    /*
    |--------------------------------------------------------------------------
    | Hub Name
    |--------------------------------------------------------------------------
    |
    | The hub name configured in Azure Web PubSub. This must match the hub
    | created in the Azure Portal. For Socket.IO, this is typically 'chat'
    | or your application name.
    |
    */

    'hub' => env('AZURE_PUBSUB_HUB', 'chat'),

    /*
    |--------------------------------------------------------------------------
    | Token Expiration (minutes)
    |--------------------------------------------------------------------------
    |
    | The number of minutes until the generated client tokens expire.
    | Clients will need to re-negotiate for a new token after expiration.
    |
    */

    'token_expiration' => env('AZURE_PUBSUB_TOKEN_EXPIRATION', 60),
];
