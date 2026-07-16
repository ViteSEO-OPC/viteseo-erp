<?php

namespace App\Modules\Projects\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Projects (PMS) module provider. Mirrors HrServiceProvider: it mounts the
 * module's routes, migrations, and broadcast channels so the pod owns its own
 * schema and endpoints while living inside the shared platform.
 */
class ProjectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The module's route file declares its own `v1/projects` prefix +
        // `auth:sanctum`, so here we only add the shared `api` prefix/middleware
        // to produce URLs like /api/v1/projects/workspaces.
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__ . '/../Routes/api.php');

        // Auto-load the pm_* migrations from inside the module.
        $this->loadMigrationsFrom(__DIR__ . '/../Migrations');

        // Private broadcast channels for real-time collaboration (Reverb).
        // The channel authorizers are inert until a broadcaster is configured
        // at deploy — see config/reverb.php + .env REVERB_* keys.
        require __DIR__ . '/../Routes/channels.php';
    }
}
