<?php

namespace App\Modules\Hr\Providers;

use App\Modules\Hr\Services\AuditService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Every HR route is automatically prefixed with /api/v1/hr
        Route::prefix('api/v1/hr')
            ->middleware('api')
            ->group(__DIR__ . '/../Routes/api.php');

        // Auto-load HR migrations from inside the module.
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        // Module Blade views (e.g. the payslip PDF template) under the `hr::` namespace.
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'hr');

        // Audit logins into the audit trail (Slice 25).
        Event::listen(Login::class, function (Login $event) {
            app(AuditService::class)->log('login', 'Auth', ($event->user->name ?? 'A user') . ' signed in', $event->user);
        });
    }
}
