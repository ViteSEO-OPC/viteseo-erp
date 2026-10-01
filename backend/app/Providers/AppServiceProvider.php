<?php

namespace App\Providers;

use App\Support\MachineTokens;
use App\Models\User;
use App\Support\AccessLifecycle;
use Spatie\Permission\Events\PermissionAttached;
use Spatie\Permission\Events\PermissionDetached;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Events\JobProcessing;
use App\Modules\Hr\Services\InternAllowanceService;
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

        /*
         * Company → Intern Allowance is resolved PER ROW in places like LeaveRequestResource, and
         * every resolution was a fresh object whose per-request memo started cold — a version query
         * for each intern's leave row. One instance lets that memo do its job.
         *
         * Safe only because the memo and the opening-version guard are keyed by org: the container
         * outlives a single request in a queue worker, which is precisely where one instance does
         * serve several tenants in turn.
         */
        $this->app->singleton(InternAllowanceService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * migrate:fresh / refresh / reset / rollback and db:wipe refuse on a live system. They drop tables
         * (data, roles, logins) or roll schema back inexactly; none is ever part of a release — a release
         * is rolled back by restoring its pre-deploy dump (docs/release). Live hosts run
         * APP_ENV=production (the demo too). Laravel's own switch; local/testing are unaffected.
         */
        DB::prohibitDestructiveCommands($this->app->isProduction());

        /*
         * No token without an expiry.
         *
         * The guard no longer ages tokens by created_at (config/sanctum.php), so a
         * token's own expires_at is the only thing that ends it -- and NULL would
         * mean never. A token minted without saying lives thirty days; machine
         * tokens say ninety through MachineTokens::issue().
         */
        PersonalAccessToken::creating(function (PersonalAccessToken $token): void {
            $token->expires_at ??= now()->addDays(MachineTokens::UNSTATED_DAYS);
        });

        /*
         * A role or permission granted to or taken from a person: their open sessions
         * are judged again (AccessLifecycle). Spatie announces these only with
         * permission.events_enabled, switched on for this. A change to a ROLE's own
         * permissions is a deploy-time seeder operation, not a person's access
         * changing, and is not followed here.
         */
        Event::listen(
            [RoleAttached::class, RoleDetached::class, PermissionAttached::class, PermissionDetached::class],
            function (RoleAttached|RoleDetached|PermissionAttached|PermissionDetached $event): void {
                if ($event->model instanceof User) {
                    AccessLifecycle::schedule($event->model, 'roles');
                }
            },
        );

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
