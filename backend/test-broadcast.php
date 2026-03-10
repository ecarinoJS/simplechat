#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::first();

echo "=== Testing Azure Web PubSub Broadcasting ===\n\n";

// Test broadcasting a message
$publisher = app(\App\Services\AzurePubSubPublisher::class);

$testMessage = [
    'id' => (string) \Illuminate\Support\Str::uuid(),
    'userId' => (string) $user->id,
    'userName' => $user->name,
    'content' => 'Test message from Azure Web PubSub test',
    'timestamp' => now()->toIso8601String(),
];

echo '=== Test Message ===' . PHP_EOL;
echo json_encode($testMessage, JSON_PRETTY_PRINT) . PHP_EOL;
echo PHP_EOL;

echo '=== Broadcasting to Azure Web PubSub ===' . PHP_EOL;
echo 'Endpoint: ' . config('azure.connection_string') . PHP_EOL;
echo 'Hub: ' . config('azure.hub', 'chat') . PHP_EOL;
echo PHP_EOL;

$result = $publisher->broadcast('message', $testMessage);

if ($result) {
    echo "✅ Message broadcasted successfully!\n";
    exit(0);
} else {
    echo "❌ Broadcasting failed!\n";
    exit(1);
}
