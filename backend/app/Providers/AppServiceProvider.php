<?php

namespace App\Providers;

use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Events\JobProcessing;
use App\Support\CurrentOrg;
use App\Support\Mail\ModuleSenderResolver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-module outbound sender identities (config/mail.php `module_senders`).
        // Registered here rather than in a module provider because it is shared: CRM,
        // HR, and eventually PMS all resolve their From address through this one object.
        $this->app->singleton(
            ModuleSenderResolver::class,
            fn ($app): ModuleSenderResolver => new ModuleSenderResolver($app->make(Repository::class)),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * A JOB DOES NOT INHERIT THE PIN OF WHOEVER DISPATCHED IT.
         *
         * CurrentOrg's override is a static. On a queue worker that static outlives
         * the job: whatever tenant one job pinned is still pinned when the next job
         * starts, so a job that forgot to name its org silently ran as the previous
         * job's tenant -- and on a busy worker that is a different tenant every time,
         * which is the failure mode that never reproduces.
         *
         * Cleared before every job, so a job must say whose data it is working on.
         * RESTORED after -- not merely cleared -- because tests and any sync dispatch
         * run the job inside the caller's own request, and wiping the pin there would
         * break the caller that is still using it. Restoring is correct in both
         * worlds: on a worker there is nothing to restore to, and inline there is.
         */
        $pinned = [];

        Queue::before(function (JobProcessing $event) use (&$pinned): void {
            $pinned[] = CurrentOrg::snapshot();
            CurrentOrg::clear();
        });

        $restore = function () use (&$pinned): void {
            if ($pinned !== []) {
                CurrentOrg::restore(array_pop($pinned));
            }
        };

        Queue::after($restore);
        Queue::failing($restore);

        // The API has no web reset page — the emailed link lands on the Next.js
        // frontend, which posts the token back to /api/v1/auth/reset-password.
        ResetPassword::createUrlUsing(static function (object $notifiable, string $token): string {
            $base = rtrim((string) config('outreach.frontend_url'), '/');

            return $base.'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
