<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\PubSubController;
use App\Http\Controllers\WebPubSubEventHandler;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider and are assigned
| the "web" middleware group (includes session support).
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Authentication routes with session support
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout']);

// Authenticated user info
Route::get('/user', [AuthController::class, 'user'])->middleware('auth');

// PubSub negotiate endpoint - requires authentication
Route::get('/api/negotiate', [PubSubController::class, 'negotiate'])->middleware('auth');

// Send message endpoint - requires authentication
Route::post('/api/messages/send', [ChatController::class, 'sendMessage'])->middleware('auth');

// Get messages endpoint - requires authentication
Route::get('/api/messages', [ChatController::class, 'getMessages'])->middleware('auth');

// Azure Web PubSub event handler (called by Azure service)
Route::post('/api/webpubsub/events', [WebPubSubEventHandler::class, 'handle']);
