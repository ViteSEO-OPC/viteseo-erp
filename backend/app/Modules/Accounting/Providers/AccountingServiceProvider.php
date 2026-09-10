<?php

namespace App\Modules\Accounting\Providers;

use App\Modules\Accounting\Console\Commands\GenerateRecurringInvoices;
use App\Modules\Accounting\Console\Commands\RecognizeRevenue;
use App\Modules\Accounting\Console\Commands\SeedAccountingDemo;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The route file declares its own `v1/accounting` prefix + `auth:sanctum`;
        // here we only add the shared `api` prefix/middleware, producing URLs like
        // /api/v1/accounting/accounts.
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__ . '/../Routes/api.php');

        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        // Registered here, not in app/Console/Commands, because Laravel only
        // auto-discovers that one directory -- and a module whose pieces live
        // outside itself is exactly how this module arrived with four tables and
        // two scheduled commands missing. routes/console.php schedules both.
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateRecurringInvoices::class,
                RecognizeRevenue::class,
                SeedAccountingDemo::class,
            ]);
        }
    }
}
