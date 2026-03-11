# SimpleChat - Detailed Implementation Plan

## Table of Contents
1. [Project Overview](#project-overview)
2. [Technical Architecture](#technical-architecture)
3. [Database Schema](#database-schema)
4. [Backend Implementation](#backend-implementation)
5. [Frontend Implementation](#frontend-implementation)
6. [Azure Web PubSub Integration](#azure-web-pubsub-integration)
7. [Authentication Flow](#authentication-flow)
8. [Real-time Messaging Flow](#real-time-messaging-flow)
9. [Configuration Management](#configuration-management)
10. [Testing Strategy](#testing-strategy)
11. [Deployment Guide](#deployment-guide)
12. [Troubleshooting](#troubleshooting)

---

## 1. Project Overview

### 1.1 Purpose
SimpleChat is a real-time chat application demonstrating the integration of Laravel (PHP) backend with Next.js (React/TypeScript) frontend using Azure Web PubSub for WebSocket communication.

**IMPORTANT:** This application uses Azure Web PubSub in **Serverless** mode, which provides a simpler architecture without requiring an upstream event handler endpoint.

### 1.2 Key Features
- Real-time message broadcasting using WebSocket
- Session-based authentication with Laravel Sanctum
- Message persistence in MySQL database
- Polling fallback for message synchronization
- Responsive UI with Tailwind CSS 4
- Optimistic UI updates

### 1.3 Technology Stack

| Layer | Technology | Version | Purpose |
|-------|-----------|---------|---------|
| Backend Framework | Laravel | 12.0 | REST API, authentication |
| Database | MySQL | 8.0+ | Data persistence |
| Authentication | Laravel Sanctum | 4.3+ | Session-based auth |
| Real-time | Azure Web PubSub | Latest | WebSocket messaging |
| Frontend Framework | Next.js | 16.1.6 | React SSR/CSR |
| Language | TypeScript | 5.x | Type safety |
| Styling | Tailwind CSS | 4.x | Utility-first CSS |
| WebSocket Client | Socket.IO Client | 4.8.3 | Azure connection |

---

## 2. Technical Architecture

### 2.1 System Architecture Diagram (Serverless Mode)

```
┌─────────────────┐         ┌──────────────────┐         ┌─────────────────┐
│                 │         │                  │         │                 │
│  Next.js Front  │◄───────►│  Laravel Backend │◄───────►│  MySQL Database │
│  (Port 3001)    │ HTTP    │  (Port 8000)     │         │                 │
│                 │         │                  │         │                 │
└────────┬────────┘         └────────┬─────────┘         └─────────────────┘
         │                           │
         │                           │
         │ WebSocket (Socket.IO)      │ REST API (Broadcast)
         │                           │
         ▼                           ▼
┌─────────────────┐         ┌──────────────────┐
│                 │         │                  │
│ Azure Web PubSub│◄────────│  JWT Service     │
│  (Serverless)   │         │  Token + REST    │
│                 │         │                  │
└────────┬────────┘         └──────────────────┘
         │
         │
         ▼
┌─────────────────┐
│                 │
│  All Connected  │
│  Clients        │
│  (Auto-managed) │
│                 │
└─────────────────┘
```

**Key Difference in Serverless Mode:**
- No event handler endpoint needed
- Azure automatically manages connections
- Backend only uses REST API to broadcast messages
- Simpler architecture with fewer moving parts

### 2.2 Data Flow

#### Message Sending Flow:
1. User types message in frontend
2. Frontend sends POST to `/api/messages/send` with session cookie
3. Backend validates authentication
4. Backend stores message in database
5. Backend generates JWT service token
6. Backend broadcasts to Azure Web PubSub REST API
7. Azure pushes message to all connected WebSocket clients
8. Frontend receives message via Socket.IO event
9. Frontend updates UI (replaces optimistic update)

#### Authentication Flow:
1. User submits credentials to `/login`
2. Backend validates credentials
3. Backend creates session
4. Session cookie stored in browser
5. Subsequent requests include session cookie
6. Backend validates session for protected routes

---

## 3. Database Schema

### 3.1 Users Table (Laravel Default)
```sql
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email_verified_at TIMESTAMP NULL,
    remember_token VARCHAR(100),
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 Messages Table
```sql
CREATE TABLE messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    content VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_created_at (created_at),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at_user_id (created_at, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.3 Sessions Table (Laravel Sanctum)
```sql
CREATE TABLE sessions (
    id VARCHAR(255) PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload LONGTEXT NOT NULL,
    last_activity INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. Backend Implementation

### 4.1 Project Structure
```
backend/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       ├── AuthController.php
│   │       ├── ChatController.php
│   │       └── PubSubController.php
│   ├── Models/
│   │   ├── User.php (Laravel default)
│   │   └── Message.php
│   ├── Providers/
│   │   └── AppServiceProvider.php
│   └── Services/
│       ├── AzurePubSubConfig.php
│       ├── AzurePubSubTokenService.php
│       └── AzurePubSubPublisher.php
├── config/
│   ├── azure.php
│   ├── cors.php
│   └── sanctum.php
├── database/
│   └── migrations/
│       └── 2026_03_10_010000_create_messages_table.php
├── routes/
│   ├── api.php
│   └── web.php
└── bootstrap/
    └── app.php
```

**Note:** In Serverless mode, `WebPubSubEventHandler.php` is **not required** since Azure manages connections automatically without needing an upstream event handler.

### 4.2 Model Implementations

#### Message Model (`app/Models/Message.php`)
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'content',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the message.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Convert message to array for JSON responses.
     * Uses camelCase for frontend compatibility.
     */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'userId' => (string) $this->user_id,
            'userName' => $this->user->name,
            'content' => $this->content,
            'timestamp' => $this->created_at->toIso8601String(),
        ];
    }
}
```

### 4.3 Service Implementations

#### Azure Configuration Service (`app/Services/AzurePubSubConfig.php`)
```php
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
     * Parse Azure Web PubSub connection string.
     * Format: Endpoint=https://<name>.webpubsub.azure.com;AccessKey=<key>;Version=1.0;
     */
    protected function parseConnectionString(string $connectionString): void
    {
        $parts = explode(';', $connectionString);
        $config = [];

        foreach ($parts as $part) {
            if (empty($part)) continue;
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $config[trim($pair[0])] = trim($pair[1]);
            }
        }

        $this->endpoint = $config['Endpoint']
            ?? throw new InvalidArgumentException('Missing Endpoint in connection string.');
        $this->accessKey = $config['AccessKey']
            ?? throw new InvalidArgumentException('Missing AccessKey in connection string.');
        $this->version = $config['Version'] ?? '1.0';
    }

    public function getBaseUrl(): string
    {
        return rtrim($this->endpoint, '/');
    }

    /**
     * Get Socket.IO client URL for JWT audience claim.
     * Format: {endpoint}/clients/socketio/hubs/{hub}
     */
    public function getSocketIOClientUrl(): string
    {
        return sprintf('%s/clients/socketio/hubs/%s', $this->getBaseUrl(), $this->hub);
    }

    /**
     * Get REST API URL for publishing to hub.
     * Uses Socket.IO default group for broadcasting.
     */
    public function getRestApiUrl(): string
    {
        return sprintf('%s/api/hubs/%s/groups/default/:send', $this->getBaseUrl(), $this->hub);
    }

    public function getHubEndpoint(): string
    {
        return sprintf('%s/api/hubs/%s', $this->getBaseUrl(), $this->hub);
    }
}
```

#### JWT Token Service (`app/Services/AzurePubSubTokenService.php`)
```php
<?php

namespace App\Services;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class AzurePubSubTokenService
{
    protected AzurePubSubConfig $config;
    protected Configuration $jwtConfig;

    public function __construct(?AzurePubSubConfig $config = null)
    {
        $this->config = $config ?? new AzurePubSubConfig();
        $this->jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->config->accessKey)
        );
    }

    /**
     * Generate client access token for Socket.IO connection.
     *
     * @param string|null $userId User identifier for the connection
     * @param array<string> $roles Optional roles to assign
     * @param int|null $expirationMinutes Token expiration in minutes
     * @return array{endpoint: string, hub: string, token: string, expires: int}
     */
    public function generateClientToken(
        ?string $userId = null,
        array $roles = [],
        ?int $expirationMinutes = null
    ): array {
        $expirationMinutes = $expirationMinutes ?? config('azure.token_expiration', 60);
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify("+{$expirationMinutes} minutes");

        $builder = $this->jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->permittedFor($this->config->getSocketIOClientUrl());

        if ($userId !== null) {
            $builder = $builder->relatedTo($userId);
        }

        if (!empty($roles)) {
            $builder = $builder->withClaim('role', $roles);
        }

        $token = $builder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return [
            'endpoint' => $this->config->getBaseUrl(),
            'hub' => $this->config->hub,
            'token' => $token->toString(),
            'expires' => $expiresAt->getTimestamp(),
        ];
    }

    /**
     * Generate service access token for REST API operations.
     * Used for server-to-server communication.
     */
    public function generateServiceToken(?string $audience = null): string
    {
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+1 minutes');

        $audience = $audience ?? $this->config->getHubEndpoint();

        $token = $this->jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->permittedFor($audience)
            ->withClaim('role', ['webpubsub.sendToGroup'])
            ->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return $token->toString();
    }
}
```

#### Message Publisher Service (`app/Services/AzurePubSubPublisher.php`)
```php
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
     * Broadcast a Socket.IO event to all connected clients.
     * In Serverless mode, this broadcasts to all clients in the default group.
     *
     * @param string $event The Socket.IO event name
     * @param mixed $data The data to broadcast
     * @return bool True if successful or using fallback
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

            Log::info('Azure PubSub broadcast', [
                'event' => $event,
                'status' => $response->status(),
            ]);

            if (!$response->successful()) {
                Log::warning('Azure PubSub broadcast failed, using fallback', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return true; // Fallback: allow message sending
            }

            return true;
        } catch (ConnectionException $e) {
            Log::error('Azure PubSub connection error, using fallback', [
                'message' => $e->getMessage(),
            ]);
            return true; // Fallback: allow message sending
        }
    }

    /**
     * Send a message to a specific user (optional, for targeted messaging).
     * In Serverless mode, this can be used for direct user-to-user messages.
     */
    public function sendToUser(string $userId, string $event, mixed $data): bool
    {
        $url = sprintf(
            '%s/api/hubs/%s/users/%s/:send',
            $this->config->getBaseUrl(),
            $this->config->hub,
            $userId
        );

        try {
            $payload = [$event, $data];
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->tokenService->generateServiceToken($url),
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            return $response->successful();
        } catch (ConnectionException $e) {
            Log::error('Failed to send to user', [
                'userId' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Add a connection to a group (optional, advanced use only).
     *
     * NOTE: In Serverless mode, Azure automatically adds all connections
     * to the default group. These methods are only needed for advanced
     * scenarios like targeted messaging to specific groups.
     */
    public function addConnectionToGroup(string $connectionId, string $group): bool
    {
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

            return $response->successful();
        } catch (ConnectionException $e) {
            Log::error('Failed to add connection to group', [
                'connectionId' => $connectionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove a connection from a group (optional, advanced use only).
     *
     * NOTE: In Serverless mode, Azure automatically handles group management.
     * These methods are only needed for advanced scenarios.
     */
    public function removeConnectionFromGroup(string $connectionId, string $group): bool
    {
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

            return $response->successful();
        } catch (ConnectionException $e) {
            Log::error('Failed to remove connection from group', [
                'connectionId' => $connectionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
```

**Serverless Mode Notes:**
- The `broadcast()` method is the primary method used in this chat application
- `sendToUser()` is available for targeted/direct messaging
- `addConnectionToGroup()` and `removeConnectionFromGroup()` are optional since Azure manages connections automatically in Serverless mode
- All Socket.IO clients are automatically added to the "default" group

### 4.4 Controller Implementations

#### Chat Controller (`app/Http/Controllers/ChatController.php`)
```php
<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\AzurePubSubPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function sendMessage(Request $request, AzurePubSubPublisher $publisher)
    {
        try {
            $validated = $request->validate([
                'content' => 'required|string|max:1000',
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Store in database
            $dbMessage = Message::create([
                'user_id' => $user->id,
                'content' => $validated['content'],
            ]);

            $message = $dbMessage->toArray();

            // Broadcast to Azure Web PubSub
            $publisher->broadcast('message', $message);

            Log::info('Message sent', [
                'messageId' => $message['id'],
                'userId' => $user->id,
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

    public function getMessages(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $limit = $request->get('limit');
            $after = $request->get('after');

            $query = Message::with('user')
                ->orderBy('created_at', 'desc');

            if ($limit !== null) {
                $query->limit(min((int)$limit, 10000));
            }

            if ($after) {
                $afterDate = \Carbon\Carbon::parse($after);
                $query->where('created_at', '>', $afterDate);
            }

            $messages = $query->get()->reverse();

            return response()->json([
                'success' => true,
                'messages' => $messages->map(fn($m) => $m->toArray())->toArray(),
            ]);
        } catch (\Throwable $th) {
            Log::error('Error getting messages', [
                'error' => $th->getMessage(),
            ]);
            return response()->json(['error' => 'Failed to get messages'], 500);
        }
    }
}
```

#### Authentication Controller (`app/Http/Controllers/AuthController.php`)
```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['user' => Auth::user()]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function user(Request $request)
    {
        return $request->user();
    }
}
```

#### WebSocket Negotiation Controller (`app/Http/Controllers/PubSubController.php`)
```php
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

    public function negotiate(Request $request): JsonResponse
    {
        $user = $request->user();

        $tokenData = $this->tokenService->generateClientToken(
            userId: $user?->id,
            roles: [] // Add roles here if needed
        );

        return response()->json($tokenData);
    }
}
```

### 4.5 Service Provider (`app/Providers/AppServiceProvider.php`)
```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AzurePubSubConfig;
use App\Services\AzurePubSubTokenService;
use App\Services\AzurePubSubPublisher;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register Azure Web PubSub services as singletons
        $this->app->singleton(AzurePubSubConfig::class);
        $this->app->singleton(AzurePubSubTokenService::class);
        $this->app->singleton(AzurePubSubPublisher::class);
    }

    public function boot(): void
    {
        //
    }
}
```

### 4.6 Routes Configuration

#### Web Routes (`routes/web.php`)
```php
<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\PubSubController;
use Illuminate\Support\Facades\Route;

// Authentication routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout']);

// User info (protected)
Route::get('/user', [AuthController::class, 'user'])->middleware('auth');

// WebSocket negotiation (protected)
Route::get('/api/negotiate', [PubSubController::class, 'negotiate'])->middleware('auth');

// Chat endpoints (protected)
Route::post('/api/messages/send', [ChatController::class, 'sendMessage'])->middleware('auth');
Route::get('/api/messages', [ChatController::class, 'getMessages'])->middleware('auth');

// Note: In Serverless mode, NO event handler route is needed
// Azure automatically manages connections without upstream events
```

#### API Routes (`routes/api.php`)
```php
<?php

use App\Http\Controllers\PubSubController;
use Illuminate\Support\Facades\Route;

// Alternative API route for negotiation
Route::middleware(['auth', 'throttle:60,1'])
    ->get('/negotiate', [PubSubController::class, 'negotiate']);
```

### 4.7 Configuration Files

#### Bootstrap Application (`bootstrap/app.php`)
```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->validateCsrfTokens(except: [
            'login',
            'logout',
            'api/login',
            'api/logout',
            'api/messages/send',
            // Note: No 'api/webpubsub/events' needed in Serverless mode
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

#### Azure Configuration (`config/azure.php`)
```php
<?php

return [
    'connection_string' => env('AZURE_PUBSUB_CONNECTION_STRING'),
    'hub' => env('AZURE_PUBSUB_HUB', 'chat'),
    'token_expiration' => env('AZURE_PUBSUB_TOKEN_EXPIRATION', 60),
];
```

#### CORS Configuration (`config/cors.php`)
```php
<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'user', 'api/negotiate'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['http://localhost:3000', 'http://localhost:3001', 'http://127.0.0.1:3000', 'http://127.0.0.1:3001'],
    'allowed_headers' => ['*'],
    'supports_credentials' => true,
];
```

#### Sanctum Configuration (`config/sanctum.php`)
```php
<?php

return [
    'stateful' => explode(',', env(
        'SANCTUM_STATEFUL_DOMAINS',
        'localhost,localhost:3000,localhost:3001,127.0.0.1:3000,127.0.0.1:3001'
    )),
    // ... other sanctum config
];
```

---

## 5. Frontend Implementation

### 5.1 Project Structure
```
frontend/
├── app/
│   ├── layout.tsx
│   ├── page.tsx
│   └── globals.css
├── components/
│   ├── ChatWindow.tsx
│   └── LoginForm.tsx
├── hooks/
│   ├── useAuth.ts
│   └── useSocket.ts
├── lib/
│   └── pubsub/
│       ├── negotiate.ts
│       └── socketManager.ts
├── .env.local
├── next.config.ts
├── package.json
└── tsconfig.json
```

### 5.2 Package Configuration (`package.json`)
```json
{
  "name": "frontend",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "dev": "next dev",
    "build": "next build",
    "start": "next start",
    "lint": "eslint"
  },
  "dependencies": {
    "next": "16.1.6",
    "react": "19.2.3",
    "react-dom": "19.2.3",
    "socket.io-client": "^4.8.3"
  },
  "devDependencies": {
    "@tailwindcss/postcss": "^4",
    "@types/node": "^20",
    "@types/react": "^19",
    "@types/react-dom": "^19",
    "eslint": "^9",
    "eslint-config-next": "16.1.6",
    "tailwindcss": "^4",
    "typescript": "^5"
  }
}
```

### 5.3 Environment Variables (`.env.local`)
```env
# Backend API URL
NEXT_PUBLIC_API_URL=http://localhost:8000
```

### 5.4 Service Layer

#### Token Negotiation (`lib/pubsub/negotiate.ts`)
```typescript
export interface NegotiateResponse {
  endpoint: string;
  hub: string;
  token: string;
  expires: number;
}

const API_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

/**
 * Fetch WebSocket credentials from the backend.
 * User must be authenticated via session cookie.
 */
export async function negotiate(): Promise<NegotiateResponse> {
  const response = await fetch(`${API_URL}/api/negotiate`, {
    method: 'GET',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
  });

  if (!response.ok) {
    if (response.status === 401) {
      throw new Error('Authentication required. Please log in.');
    }
    throw new Error(`Negotiation failed: ${response.status} ${response.statusText}`);
  }

  return response.json();
}
```

#### Socket Manager (`lib/pubsub/socketManager.ts`)
```typescript
import { io, Socket } from 'socket.io-client';
import { negotiate, NegotiateResponse } from './negotiate';

class SocketManager {
  private socket: Socket | null = null;
  private credentials: NegotiateResponse | null = null;
  private connecting = false;
  private reconnectAttempts = 0;
  private maxReconnectAttempts = 5;

  async getSocket(): Promise<Socket> {
    if (this.socket?.connected) {
      return this.socket;
    }

    if (this.connecting) {
      return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error('Connection timeout')), 10000);
        const checkInterval = setInterval(() => {
          if (this.socket?.connected) {
            clearTimeout(timeout);
            clearInterval(checkInterval);
            resolve(this.socket!);
          } else if (!this.connecting) {
            clearTimeout(timeout);
            clearInterval(checkInterval);
            reject(new Error('Connection failed'));
          }
        }, 100);
      });
    }

    return this.connect();
  }

  private async connect(): Promise<Socket> {
    this.connecting = true;

    try {
      this.credentials = await negotiate();

      this.socket = io(this.credentials.endpoint, {
        path: `/clients/socketio/hubs/${this.credentials.hub}`,
        query: { access_token: this.credentials.token },
        transports: ['websocket'], // MUST use websocket for Azure
        reconnection: true,
        reconnectionAttempts: this.maxReconnectAttempts,
        reconnectionDelay: 2000,
      });

      // Token refresh on reconnection
      this.socket.on('reconnect_attempt', async () => {
        if (this.credentials && Date.now() >= this.credentials.expires * 1000) {
          try {
            this.credentials = await negotiate();
            if (this.socket) {
              this.socket.io.opts.query = { access_token: this.credentials.token };
            }
          } catch (error) {
            console.error('Failed to refresh token:', error);
          }
        }
      });

      this.socket.on('connect', () => {
        this.reconnectAttempts = 0;
        this.connecting = false;
      });

      this.socket.on('connect_error', (error) => {
        this.reconnectAttempts++;
        this.connecting = false;
      });

      return new Promise((resolve, reject) => {
        this.socket!.on('connect', () => resolve(this.socket!));
        this.socket!.on('connect_error', (error) => {
          this.connecting = false;
          reject(error);
        });
      });
    } catch (error) {
      this.connecting = false;
      throw error;
    }
  }

  disconnect(): void {
    if (this.socket) {
      this.socket.disconnect();
      this.socket = null;
      this.credentials = null;
    }
  }

  isConnected(): boolean {
    return this.socket?.connected ?? false;
  }
}

export const socketManager = new SocketManager();
```

### 5.5 React Hooks

#### Authentication Hook (`hooks/useAuth.ts`)
```typescript
'use client';

import { useState, useEffect, useCallback } from 'react';

interface User {
  id: number;
  name: string;
  email: string;
}

interface UseAuthReturn {
  user: User | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  error: string | null;
}

const API_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

export function useAuth(): UseAuthReturn {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchUser();
  }, []);

  const fetchUser = async () => {
    try {
      const response = await fetch(`${API_URL}/user`, {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });

      if (response.ok) {
        const userData = await response.json();
        setUser(userData);
      } else {
        setUser(null);
      }
    } catch {
      setUser(null);
    } finally {
      setIsLoading(false);
    }
  };

  const login = useCallback(async (email: string, password: string) => {
    setError(null);
    setIsLoading(true);

    try {
      // Get CSRF token first
      await fetch(`${API_URL}/sanctum/csrf-cookie`, {
        credentials: 'include',
      });

      // Login
      const response = await fetch(`${API_URL}/login`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ email, password }),
      });

      if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.message || data.error || 'Login failed');
      }

      await fetchUser();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed');
      throw err;
    } finally {
      setIsLoading(false);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await fetch(`${API_URL}/logout`, {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });
    } finally {
      setUser(null);
    }
  }, []);

  return {
    user,
    isLoading,
    isAuthenticated: !!user,
    login,
    logout,
    error,
  };
}
```

#### WebSocket Hook (`hooks/useSocket.ts`)
```typescript
'use client';

import { useEffect, useState, useCallback, useRef } from 'react';
import { Socket } from 'socket.io-client';
import { socketManager } from '@/lib/pubsub/socketManager';

interface UseSocketReturn {
  socket: Socket | null;
  isConnected: boolean;
  isLoading: boolean;
  error: Error | null;
  connect: () => Promise<void>;
  disconnect: () => void;
}

export function useSocket(autoConnect = true): UseSocketReturn {
  const [socket, setSocket] = useState<Socket | null>(null);
  const [isConnected, setIsConnected] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const retryCountRef = useRef(0);
  const maxRetries = 3;

  const connect = useCallback(async () => {
    setIsLoading(true);
    setError(null);

    if (retryCountRef.current >= maxRetries) {
      retryCountRef.current = 0;
    }

    let lastError: Error | null = null;

    while (retryCountRef.current <= maxRetries) {
      try {
        const connectedSocket = await socketManager.getSocket();
        setSocket(connectedSocket);
        setIsConnected(true);
        retryCountRef.current = 0;
        setIsLoading(false);
        return;
      } catch (err) {
        lastError = err instanceof Error ? err : new Error('Connection failed');
        retryCountRef.current++;

        if (retryCountRef.current <= maxRetries) {
          await new Promise(resolve => setTimeout(resolve, 1000));
        }
      }
    }

    setError(lastError);
    setIsConnected(false);
    setIsLoading(false);
  }, []);

  const disconnect = useCallback(() => {
    socketManager.disconnect();
    setSocket(null);
    setIsConnected(false);
  }, []);

  useEffect(() => {
    if (autoConnect) {
      connect();
    }

    const handleConnect = () => setIsConnected(true);
    const handleDisconnect = () => setIsConnected(false);

    if (socket) {
      socket.on('connect', handleConnect);
      socket.on('disconnect', handleDisconnect);
    }

    return () => {
      if (socket) {
        socket.off('connect', handleConnect);
        socket.off('disconnect', handleDisconnect);
      }
    };
  }, [autoConnect, connect, socket]);

  return {
    socket,
    isConnected,
    isLoading,
    error,
    connect,
    disconnect,
  };
}
```

### 5.6 Components

#### Login Form (`components/LoginForm.tsx`)
```typescript
'use client';

import { useState } from 'react';

interface LoginFormProps {
  onLogin: (email: string, password: string) => Promise<void>;
  error: string | null;
  isLoading: boolean;
}

export function LoginForm({ onLogin, error, isLoading }: LoginFormProps) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    await onLogin(email, password);
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-100">
      <div className="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <h1 className="text-2xl font-bold mb-6 text-center">Login to SimpleChat</h1>

        <form onSubmit={handleSubmit} className="space-y-4">
          {error && (
            <div className="p-3 bg-red-100 text-red-700 rounded-lg text-sm">
              {error}
            </div>
          )}

          <div>
            <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-1">
              Email
            </label>
            <input
              id="email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              className="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="chat@example.com"
            />
          </div>

          <div>
            <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-1">
              Password
            </label>
            <input
              id="password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              className="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="password123"
            />
          </div>

          <button
            type="submit"
            disabled={isLoading}
            className="w-full py-2 px-4 bg-blue-500 text-white rounded-lg hover:bg-blue-600 disabled:opacity-50"
          >
            {isLoading ? 'Logging in...' : 'Login'}
          </button>
        </form>
      </div>
    </div>
  );
}
```

#### Chat Window (`components/ChatWindow.tsx`)
```typescript
'use client';

import { useEffect, useState, useCallback, useRef } from 'react';
import { useSocket } from '@/hooks/useSocket';

interface Message {
  id: string;
  userId: string;
  userName?: string;
  content: string;
  timestamp: Date;
}

interface ChatWindowProps {
  userId: string;
}

const API_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

export function ChatWindow({ userId }: ChatWindowProps) {
  const { socket, isConnected } = useSocket(false);
  const [messages, setMessages] = useState<Message[]>([]);
  const [inputValue, setInputValue] = useState('');
  const messagesEndRef = useRef<HTMLDivElement>(null);

  // Auto-scroll
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  // Connect on mount
  useEffect(() => {
    const connectAsync = async () => {
      try {
        await socket?.connect();
      } catch (error) {
        console.error('Socket connection failed:', error);
      }
    };
    connectAsync();
  }, [socket]);

  // Load initial messages
  useEffect(() => {
    const fetchMessages = async () => {
      const response = await fetch(`${API_URL}/api/messages`, {
        credentials: 'include',
      });
      if (response.ok) {
        const data = await response.json();
        const sorted = data.messages
          .map((msg: Message) => ({ ...msg, timestamp: new Date(msg.timestamp) }))
          .sort((a: Message, b: Message) =>
            new Date(a.timestamp).getTime() - new Date(b.timestamp).getTime()
          );
        setMessages(sorted);
      }
    };
    fetchMessages();
  }, []);

  // Listen for new messages
  useEffect(() => {
    if (!socket) return;

    const handleMessage = (data: Message) => {
      setMessages((prev) => {
        if (prev.some((msg) => msg.id === data.id)) return prev;
        return [...prev, { ...data, timestamp: new Date(data.timestamp) }];
      });
    };

    socket.on('message', handleMessage);
    return () => socket.off('message', handleMessage);
  }, [socket]);

  // Polling fallback
  const lastMessageRef = useRef<Message | null>(null);
  useEffect(() => {
    if (messages.length > 0) {
      lastMessageRef.current = messages[messages.length - 1];
    }
  }, [messages]);

  useEffect(() => {
    const pollInterval = 2000;

    const pollForNewMessages = async () => {
      const lastMsg = lastMessageRef.current;
      if (!lastMsg) return;

      const afterTimestamp = new Date(lastMsg.timestamp).toISOString();
      try {
        const response = await fetch(
          `${API_URL}/api/messages?after=${encodeURIComponent(afterTimestamp)}`,
          { credentials: 'include' }
        );
        if (response.ok) {
          const data = await response.json();
          const newMessages = Array.isArray(data.messages) ? data.messages : [];
          if (newMessages.length > 0) {
            setMessages((prev) => {
              const existingIds = new Set(prev.map((msg) => msg.id));
              const unique = newMessages.filter((msg: Message) => !existingIds.has(msg.id));
              return [...prev, ...unique.map((msg: Message) => ({ ...msg, timestamp: new Date(msg.timestamp) }))];
            });
          }
        }
      } catch (error) {
        console.error('Polling error:', error);
      }
    };

    const intervalId = setInterval(pollForNewMessages, pollInterval);
    return () => clearInterval(intervalId);
  }, []);

  const sendMessage = useCallback(async () => {
    if (!inputValue.trim()) return;

    const tempId = `temp-${crypto.randomUUID()}`;
    const content = inputValue.trim();

    // Optimistic update
    const tempMessage: Message = {
      id: tempId,
      userId: userId,
      userName: 'You',
      content: content,
      timestamp: new Date(),
    };
    setMessages((prev) => [...prev, tempMessage]);
    setInputValue('');

    try {
      const response = await fetch(`${API_URL}/api/messages/send`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ content }),
      });

      if (!response.ok) {
        setMessages((prev) => prev.filter((msg) => msg.id !== tempId));
        setInputValue(content);
      }
    } catch (error) {
      setMessages((prev) => prev.filter((msg) => msg.id !== tempId));
      setInputValue(content);
    }
  }, [inputValue, userId]);

  return (
    <div className="flex flex-col h-[600px] max-w-2xl mx-auto border rounded-lg bg-white shadow-lg">
      {/* Header */}
      <div className="flex items-center justify-between p-4 bg-gray-100 border-b">
        <h2 className="text-lg font-semibold">Chat Room</h2>
        <div className="flex items-center gap-2">
          <span className={`w-3 h-3 rounded-full ${isConnected ? 'bg-green-500' : 'bg-gray-400'}`} />
          <span className="text-sm text-gray-600">{isConnected ? 'Connected' : 'Disconnected'}</span>
        </div>
      </div>

      {/* Messages */}
      <div className="flex-1 overflow-y-auto p-4 space-y-4">
        {messages.map((msg) => (
          <div key={msg.id} className={`flex ${msg.userId === userId ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[70%] px-4 py-2 rounded-lg ${msg.userId === userId ? 'bg-blue-500 text-white' : 'bg-gray-200'}`}>
              <p className="text-xs opacity-75 mb-1">{msg.userId === userId ? 'You' : msg.userName}</p>
              <p>{msg.content}</p>
            </div>
          </div>
        ))}
        <div ref={messagesEndRef} />
      </div>

      {/* Input */}
      <div className="p-4 border-t bg-gray-50">
        <div className="flex gap-2">
          <input
            type="text"
            value={inputValue}
            onChange={(e) => setInputValue(e.target.value)}
            onKeyPress={(e) => e.key === 'Enter' && !e.shiftKey && sendMessage()}
            disabled={!isConnected}
            className="flex-1 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            placeholder="Type a message..."
          />
          <button
            onClick={sendMessage}
            disabled={!isConnected || !inputValue.trim()}
            className="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 disabled:opacity-50"
          >
            Send
          </button>
        </div>
      </div>
    </div>
  );
}
```

### 5.7 Main Page (`app/page.tsx`)
```typescript
'use client';

import { useAuth } from '@/hooks/useAuth';
import { ChatWindow } from '@/components/ChatWindow';
import { LoginForm } from '@/components/LoginForm';

export default function Home() {
  const { user, isLoading: authLoading, isAuthenticated, login, logout } = useAuth();

  if (authLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="text-xl">Loading...</div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <LoginForm onLogin={login} error={null} isLoading={authLoading} />;
  }

  return (
    <div className="min-h-screen bg-gray-100 py-8 px-4">
      <main className="container mx-auto">
        <div className="flex justify-between items-center mb-8">
          <h1 className="text-3xl font-bold">SimpleChat</h1>
          <div className="flex items-center gap-4">
            <span className="text-gray-600">Welcome, {user?.name}</span>
            <button
              onClick={logout}
              className="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600"
            >
              Logout
            </button>
          </div>
        </div>
        <ChatWindow userId={user?.id?.toString() || 'unknown'} />
      </main>
    </div>
  );
}
```

### 5.8 Layout (`app/layout.tsx`)
```typescript
import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "SimpleChat - Real-time Chat Application",
  description: "A real-time chat application built with Laravel and Next.js using Azure Web PubSub",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en">
      <body className={`${geistSans.variable} ${geistMono.variable} antialiased`}>
        {children}
      </body>
    </html>
  );
}
```

---

## 6. Azure Web PubSub Integration

### 6.1 Azure Web PubSub Mode: Serverless

**Important:** This application uses Azure Web PubSub in **Serverless** mode, not the default Socket.IO mode with event handlers.

#### Key Differences: Serverless vs. Default Mode

| Feature | Serverless Mode (This App) | Default Mode |
|---------|---------------------------|--------------|
| Event Handler | Not required | Required upstream server |
| Connection Management | Automatic via Azure | Handled by backend |
| Setup Complexity | Simple | Complex |
| Backend Events | None needed | connect/disconnected/userEvent |
| REST API Broadcasting | Direct to groups | Via event handler |

#### Why Serverless Mode?

Serverless mode is ideal for this chat application because:
- **Simpler architecture** - No need to manage WebSocket connection lifecycle
- **No event handler endpoint** - Backend doesn't need to be publicly accessible for Azure callbacks
- **Easier deployment** - No need to configure event handler URLs or handle Azure webhook events
- **Sufficient for chat** - Broadcasting to all connected clients works without connection tracking

### 6.2 Azure Portal Configuration

1. **Create Azure Web PubSub Resource**
   - Navigate to Azure Portal
   - Search for "Web PubSub"
   - Click "Create"
   - Select pricing tier (Free tier for development)
   - Choose resource name and region
   - Click "Review + Create"

2. **No Additional Configuration Required**
   - Serverless mode requires **no event handler setup**
   - No need to configure "Settings" → "Event Handlers"
   - No need to set up upstream server URLs

3. **Get Connection String**
   - Go to your Web PubSub resource
   - Navigate to "Settings" → "Keys"
   - Copy the connection string
   - Format: `Endpoint=https://<name>.webpubsub.azure.com;AccessKey=<key>;Version=1.0;`

4. **Note the Hub Name**
   - The hub name is configured in your application code
   - Default hub name for this app: `chat`
   - Hub names must match between backend and frontend

### 6.3 JWT Token Structure

#### Client Token (for WebSocket Connection)

In Serverless mode, client tokens need minimal claims:

```json
{
  "header": {
    "typ": "JWT",
    "alg": "HS256"
  },
  "payload": {
    "aud": "https://<resource>.webpubsub.azure.com/clients/socketio/hubs/chat",
    "iat": 1741327531,
    "exp": 1741331131,
    "sub": "3"
  }
}
```

**Required Claims:**
- `aud` (Audience): Must be the Socket.IO client URL format
- `iat` (Issued At): Token issuance timestamp
- `exp` (Expiration): Token expiration timestamp
- `sub` (Subject): User ID (optional but recommended)

**Optional Claims for Serverless Mode:**
- `role`: Not typically needed in serverless mode since connections are auto-managed

#### Service Token (for REST API Broadcasting)

Service tokens are used by the backend to broadcast messages via the REST API:

```json
{
  "header": {
    "typ": "JWT",
    "alg": "HS256"
  },
  "payload": {
    "aud": "https://<resource>.webpubsub.azure.com/api/hubs/chat",
    "iat": 1741327531,
    "exp": 1741327591,
    "role": ["webpubsub.sendToGroup"]
  }
}
```

**Required Claims:**
- `aud` (Audience): The REST API endpoint URL
- `iat` (Issued At): Token issuance timestamp
- `exp` (Expiration): Token expiration timestamp (typically short-lived, ~1 minute)
- `role`: Must include `webpubsub.sendToGroup` for broadcasting

### 6.4 Socket.IO Connection URL (Serverless Mode)
```
{endpoint}/clients/socketio/hubs/{hub}
?access_token={jwt_token}

Example:
https://qaautoallies.webpubsub.azure.com/clients/socketio/hubs/chat?access_token=eyJ0eXAiOiJKV1QiLCJhbGc...
```

### 6.5 Serverless Mode vs Default Mode Comparison

| Feature | Serverless Mode (This App) | Default Mode |
|---------|---------------------------|--------------|
| **Event Handler** | Not required | Required upstream server |
| **Setup Complexity** | Simple - just connection string | Complex - need webhook endpoint |
| **Connection Management** | Automatic via Azure | Handled by backend events |
| **Backend Files Needed** | No event handler controller | WebPubSubEventHandler required |
| **Azure Portal Config** | None beyond resource creation | Event handler URL template needed |
| **Public Endpoint** | Not needed | Must expose event endpoint |
| **Group Management** | Auto-added to default group | Manual via event handler |
| **Firewall Rules** | Outbound HTTPS only | Inbound for Azure callbacks |
| **Ideal For** | Chat, notifications, broadcasts | Advanced connection control |

### 6.6 How Serverless Mode Works in This App

1. **Client Connection Flow:**
   - Frontend requests credentials from `/api/negotiate`
   - Backend generates JWT with client-specific claims
   - Frontend connects directly to Azure with the JWT token
   - Azure automatically adds connection to "default" group
   - No backend event handler involved

2. **Message Broadcasting Flow:**
   - Frontend sends message via HTTP POST to `/api/messages/send`
   - Backend stores message in database
   - Backend generates service JWT (1 minute expiration)
   - Backend calls Azure REST API: `POST /api/hubs/{hub}/groups/default/:send`
   - Azure broadcasts to all connections in "default" group
   - All clients receive the message via WebSocket

3. **No Event Handler Needed:**
   - Azure doesn't send connect/disconnect events to backend
   - Backend doesn't need to handle `connected`, `disconnected`, or `userEvent` events
   - Simpler architecture with fewer moving parts

---

## 7. Authentication Flow

### 7.1 Login Sequence Diagram
```
┌─────────┐                    ┌─────────┐                    ┌──────────────┐
│ Frontend│                    │ Backend │                    │   Database   │
└────┬────┘                    └────┬────┘                    └──────┬───────┘
     │                              │                                 │
     │ POST /sanctum/csrf-cookie    │                                 │
     │─────────────────────────────>│                                 │
     │ Set CSRF Cookie               │                                 │
     │<─────────────────────────────│                                 │
     │                              │                                 │
     │ POST /login                  │                                 │
     │ {email, password}            │                                 │
     │─────────────────────────────>│                                 │
     │                              │ Validate credentials             │
     │                              │────────────────────────────────>│
     │                              │ User found                      │
     │                              │<────────────────────────────────│
     │                              │ Create session                  │
     │                              │────────────────────────────────>│
     │                              │ Session created                 │
     │                              │<────────────────────────────────│
     │ Set session cookie            │                                 │
     │<─────────────────────────────│                                 │
     │                              │                                 │
```

### 7.2 WebSocket Negotiation Sequence
```
┌─────────┐                    ┌─────────┐                    ┌──────────────┐
│ Frontend│                    │ Backend │                    │    Azure     │
└────┬────┘                    └────┬────┘                    └──────┬───────┘
     │                              │                                 │
     │ GET /api/negotiate            │                                 │
     │ (with session cookie)         │                                 │
     │─────────────────────────────>│                                 │
     │                              │ Verify session                   │
     │                              │────────────────────────────────>│
     │                              │ Valid session                   │
     │                              │<────────────────────────────────│
     │                              │ Generate JWT token              │
     │                              │ {endpoint, hub, token, expires} │
     │                              │                                 │
     │ Return credentials            │                                 │
     │<─────────────────────────────│                                 │
     │                              │                                 │
     │ Socket.IO connect             │                                 │
     │───────────────────────────────────────────────────────────────>│
     │                              │                                 │
     │ Connected                     │                                 │
     │<──────────────────────────────────────────────────────────────│
```

---

## 8. Real-time Messaging Flow

### 8.1 Message Sending Flow
```
┌─────────┐    ┌─────────┐    ┌────────────┐    ┌──────────┐    ┌─────────┐
│ Frontend│    │ Backend │    │  Database  │    │   Azure  │    │ Clients │
└────┬────┘    └────┬────┘    └─────┬──────┘    └────┬─────┘    └────┬────┘
     │              │                │               │               │
     │ Optimistic   │                │               │               │
     │ UI update    │                │               │               │
     │              │                │               │               │
     │ POST /api/messages/send      │               │               │
     │ {content}    │                │               │               │
     │─────────────>│                │               │               │
     │              │ INSERT message │               │               │
     │              │───────────────>│               │               │
     │              │ Message saved  │               │               │
     │              │<───────────────│               │               │
     │              │                │               │               │
     │              │ Generate service token        │               │
     │              │ POST /api/hubs/chat/...       │               │
     │              │───────────────────────────────>│               │
     │              │                │               │ 202 Accepted  │
     │              │<───────────────────────────────│               │
     │              │                │               │               │
     │ 200 OK       │                │               │               │
     │<─────────────│                │               │               │
     │              │                │               │               │
     │              │                │               │ Broadcast     │
     │              │                │               │──────────────>│
     │              │                │               │               │
     │ WebSocket event               │               │               │
     │<──────────────────────────────────────────────────────────────│
     │ Replace optimistic message    │               │               │
```

### 8.2 Polling Fallback Flow
```
┌─────────┐    ┌─────────┐    ┌────────────┐
│ Frontend│    │ Backend │    │  Database  │
└────┬────┘    └────┬────┘    └─────┬──────┘
     │              │                │
     │ Every 2s     │                │
     │ GET /api/messages?after={timestamp}
     │─────────────>│                │
     │              │ SELECT * WHERE created_at > after
     │              │───────────────>│
     │              │ New messages   │
     │              │<───────────────│
     │  Messages    │                │
     │<─────────────│                │
     │ Merge unique │                │
     │              │                │
```

---

## 9. Configuration Management

### 9.1 Backend Environment Variables (`.env`)
```env
# Application
APP_NAME=Laravel
APP_ENV=local
APP_KEY=base64:your-app-key
APP_DEBUG=true
APP_URL=http://localhost

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=simplechat
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Session
SESSION_DRIVER=database

# Laravel Sanctum
SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:3001,127.0.0.1:3000,127.0.0.1:3001

# Azure Web PubSub
AZURE_PUBSUB_CONNECTION_STRING=Endpoint=https://your-resource.webpubsub.azure.com;AccessKey=your-key;Version=1.0;
AZURE_PUBSUB_HUB=chat
AZURE_PUBSUB_TOKEN_EXPIRATION=60
```

### 9.2 Frontend Environment Variables (`.env.local`)
```env
# Backend API URL
NEXT_PUBLIC_API_URL=http://localhost:8000
```

---

## 10. Testing Strategy

### 10.1 Backend Testing

#### Unit Tests (`tests/Unit/MessageTest.php`)
```php
<?php

namespace Tests\Unit;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $message = Message::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $message->user);
        $this->assertEquals($user->id, $message->user->id);
    }

    public function test_message_to_array_format(): void
    {
        $user = User::factory()->create(['name' => 'Test User']);
        $message = Message::factory()->create([
            'user_id' => $user->id,
            'content' => 'Test message'
        ]);

        $array = $message->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('userId', $array);
        $this->assertArrayHasKey('userName', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('timestamp', $array);
        $this->assertEquals('Test User', $array['userName']);
    }
}
```

#### Feature Tests (`tests/Feature/ChatTest.php`)
```php
<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_send_message(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJson('/api/messages/send', [
            'content' => 'Test message'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => [
                    'userId' => $user->id,
                    'content' => 'Test message'
                ]
            ]);

        $this->assertDatabaseHas('messages', [
            'user_id' => $user->id,
            'content' => 'Test message'
        ]);
    }

    public function test_authenticated_user_can_get_messages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Message::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/messages');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'messages' => [
                    '*' => ['id', 'userId', 'userName', 'content', 'timestamp']
                ]
            ]);
    }

    public function test_guest_cannot_send_message(): void
    {
        $response = $this->postJson('/api/messages/send', [
            'content' => 'Test message'
        ]);

        $response->assertStatus(401);
    }
}
```

### 10.2 Frontend Testing

#### Component Tests (`components/__tests__/LoginForm.test.tsx`)
```typescript
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { LoginForm } from '../LoginForm';

describe('LoginForm', () => {
  it('renders login form', () => {
    render(<LoginForm onLogin={jest.fn()} error={null} isLoading={false} />);

    expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /login/i })).toBeInTheDocument();
  });

  it('calls onLogin with credentials', async () => {
    const mockLogin = jest.fn().mockResolvedValue(undefined);
    render(<LoginForm onLogin={mockLogin} error={null} isLoading={false} />);

    fireEvent.change(screen.getByLabelText(/email/i), {
      target: { value: 'test@example.com' }
    });
    fireEvent.change(screen.getByLabelText(/password/i), {
      target: { value: 'password123' }
    });
    fireEvent.click(screen.getByRole('button', { name: /login/i }));

    await waitFor(() => {
      expect(mockLogin).toHaveBeenCalledWith('test@example.com', 'password123');
    });
  });

  it('displays error message', () => {
    render(
      <LoginForm
        onLogin={jest.fn()}
        error="Invalid credentials"
        isLoading={false}
      />
    );

    expect(screen.getByText('Invalid credentials')).toBeInTheDocument();
  });
});
```

### 10.3 Integration Testing

#### API Integration Test (`tests/integration/chat.spec.ts`)
```typescript
import { test, expect } from '@playwright/test';

test.describe('Chat Integration', () => {
  test.beforeEach(async ({ page }) => {
    // Login before each test
    await page.goto('http://localhost:3001');
    await page.fill('input[type="email"]', 'chat@example.com');
    await page.fill('input[type="password"]', 'password123');
    await page.click('button[type="submit"]');
    await page.waitForURL('http://localhost:3001/');
  });

  test('displays chat window', async ({ page }) => {
    await expect(page.locator('text=Chat Room')).toBeVisible();
    await expect(page.locator('text=Connected')).toBeVisible({ timeout: 10000 });
  });

  test('sends and receives messages', async ({ page }) => {
    const testMessage = `Test message ${Date.now()}`;

    // Open two browser contexts
    const context2 = await page.context().browser()?.newContext();
    const page2 = await context2?.newPage();

    if (!page2) throw new Error('Could not create second page');

    // Login second user
    await page2.goto('http://localhost:3001');
    await page2.fill('input[type="email"]', 'chat@example.com');
    await page2.fill('input[type="password"]', 'password123');
    await page2.click('button[type="submit"]');
    await page2.waitForURL('http://localhost:3001/');

    // Send message from first page
    await page.fill('input[placeholder="Type a message..."]', testMessage);
    await page.click('button:has-text("Send")');

    // Verify message appears on both pages
    await expect(page.locator(`text=${testMessage}`)).toBeVisible({ timeout: 5000 });
    await expect(page2.locator(`text=${testMessage}`)).toBeVisible({ timeout: 5000 });

    await context2?.close();
  });
});
```

---

## 11. Deployment Guide

### 11.1 Backend Deployment (Laravel)

#### Production Server Requirements
- PHP 8.2 or higher
- Composer
- MySQL 8.0 or higher
- Nginx or Apache
- SSL certificate

#### Deployment Steps
1. **Clone repository**
```bash
git clone <repository-url>
cd simpleChat/backend
```

2. **Install dependencies**
```bash
composer install --optimize-autoloader --no-dev
```

3. **Set environment**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Configure production variables**
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_HOST=production-db-host
DB_DATABASE=production_db_name
DB_USERNAME=production_db_user
DB_PASSWORD=production_db_password

AZURE_PUBSUB_CONNECTION_STRING=Endpoint=https://prod-resource.webpubsub.azure.com;...
```

5. **Run migrations**
```bash
php artisan migrate --force
```

6. **Optimize application**
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

7. **Configure web server (Nginx)**
```nginx
server {
    listen 80;
    listen [::]:80;
    server_name yourdomain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com;
    root /var/www/simpleChat/backend/public;

    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

**Serverless Mode Advantage:** No need to configure Azure event handler URLs or expose a publicly accessible event endpoint. The backend only needs to make outbound REST API calls to Azure, simplifying deployment and security.

### 11.2 Frontend Deployment (Next.js)

#### Build for Production
```bash
cd frontend
npm install
npm run build
```

#### Deployment Options

**Option 1: Vercel (Recommended)**
```bash
npm install -g vercel
vercel
```

**Option 2: Docker**
```dockerfile
# Dockerfile
FROM node:20-alpine AS base

FROM base AS deps
WORKDIR /app
COPY package*.json ./
RUN npm ci

FROM base AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npm run build

FROM base AS runner
WORKDIR /app
ENV NODE_ENV production
RUN addgroup --system --gid 1001 nodejs
RUN adduser --system --uid 1001 nextjs
COPY --from=builder /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static

USER nextjs
EXPOSE 3000
ENV PORT 3000
ENV HOSTNAME "0.0.0.0"

CMD ["node", "server.js"]
```

**Option 3: VPS with PM2**
```bash
npm run build
npm install -g pm2
pm2 start npm --name "simplechat" -- start
pm2 save
pm2 startup
```

### 11.3 Azure Web PubSub Production Setup

1. **Upgrade to Standard Tier (Optional for Production)**
   - Navigate to your Web PubSub resource
   - Go to "Scale" settings
   - Select "Standard" tier for production workloads
   - Set capacity units based on expected load
   - Free tier (S1) is sufficient for development and small apps

2. **Configure Custom Domain (Optional)**
   - Add custom domain for professional SSL certificates
   - Update connection string if using custom domain
   - Note: This is optional; default domain works fine

3. **Serverless Mode Configuration**
   - **NO event handler setup needed**
   - Simply copy the connection string to production `.env`
   - Backend makes outbound REST API calls only
   - No webhook endpoint configuration required

4. **Monitoring and Alerts**
   - Enable diagnostic logs
   - Set up metrics and alerts for:
     - Connection count
     - Message throughput
     - Error rate
   - Configure Log Analytics workspace if desired

**Serverless Mode Benefits:**
- Simpler setup - no event handler URLs to configure
- Better security - no public webhook endpoint needed
- Easier deployment - no firewall rules for incoming Azure requests

---

## 12. Troubleshooting

### 12.1 Common Issues

#### Issue: "Authentication required" when negotiating
**Cause**: Session not established or expired
**Solution**:
1. Ensure CSRF cookie is fetched before login
2. Verify session configuration in `.env`
3. Check `SANCTUM_STATEFUL_DOMAINS` includes frontend URL

#### Issue: WebSocket connection fails
**Cause**: Invalid JWT token, expired token, or incorrect hub configuration
**Solution**:
1. Verify Azure connection string is correct
2. Check token expiration (default 60 minutes)
3. Ensure hub name matches between backend and frontend
4. Verify JWT audience matches Socket.IO client URL format

#### Issue: Messages not broadcasting to other clients
**Cause**: Azure REST API call failing or incorrect group name
**Solution**:
1. Check Laravel logs for Azure response: `tail -f backend/storage/logs/laravel.log`
2. Verify service token audience matches REST API URL exactly
3. In Serverless mode, ensure you're using the "default" group
4. Test the REST API call manually with curl

#### Issue: Messages not appearing in real-time
**Cause**: WebSocket not connected or polling fallback not working
**Solution**:
1. Check browser console for Socket.IO connection status
2. Verify the frontend is receiving the WebSocket credentials
3. Check that `isConnected` status shows "Connected" in the UI
4. If WebSocket fails, polling should still work every 2 seconds

#### Issue: CORS errors
**Cause**: Frontend origin not allowed
**Solution**:
1. Add frontend URL to `config/cors.php`
2. Verify `SANCTUM_STATEFUL_DOMAINS`
3. Ensure `supports_credentials` is `true`

#### Issue: Polling not working
**Cause**: Timestamp format mismatch or missing messages
**Solution**:
1. Ensure timestamps are ISO 8601 format
2. Check timezone configuration in `config/app.php`
3. Verify the `after` parameter URL encoding

#### Issue: Azure Web PubSub "401 Unauthorized" on broadcast
**Cause**: Service token JWT audience mismatch
**Solution**:
1. The service token `aud` claim must match the exact REST API URL
2. Check `AzurePubSubConfig::getRestApiUrl()` returns correct format
3. Ensure the URL includes `/groups/default/:send` for broadcasting

#### Serverless Mode Specific Issues

**Important:** In Serverless mode, these issues are **NOT applicable:
- Event handler connection errors (no event handler needed)
- Upstream server timeout for events
- `connect`/`disconnect` event handling failures

**Common Serverless Mode Issues:**

#### Issue: "No clients receive messages"
**Cause**: Clients not in the default group
**Solution**:
1. In Serverless mode, all Socket.IO clients are automatically added to the "default" group
2. Verify your REST API call targets `/groups/default/:send`
3. Check that clients are using the correct hub name

#### Issue: Client connects but doesn't receive events
**Cause**: JWT token missing required claims
**Solution**:
1. Verify client token includes `aud` for Socket.IO client URL
2. Check that `sub` (user ID) is included if needed
3. Ensure token hasn't expired

### 12.2 Debug Commands

```bash
# Check Laravel logs
tail -f backend/storage/logs/laravel.log

# Check database connection
php artisan tinker
>>> DB::connection()->getPdo();

# Test Azure connection
php artisan tinker
>>> $config = new App\Services\AzurePubSubConfig();
>>> $config->getBaseUrl();

# Clear caches
php artisan config:clear
php artisan route:clear
php artisan cache:clear

# Restart services
pm2 restart simplechat
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx
```

---

## 13. Security Considerations

### 13.1 Backend Security
- Always use HTTPS in production
- Set `APP_DEBUG=false` in production
- Rotate Azure access keys regularly
- Implement rate limiting on API endpoints
- Validate and sanitize all user inputs
- Use prepared statements (Laravel Eloquent handles this)

### 13.2 Frontend Security
- Never store credentials in frontend code
- Use `credentials: 'include'` only over HTTPS
- Implement XSS protection (React handles this by default)
- Validate data from WebSocket messages

### 13.3 Azure Web PubSub Security
- Use separate connection strings for dev/prod
- Set appropriate token expiration times (client tokens: 60 min, service tokens: 1 min)
- Monitor for unusual connection patterns
- **Serverless mode advantage:** No publicly accessible event handler endpoint needed
- Keep connection strings in environment variables, never commit to git

---

## 14. Performance Optimization

### 14.1 Database Optimization
```sql
-- Add compound indexes for common queries
CREATE INDEX idx_messages_created_at_user_id ON messages(created_at, user_id);

-- Partition messages table by date for large datasets
-- (Implementation depends on MySQL version and requirements)
```

### 14.2 Backend Optimization
- Use Redis for session storage in production
- Implement queue for message broadcasting
- Enable HTTP caching headers for static assets
- Use Laravel Octane for improved performance

### 14.3 Frontend Optimization
- Implement virtual scrolling for large message lists
- Use React.memo for expensive components
- Implement message pagination
- Debounce search/filter operations

---

## Appendix A: File Reference

### Backend File Locations
| File | Path |
|------|------|
| Message Model | `app/Models/Message.php` |
| Chat Controller | `app/Http/Controllers/ChatController.php` |
| Auth Controller | `app/Http/Controllers/AuthController.php` |
| PubSub Controller | `app/Http/Controllers/PubSubController.php` |
| Azure Config | `app/Services/AzurePubSubConfig.php` |
| Token Service | `app/Services/AzurePubSubTokenService.php` |
| Publisher | `app/Services/AzurePubSubPublisher.php` |
| Web Routes | `routes/web.php` |
| API Routes | `routes/api.php` |
| Azure Config | `config/azure.php` |

**Note:** In Serverless mode, `WebPubSubEventHandler.php` is **not required** since Azure manages connections automatically.

### Frontend File Locations
| File | Path |
|------|------|
| Main Page | `app/page.tsx` |
| Layout | `app/layout.tsx` |
| Styles | `app/globals.css` |
| Chat Window | `components/ChatWindow.tsx` |
| Login Form | `components/LoginForm.tsx` |
| Auth Hook | `hooks/useAuth.ts` |
| Socket Hook | `hooks/useSocket.ts` |
| Negotiate | `lib/pubsub/negotiate.ts` |
| Socket Manager | `lib/pubsub/socketManager.ts` |
| Environment | `.env.local` |

---

## Appendix B: API Reference

### POST /login
Login user and create session.

**Request:**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response (200):**
```json
{
  "user": {
    "id": 1,
    "name": "User Name",
    "email": "user@example.com"
  }
}
```

### POST /logout
Logout user and destroy session.

**Response (200):**
```json
{
  "message": "Logged out"
}
```

### GET /user
Get current authenticated user.

**Response (200):**
```json
{
  "id": 1,
  "name": "User Name",
  "email": "user@example.com"
}
```

### GET /api/negotiate
Get WebSocket credentials.

**Response (200):**
```json
{
  "endpoint": "https://resource.webpubsub.azure.com",
  "hub": "chat",
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "expires": 1741331131
}
```

### GET /api/messages
Get message history.

**Query Parameters:**
- `limit` (optional): Maximum number of messages to return
- `after` (optional): ISO 8601 timestamp to filter messages after

**Response (200):**
```json
{
  "success": true,
  "messages": [
    {
      "id": "1",
      "userId": "1",
      "userName": "User Name",
      "content": "Hello world",
      "timestamp": "2026-03-10T12:00:00+00:00"
    }
  ]
}
```

### POST /api/messages/send
Send a new message.

**Request:**
```json
{
  "content": "Hello world"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": {
    "id": "1",
    "userId": "1",
    "userName": "User Name",
    "content": "Hello world",
    "timestamp": "2026-03-10T12:00:00+00:00"
  }
}
```

---

## Appendix C: Socket.IO Events

### Client → Server Events
None (all client communication via HTTP API)

### Server → Client Events

#### `message`
New message received.

```typescript
socket.on('message', (data: Message) => {
  console.log(data);
  // {
  //   id: "1",
  //   userId: "1",
  //   userName: "User Name",
  //   content: "Hello world",
  //   timestamp: "2026-03-10T12:00:00+00:00"
  // }
});
```

---

*Document Version: 1.0*
*Last Updated: 2025-03-10*
*Author: SimpleChat Development Team*
