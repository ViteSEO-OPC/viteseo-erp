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
        // FIN-006 — a baseline rate limit on every HR route.
        //
        // The application had no throttling anywhere: `bootstrap/app.php` never calls
        // `throttleApi()`, and Laravel's `api` group only adds `throttle:` when that limiter is
        // registered (Foundation/Configuration/Middleware.php). So every endpoint accepted
        // unlimited requests.
        //
        // SCOPE: this covers `/api/v1/hr/*` ONLY, and is applied here rather than in
        // `bootstrap/app.php` deliberately — that file is shared with the CRM and Projects
        // modules, and rate-limiting their endpoints is not this pod's call to make.
        //
        // NOT COVERED, and it is the more valuable half: `POST /api/v1/auth/login` is a shared
        // route and still has no limit, so credential stuffing against it remains unbounded.
        // That fix belongs in `bootstrap/app.php` and needs the platform owner. Tracked as the
        // outstanding half of FIN-006 — do not read this limiter as closing it.
        //
        // 120/min is deliberately loose: an HR dashboard fans out a lot of reads on load, and a
        // limiter that trips during normal use gets removed rather than tuned. The expensive
        // write paths carry their own tighter limits in Routes/api.php.
        Route::prefix('api/v1/hr')
            ->middleware(['api', 'throttle:120,1'])
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
