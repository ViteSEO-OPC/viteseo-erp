<?php

namespace App\Modules\Crm\Providers;

use App\Models\User;
use App\Modules\Crm\Console\Commands\MailboxWatchCommand;
use App\Modules\Crm\Console\Commands\RecomputeLeadScoresCommand;
use App\Modules\Crm\Jobs\ReconcileMailboxJob;
use App\Modules\Crm\Jobs\RenewMailboxWatchJob;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Note;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Policies\EmailPolicy;
use App\Modules\Crm\Policies\LeadPolicy;
use App\Modules\Crm\Policies\NotePolicy;
use App\Modules\Crm\Policies\PlannerEventPolicy;
use App\Modules\Crm\Policies\UserPolicy;
use App\Modules\Crm\Services\Kpi\KpiConfig;
use App\Modules\Crm\Services\Mail\Contracts\EmailProvider;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Mail\Providers\GmailMailboxSync;
use App\Modules\Crm\Services\Mail\Providers\SmtpEmailProvider;
use App\Modules\Crm\Services\Ranking\RankingConfig;
use App\Modules\Crm\Services\Scoring\ScoringConfig;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * CRM (Zerem) module provider. Mirrors ProjectsServiceProvider: it mounts the
 * module's routes + migrations, registers its policies, and binds the Outreach
 * (email) providers and scoring/KPI/ranking config objects so the pod owns its
 * own schema, endpoints, and services while living inside the shared platform.
 */
class CrmServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private array $policies = [
        Lead::class => LeadPolicy::class,
        Note::class => NotePolicy::class,
        Email::class => EmailPolicy::class,
        PlannerEvent::class => PlannerEventPolicy::class,
        User::class => UserPolicy::class,
    ];

    public function register(): void
    {
        // Personal Lead Score parameters (weights/windows/caps) from config/scoring.php.
        $this->app->singleton(ScoringConfig::class, fn ($app): ScoringConfig => ScoringConfig::fromArray(
            is_array($config = $app['config']->get('scoring', [])) ? $config : [],
        ));

        // BD KPI Engine parameters (status sets + rolling window) from config/kpi.php.
        $this->app->singleton(KpiConfig::class, fn ($app): KpiConfig => KpiConfig::fromArray(
            is_array($config = $app['config']->get('kpi', [])) ? $config : [],
        ));

        // BD Ranking (Interim) parameters from config/ranking.php.
        $this->app->singleton(RankingConfig::class, fn ($app): RankingConfig => RankingConfig::fromArray(
            is_array($config = $app['config']->get('ranking', [])) ? $config : [],
        ));

        // Outbound email provider resolved from config('outreach.provider') (default smtp).
        $this->app->bind(EmailProvider::class, function (Application $app): EmailProvider {
            $key = (string) config('outreach.provider', 'smtp');
            $map = ['smtp' => SmtpEmailProvider::class];
            $class = $map[$key] ?? SmtpEmailProvider::class;

            return $app->make($class);
        });

        // Inbound mailbox sync provider (Gmail). The credentials/topic default to null when the
        // Google env vars are unset, so binding here never crashes on boot — the provider is only
        // exercised by the queued mailbox jobs, which require Gmail creds + a queue worker at deploy.
        $this->app->bind(MailboxSyncProvider::class, function (): MailboxSyncProvider {
            return new GmailMailboxSync([
                'credentials' => config('outreach.gmail.credentials'),
                'topic' => config('outreach.gmail.topic'),
                'scopes' => config('outreach.gmail.scopes', []),
            ]);
        });
    }

    public function boot(): void
    {
        // The module route file declares its own `v1/crm` prefix + `auth:sanctum`;
        // here we add the shared `api` prefix/middleware → /api/v1/crm/*.
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__ . '/../Routes/api.php');

        // Auto-load the crm_* migrations from inside the module.
        $this->loadMigrationsFrom(__DIR__ . '/../Migrations');

        // Register the module policies (models Lead/Note/Email/PlannerEvent + the shared User for
        // CRM team-account management). Observers auto-register via #[ObservedBy] attributes.
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Super Admin is the platform's all-access role (CEO). Several CRM policies
        // (UserPolicy/NotePolicy/EmailPolicy) were written to rely on this bypass rather than
        // enumerate super_admin in every branch. Returning null (not false) lets normal
        // policy/permission checks run for everyone else. Only ADDS access for super_admin.
        Gate::before(fn (User $user, string $ability): ?bool => $user->hasRole('super_admin') ? true : null);

        // Console commands + inbound-sync maintenance schedule. Registering the schedule is inert
        // until a scheduler/queue worker runs at deploy (and Gmail creds are configured).
        if ($this->app->runningInConsole()) {
            $this->commands([
                MailboxWatchCommand::class,
                RecomputeLeadScoresCommand::class,
            ]);
        }

        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);
            $schedule->job(new RenewMailboxWatchJob)->daily();
            $schedule->job(new ReconcileMailboxJob)->hourly();
        });
    }
}
