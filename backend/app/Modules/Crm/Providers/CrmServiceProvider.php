<?php

namespace App\Modules\Crm\Providers;

use App\Models\User;
use App\Modules\Crm\Console\Commands\BackfillNichesCommand;
use App\Modules\Crm\Console\Commands\BackfillPlannerOwnershipCommand;
use App\Modules\Crm\Console\Commands\SyncMailboxesCommand;
use App\Modules\Crm\Console\Commands\N8nTokenCommand;
use App\Modules\Crm\Console\Commands\PruneReadNotifications;
use App\Modules\Crm\Console\Commands\ProvisionIntegrationAccountCommand;
use App\Modules\Crm\Console\Commands\PruneXlsImportsCommand;
use App\Modules\Crm\Console\Commands\RecomputeLeadScoresCommand;
use App\Modules\Crm\Events\DealWon;
use App\Modules\Crm\Events\FollowUpCompleted;
use App\Modules\Crm\Events\InboundReplyReceived;
use App\Modules\Crm\Events\LeadQualified;
use App\Modules\Crm\Events\OutboundEmailSent;
use App\Modules\Crm\Events\PlannerReminderDue;
use App\Modules\Crm\Events\ProposalSent;
use App\Modules\Crm\Events\ScheduledEmailDue;
use App\Modules\Crm\Jobs\SendDueRemindersJob;
use App\Modules\Crm\Jobs\SendScheduledEmailsJob;
use App\Modules\Crm\Listeners\EmitDealWonWebhook;
use App\Modules\Crm\Listeners\EmitFollowUpCompletedWebhook;
use App\Modules\Crm\Listeners\EmitLeadQualifiedWebhook;
use App\Modules\Crm\Listeners\EmitOutboundEmailSentWebhook;
use App\Modules\Crm\Listeners\EmitProposalSentWebhook;
use App\Modules\Crm\Listeners\EmitReminderDueWebhook;
use App\Modules\Crm\Listeners\EscalateUnansweredFollowUp;
use App\Modules\Crm\Listeners\BroadcastCrmNotification;
use App\Modules\Crm\Listeners\InboundReplyDeliveryDispatcher;
use App\Modules\Crm\Listeners\ReminderDeliveryDispatcher;
use App\Modules\Crm\Listeners\StampLastLoginAt;
use App\Modules\Crm\Listeners\SendDueScheduledEmail;
use App\Modules\Crm\Listeners\SyncLeadEngagementOnCompletion;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadAttachment;
use App\Modules\Crm\Models\Note;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Policies\CallPolicy;
use App\Modules\Crm\Policies\EmailPolicy;
use App\Modules\Crm\Policies\LeadAttachmentPolicy;
use App\Modules\Crm\Policies\LeadPolicy;
use App\Modules\Crm\Policies\NotePolicy;
use App\Modules\Crm\Policies\PlannerEventPolicy;
use App\Modules\Crm\Policies\UserPolicy;
use App\Modules\Crm\Services\BusinessRules\ScoringConfigProvider;
use App\Modules\Crm\Services\Discovery\Contracts\ProposalGenerator;
use App\Modules\Crm\Services\Discovery\Contracts\TagExpander;
use App\Modules\Crm\Services\Discovery\OpenAiProposalGenerator;
use App\Modules\Crm\Services\Discovery\OpenAiTagExpander;
use App\Modules\Crm\Services\Kpi\KpiConfig;
use App\Modules\Crm\Services\NicheCanonicalizer;
use App\Modules\Crm\Services\Mail\Contracts\EmailProvider;
use App\Modules\Crm\Services\Mail\Contracts\GoogleCredentialSource;
use App\Modules\Crm\Services\Mail\Google\GoogleCredentialSourceFactory;
use App\Modules\Crm\Services\Mail\MailboxOauthTokenStore;
use App\Modules\Crm\Services\Mail\MailboxSyncManager;
use App\Modules\Crm\Services\Mail\Providers\SmtpEmailProvider;
use App\Modules\Crm\Services\Ranking\RankingConfig;
use App\Modules\Crm\Services\Scoring\ScoringConfig;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
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
        LeadAttachment::class => LeadAttachmentPolicy::class,
        Note::class => NotePolicy::class,
        Email::class => EmailPolicy::class,
        PlannerEvent::class => PlannerEventPolicy::class,
        Call::class => CallPolicy::class,
        User::class => UserPolicy::class,
    ];

    public function register(): void
    {
        // Personal Lead Score parameters — resolved through the Configuration Provider, the SINGLE source
        // of ScoringConfig (Business Rules Administration §6). The provider reads the active database
        // configuration version, validates it, and merges its editable point values over the file-backed
        // structural caps/windows — falling back to config/scoring.php on any absence, invalidity, or error.
        // The engine stays unaware of the source. Bound as a lazy singleton (built once per request, only
        // when a score is first computed) so the app never fails to boot if the tables are unavailable.
        $this->app->singleton(
            ScoringConfig::class,
            fn ($app): ScoringConfig => $app->make(ScoringConfigProvider::class)->resolve(),
        );

        // Nearby candidate-name generation. Bound to an interface so the OpenAI credential stays on
        // the server side of one seam: the scraper asks the CRM for names and never sees a key, and a
        // different model vendor (or a fake in tests) swaps in here without the scraper learning
        // anything. Not a singleton — it reads the stored configuration per call, so an admin's key
        // or model change takes effect on the next request rather than after a restart.
        $this->app->bind(ProposalGenerator::class, OpenAiProposalGenerator::class);
        $this->app->bind(TagExpander::class, OpenAiTagExpander::class);

        // Business Niche registry lookup for the Nearby Clients surface. SCOPED (one instance per
        // request) on purpose: the canonicalizer memoizes the registry, so serializing a whole
        // prospect pool costs one query instead of one per row — while a long-lived singleton would
        // serve a stale registry to later requests after a niche is created or renamed.
        $this->app->scoped(NicheCanonicalizer::class);

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

        // How a Google client proves it may read a mailbox: keyless domain-wide delegation by
        // default, the legacy JSON key under auth=key, per-mailbox user consent under auth=oauth.
        // Resolved lazily and never contacted at boot, so a deployment with the Google env vars
        // unset still boots — the credential is only exercised by the queued SyncMailboxJob.
        //
        // The token store is resolved from the container rather than left to the factory's default
        // so that one binding governs it everywhere: the eventual consent flow writes grants through
        // the same instance (and the same expiry skew) this reads them through.
        $this->app->singleton(GoogleCredentialSource::class, function (Application $app): GoogleCredentialSource {
            /** @var array<string, mixed> $gmail */
            $gmail = (array) config('outreach.gmail', []);

            return GoogleCredentialSourceFactory::fromConfig($gmail, $app->make(MailboxOauthTokenStore::class));
        });

        // Inbound sync providers, resolved PER MAILBOX from crm_mailbox_syncs.provider rather than
        // from one global binding — see MailboxSyncManager. Gmail remains the only registered
        // driver and the configured default, so current behaviour is unchanged.
        $this->app->singleton(MailboxSyncManager::class, function (Application $app): MailboxSyncManager {
            return new MailboxSyncManager($app);
        });
    }

    public function boot(): void
    {
        // The module route file declares its own `v1/crm` prefix + `auth:sanctum`;
        // here we add the shared `api` prefix/middleware → /api/v1/crm/*.
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__ . '/../Routes/api.php');

        // The Google OAuth callback, mounted at the ROOT with no prefix so its path is exactly
        // /auth/google/callback — the string registered as GOOGLE_REDIRECT_URI on the OAuth client,
        // which Google matches literally. `api` middleware rather than `web`: the flow authenticates
        // by a cache-backed state nonce, so it wants no session or CSRF cookie machinery.
        Route::middleware('api')
            ->group(__DIR__ . '/../Routes/oauth.php');

        // Auto-load the crm_* migrations from inside the module.
        $this->loadMigrationsFrom(__DIR__ . '/../Migrations');

        // Private broadcast channels for CRM real-time features (Reverb). Registered here — like
        // the Projects module does — so the pod owns its own channel authorization. Requires the
        // policies registered below, which is why this sits inside boot().
        require __DIR__ . '/../Routes/channels.php';

        // Blade views owned by the CRM module, namespaced `crm::` — currently the single-lead PDF
        // template. Mirrors the HR module's `hr::` registration; no app-level views are touched.
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'crm');

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

        // `users.last_login_at` is stamped by observing Laravel's own Login event rather than by
        // editing the SHARED AuthController — see StampLastLoginAt for why the write belongs here.
        Event::listen(Login::class, StampLastLoginAt::class);

        // RC3 Send-Later: a due scheduled email is delivered through the single outbound pipeline.
        Event::listen(ScheduledEmailDue::class, SendDueScheduledEmail::class);

        // RC3 Reminders: Planner emits PlannerReminderDue; the delivery subsystem (dispatcher) delivers it.
        Event::listen(PlannerReminderDue::class, ReminderDeliveryDispatcher::class);

        // RC3 Phase 5 — CRM → n8n signed webhooks over domain events (n8n consumes; never drives CRM).
        Event::listen(OutboundEmailSent::class, EmitOutboundEmailSentWebhook::class);
        Event::listen(PlannerReminderDue::class, EmitReminderDueWebhook::class);

        // RC3 Outreach Automation Pack — additive domain events → the same signed n8n emitter.
        Event::listen(ProposalSent::class, EmitProposalSentWebhook::class);
        Event::listen(LeadQualified::class, EmitLeadQualifiedWebhook::class);
        Event::listen(DealWon::class, EmitDealWonWebhook::class);
        Event::listen(FollowUpCompleted::class, EmitFollowUpCompletedWebhook::class);

        // No-Response Escalation (workflow #4) — a follow-up completed without a reply creates a
        // Planner escalation task for the assigned BD (decided & executed server-side).
        Event::listen(FollowUpCompleted::class, EscalateUnansweredFollowUp::class);

        // Planner → Lead Engagement Status. Completing a CALL or EMAIL activity advances the lead's
        // engagement column (advance-only), so the Leads table reflects work the BD has actually done.
        // Communication lifecycle only — `leads.status` stays a manual sales decision.
        Event::listen(FollowUpCompleted::class, SyncLeadEngagementOnCompletion::class);

        // Multi-Channel Internal Notifications — the delivery subsystem for inbound-reply alerts on
        // channels beyond the always-on in-app notification (mail today, gated by
        // `outreach.internal_notifications.mail`; slack/teams/sms later). Internal staff alerts only —
        // customer communication continues to flow through the shared outreach mailbox.
        Event::listen(InboundReplyReceived::class, InboundReplyDeliveryDispatcher::class);

        // Notification Center realtime. Hooks Laravel's own notification lifecycle
        // rather than each producer, so EVERY CRM in-app notification — the two that
        // exist and every one added later — is pushed to its recipient without the
        // producer knowing anything about broadcasting. There is deliberately no
        // second path that creates a notification.
        Event::listen(NotificationSent::class, BroadcastCrmNotification::class);

        // Console commands + inbound-sync maintenance schedule. Registering the schedule is inert
        // until a scheduler/queue worker runs at deploy (and Gmail creds are configured).
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncMailboxesCommand::class,
                RecomputeLeadScoresCommand::class,
                N8nTokenCommand::class,
                PruneReadNotifications::class,
                // One-shot Business Niche registry backfill. Not scheduled — run manually once the
                // canonical built-in list is approved and NicheSeeder has been run.
                BackfillNichesCommand::class,
                // One-shot Planner ownership reconciliation for drift predating the forward transfer.
                // Deliberately NOT scheduled: it is read-only by default and its --apply run is
                // approved from a printed row list, never automated.
                BackfillPlannerOwnershipCommand::class,
                // Hourly cleanup of abandoned .xls import files (scheduled below).
                PruneXlsImportsCommand::class,
                // Provisions (or inspects) the per-org, per-provider inbound integration identity.
                // Run by hand when an integration is set up; never scheduled.
                ProvisionIntegrationAccountCommand::class,
            ]);
        }

        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);
            // Inbound email. THE trigger for mailbox synchronization: the command fans out one
            // SyncMailboxJob per mailbox and returns immediately. Five minutes is the latency the
            // Pub/Sub push used to deliver in seconds — the deliberate trade for needing no Google
            // Cloud topic, no push subscription, and no public webhook.
            //
            // withoutOverlapping() guards the FAN-OUT (a scheduler tick that somehow runs long must
            // not stack); SyncMailboxJob::uniqueId() separately guards each mailbox's actual sync,
            // which is the one that matters because the queue, not the scheduler, does the work.
            $schedule->command('outreach:sync')->everyFiveMinutes()->withoutOverlapping();

            // RC3 Send-Later: dispatch due scheduled outbound emails through the single outbound pipeline.
            $schedule->job(new SendScheduledEmailsJob)->everyMinute();

            // RC3 Reminders: emit due Planner-event reminders (delivered by the dispatcher).
            $schedule->job(new SendDueRemindersJob)->everyMinute();

            // Notification retention: prune READ notifications older than 90 days (unread never deleted).
            $schedule->command('notifications:prune-read')->dailyAt('03:00');

            // Temporary-file retention for queued `.xls` imports. Commit and failure clean up
            // eagerly; this collects the third ending — a conversion previewed and never confirmed,
            // whose cache record expires but whose file a cache expiry cannot unlink. Hourly because
            // the job record's own TTL is an hour, and the command is idempotent and silent when
            // there is nothing to remove.
            $schedule->command('crm:prune-xls-imports')->hourly()->withoutOverlapping();
        });
    }
}
