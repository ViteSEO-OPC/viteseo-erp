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
        //
        // `hr-api:` is the counter's own name, and it matters. An authenticated request is keyed on
        // `sha1($user->id)` with no route in it, so an unprefixed limiter here shares one counter
        // with every other unprefixed `throttle:` the user touches — which is precisely how this
        // 120/min limiter came to be enforcing the payroll run's 5/min against ordinary page loads.
        // See the note above the payroll routes.
        // TWO WINDOWS, not one. The 120/min alone bounds a burst but not a grind: sitting at 119
        // requests a minute is inside policy forever, which is ~171k requests a day against the
        // employee directory. The hourly ceiling is what makes patient scraping cost something.
        //
        // 2000/hour is ~33/min sustained — far above real use (a heavy HR session is a few hundred
        // requests an hour) and far below what a scraper needs. Separate counter name, for the same
        // reason as `hr-api:` below: an unprefixed limiter shares one bucket with every other
        // unprefixed `throttle:` the user touches.
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

            // FIN-034 — leave accrual, carry-over and expiry, on the 1st of each month. Early, so a
            // balance is right before anyone files against it that day. The command is idempotent
            // (it recomputes rather than increments), so a double fire or a manual catch-up after a
            // missed month cannot over-credit — hence no "already ran" guard.
            Schedule::command(RunLeaveAccrual::class)
                ->monthlyOn(1, '00:30')
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
