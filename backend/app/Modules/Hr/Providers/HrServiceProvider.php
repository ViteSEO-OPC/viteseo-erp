<?php

namespace App\Modules\Hr\Providers;

use App\Modules\Hr\Console\Commands\GenerateManagementAttendance;
use App\Modules\Hr\Services\AuditService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
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

        // Module console commands + their schedule live here rather than in the shared
        // routes/console.php, so HR owns its own jobs.
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateManagementAttendance::class]);

            // Management is exempt from the time clock, so the platform writes their
            // rows instead — late enough in the Manila day that the shift is over.
            // The command covers the last 7 days, so a missed night self-heals.
            // NOTE: needs `php artisan schedule:run` on a system cron to fire.
            Schedule::command(GenerateManagementAttendance::class)
                ->dailyAt('23:55')
                ->timezone(config('app.timezone_local'))
                ->withoutOverlapping()
                ->onOneServer();
        }

        // Audit logins into the audit trail (Slice 25).
        Event::listen(Login::class, function (Login $event) {
            app(AuditService::class)->log('login', 'Auth', ($event->user->name ?? 'A user') . ' signed in', $event->user);
        });
    }
}
