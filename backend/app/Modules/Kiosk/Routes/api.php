<?php

declare(strict_types=1);

use App\Modules\Kiosk\Controllers\AccessController;
use App\Modules\Kiosk\Controllers\CatalogController;
use App\Modules\Kiosk\Controllers\SelectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Kiosk module API routes
|--------------------------------------------------------------------------
| Final URLs: /api/v1/kiosk/...  (the `api` prefix is added by the
| KioskServiceProvider). Internal lead-gen tool — gated by kiosk permissions
| inside each controller, NOT by an org entitlement (it's not a sold product).
| `set-current-org` pins the tenant so saved selections are org-scoped.
*/

Route::prefix('v1/kiosk')
    ->middleware(['auth:sanctum', 'set-current-org', 'kiosk-access'])
    ->group(function (): void {
        Route::get('catalog', [CatalogController::class, 'index']);

        // Saved selections (leads)
        Route::get('selections', [SelectionController::class, 'index']);
        Route::post('selections', [SelectionController::class, 'store']);
        Route::get('selections/{selection}', [SelectionController::class, 'show']);
        Route::delete('selections/{selection}', [SelectionController::class, 'destroy']);

        // Temp client access (1-day demo logins)
        Route::get('access', [AccessController::class, 'index']);
        Route::post('access', [AccessController::class, 'store']);
        Route::delete('access/{grant}', [AccessController::class, 'destroy']);
    });
