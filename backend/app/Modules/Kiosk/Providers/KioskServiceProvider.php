<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Kiosk module (internal website-configurator / lead-gen tool).
 * The route file declares its own `v1/kiosk` prefix + auth; here we only add the
 * shared `api` prefix/middleware, producing URLs like /api/v1/kiosk/catalog.
 */
class KioskServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__ . '/../Routes/api.php');

        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
