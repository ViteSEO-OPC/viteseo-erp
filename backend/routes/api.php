<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// This file is auto-mounted under the `api` prefix (see bootstrap/app.php),
// so the `v1` group below produces URLs like /api/v1/auth/login.
Route::prefix('v1')->group(function () {

    // Core (shared) auth — not HR-specific. Public login; the rest need a token.
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

});
