<?php

namespace App\Modules\Hr\Providers;

use App\Modules\Hr\Console\Commands\GenerateManagementAttendance;
use App\Modules\Hr\Console\Commands\RunLeaveAccrual;
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
        // A baseline rate limit on every HR route.
        Route::prefix('api/v1/hr')
            ->middleware(['api', 'throttle:120,1,hr-api:', 'throttle:2000,60,hr-api-hourly:'])
            ->group(__DIR__ . '/../Routes/api.php');

        // Auto-load HR migrations from inside the module.
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        // Module Blade views (e.g. the payslip PDF template) under the `hr::` namespace.
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'hr');

        // Module console commands + their schedule live here rather than in the shared
        // routes/console.php, so HR owns its own jobs.
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateManagementAttendance::class, RunLeaveAccrual::class]);

            // Management is exempt from the time clock, so the platform writes their
            // rows instead — late enough in the Manila day that the shift is over.
            // The command covers the last 7 days, so a missed night self-heals.
            // NOTE: needs `php artisan schedule:run` on a system cron to fire.
            Schedule::command(GenerateManagementAttendance::class)
                ->dailyAt('23:55')
                ->timezone(config('app.timezone_local'))
                ->withoutOverlapping()
                ->onOneServer();

            // Leave accrual, carry-over and expiry, on the 1st of each month. Early, so a
            // balance is right before anyone files against it that day. The command is idempotent
            // (it recomputes rather than increments), so a double fire or a manual catch-up after a
            // missed month cannot over-credit — hence no "already ran" guard.
            Schedule::command(RunLeaveAccrual::class)
                ->monthlyOn(1, '00:30')
                ->timezone(config('app.timezone_local'))
                ->withoutOverlapping()
                ->onOneServer();
        }

        // Audit logins into the audit trail.
        Event::listen(Login::class, function (Login $event) {
            app(AuditService::class)->log('login', 'Auth', ($event->user->name ?? 'A user') . ' signed in', $event->user);
        });
    }
}
