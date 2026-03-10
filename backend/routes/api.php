<?php

use App\Http\Controllers\PubSubController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider and are assigned
| the "api" middleware group. Authentication is handled via Sanctum.
|
*/

// PubSub negotiate endpoint - requires authentication
Route::middleware(['auth', 'throttle:60,1'])
    ->get('/negotiate', [PubSubController::class, 'negotiate']);
