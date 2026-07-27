<?php

use App\Models\User;
use App\Modules\Crm\Controllers\ActivityController;
use App\Modules\Crm\Controllers\Auth\MeController;
use App\Modules\Crm\Controllers\Auth\UpdateProfileController;
use App\Modules\Crm\Controllers\AutomationEmailController;
use App\Modules\Crm\Controllers\BusinessRuleController;
use App\Modules\Crm\Controllers\CallController;
use App\Modules\Crm\Controllers\DashboardController;
use App\Modules\Crm\Controllers\DeliveryMonitorController;
use App\Modules\Crm\Controllers\EmailController;
use App\Modules\Crm\Controllers\EmailTemplateController;
use App\Modules\Crm\Controllers\GmailWebhookController;
use App\Modules\Crm\Controllers\LeadController;
use App\Modules\Crm\Controllers\LeadFollowUpController;
use App\Modules\Crm\Controllers\LeadOutcomeController;
use App\Modules\Crm\Controllers\LeadQualificationController;
use App\Modules\Crm\Controllers\NearbyClientController;
use App\Modules\Crm\Controllers\NicheController;
use App\Modules\Crm\Controllers\NoteController;
use App\Modules\Crm\Controllers\NotificationController;
use App\Modules\Crm\Controllers\PlannerEventController;
use App\Modules\Crm\Controllers\ServiceAreaController;
use App\Modules\Crm\Controllers\UserController;
use App\Modules\Crm\Controllers\WebsiteEnrichmentController;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Illuminate\Support\Facades\Route;

/*
 * CRM (Zerem) module routes. Mounted under /api/v1/crm/* (the shared `api`
 * prefix is added by CrmServiceProvider). Login/logout/me are NOT here — the
 * platform's shared /api/v1/auth/* handles authentication. Permissions are
 * namespaced `crm.*`; roles are the platform Spatie roles (super_admin/crm_admin/crm_bd).
 */
Route::prefix('v1/crm')->group(function (): void {
    // Public inbound webhook (Gmail Pub/Sub push). Authenticated by the Google-signed OIDC token
    // inside the controller, NOT by Sanctum — Pub/Sub cannot present a bearer session token.
    Route::post('/webhooks/gmail', GmailWebhookController::class)->middleware('throttle:120,1');

    Route::middleware('auth:sanctum')->group(function () {
        // CRM-shaped profile payload + self-service update (own name + phone only).
        // Not admin user management; the platform's /api/v1/auth/me still owns auth.
        Route::get('/me', MeController::class);
        Route::patch('/me', UpdateProfileController::class);

        // Generic, reusable activity feed (append-only Activity log). Powers Dashboard Recent
        // Activity now; reusable for the Activity Logs page, notifications, and future consumers.
        Route::get('/activities', [ActivityController::class, 'index']);

        // In-app Notification Center — reads the existing RC2 Database Notifications (`notifications`
        // table) via the Notifiable relation, scoped to the authenticated user (own notifications only).
        // Static segments declared before the {id} wildcard so they are never captured as an id.
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);

        Route::get('/leads', [LeadController::class, 'index'])->can('viewAny', Lead::class);
        // Leads-page Priority Summary cards: High/Medium/Low counts aggregated from leads.priority
        // (the manual business-priority field — NOT Lead Score, NOT website_status). Filter-aware +
        // ownership-scoped, mirroring the list. Declared before the /leads/{lead} wildcards so the
        // static `priority-summary` segment is not captured as a {lead} route-model-binding id.
        Route::get('/leads/priority-summary', [LeadController::class, 'prioritySummary'])->can('viewAny', Lead::class);
        // Leads-page status tabs: Total/New/Contacted/Qualified/Declined counts over the SAME filtered,
        // ownership-scoped dataset as the list, so the tabs can never disagree with the table.
        // Declared before the /leads/{lead} wildcards, like priority-summary above.
        Route::get('/leads/status-summary', [LeadController::class, 'statusSummary'])->can('viewAny', Lead::class);
        Route::post('/leads/import', [LeadController::class, 'import'])->can('import', Lead::class);
        // Programmatic enrichment ingest — a discovery provider (Python Scraper) submits normalized
        // discovered businesses as JSON. Same `import` permission; lands fit-data only, assigns no owner.
        Route::post('/leads/discovered', [LeadController::class, 'ingestDiscovered'])->can('import', Lead::class);
        // Website Intelligence — audit provider (Scrapy). `queue` lists businesses with a website to
        // audit; `website-enrichment` records the audit findings (1:1) + derives website_status. Strict
        // data provider (same `import` permission); never touches Lead Status / score / ownership.
        Route::get('/website-enrichment/queue', [WebsiteEnrichmentController::class, 'queue'])->can('import', Lead::class);
        Route::put('/leads/{lead}/website-enrichment', [WebsiteEnrichmentController::class, 'update'])->can('import', Lead::class);
        Route::get('/leads/{lead}/activities', [LeadController::class, 'activities'])->can('view', 'lead');
        // Personal Lead Score breakdown (KPI Engine) — the auditable per-signal "why" behind
        // leads.score. Read-only; ownership-gated identically to viewing the lead.
        Route::get('/leads/{lead}/score', [LeadController::class, 'score'])->can('view', 'lead');
        // Read-only email conversation (outbound + inbound) for the Lead Details Email Thread panel.
        // Ownership-gated identically to viewing the lead; reuses Email/EmailResource.
        Route::get('/leads/{lead}/emails', [LeadController::class, 'emails'])->can('view', 'lead');
        // RC3 Phase 2 — active email templates (Purpose + Tone) rendered for this lead, for the composer.
        Route::get('/leads/{lead}/email-templates', [EmailTemplateController::class, 'index'])
            ->middleware('permission:crm.follow-ups.view')
            ->can('view', 'lead');
        Route::post('/leads', [LeadController::class, 'store'])->can('create', Lead::class);

        // Qualification bridge: accept a discovered prospect (Nearby Clients) into the CRM sales pipeline.
        // Policy-gated (leads.qualify → BD/Admin/SA); assigns ownership + triggers the one-time Lead Score.
        Route::post('/leads/{lead}/qualify', [LeadQualificationController::class, 'store'])
            ->can('qualify', 'lead');

        // Archive Management (Admin/Super Admin only). Soft delete (deleted_at) is the single archive
        // state — there is no separate archive table or route family. `role:super_admin|crm_admin` blocks
        // BDs entirely. Declared BEFORE the `/leads/{lead}` wildcard routes so the static `archived` and
        // `restore-all` paths are not captured by the {lead} binding.
        Route::middleware('role:super_admin|crm_admin')->group(function () {
            // List archived leads (leads.view gates viewing; the group already restricts to management).
            Route::get('/leads/archived', [LeadController::class, 'archived'])->can('viewAny', Lead::class);
            // Bulk restore — checked rows (ids) or the whole current filter set. Reuses the single
            // per-record restore path (one restore workflow, one Activity per lead).
            Route::post('/leads/restore-all', [LeadController::class, 'restoreAll'])
                ->middleware('permission:crm.leads.restore');
            // Restore one archived lead. withTrashed() resolves the soft-deleted model for binding;
            // authorized by LeadPolicy@restore (leads.restore + ownership).
            Route::post('/leads/{lead}/restore', [LeadController::class, 'restore'])
                ->withTrashed()
                ->can('restore', 'lead');
            // Permanent delete — architecture-ready, intentionally NOT implemented (future milestone).
            // LeadPolicy@forceDelete already denies; enable when Permanent Delete is scoped.
            // Route::delete('/leads/{lead}/force', [LeadController::class, 'forceDestroy'])
            //     ->withTrashed()->can('forceDelete', 'lead');
        });

        // Business Niche registry — the vocabulary behind the shared Business Niche selector and the
        // Add Business Niche modal. Business CONFIGURATION, not lead data: no niche route touches a
        // lead's status, score, priority or ownership.
        //
        // 🔒 AUTHORIZATION (PO decision 2026-07-24, corrected — SUPERSEDES both the original "any
        // authenticated CRM user" rule and R-1's briefly-applied "BD read-only"):
        //
        //   read + create + rename → every CRM role, INCLUDING Business Development. A BD classifies
        //                            leads as they work, so authoring a niche is part of their job.
        //   delete                 → Super Admin + Admin ONLY. Deletion is the destructive, tenant-wide
        //                            action: the registry is shared, so removing a niche affects every
        //                            lead filed under it. This is the restriction that matters.
        //
        // Roles are listed explicitly rather than relying on "any authenticated user", so a future role
        // does not silently inherit write access to shared configuration.
        //
        // Role middleware, not a Policy: a niche has no ownership or per-instance rules, so there is
        // nothing for a Policy to decide — this matches how the other management-only routes here are
        // gated. The built-in-immutable and still-in-use-can't-be-deleted rules remain business rules
        // enforced in NicheController; they are not authorization.
        //
        // Declared before the /leads/{lead} wildcards purely for grouping — the URIs never collide.
        Route::get('/niches', [NicheController::class, 'index'])
            ->middleware('permission:crm.leads.view');
        Route::middleware('role:super_admin|crm_admin|crm_bd')->group(function () {
            Route::post('/niches', [NicheController::class, 'store']);
            Route::patch('/niches/{niche}', [NicheController::class, 'update']);
        });
        Route::delete('/niches/{niche}', [NicheController::class, 'destroy'])
            ->middleware('role:super_admin|crm_admin');

        Route::get('/leads/{lead}', [LeadController::class, 'show'])->can('view', 'lead');
        Route::match(['put', 'patch'], '/leads/{lead}', [LeadController::class, 'update'])->can('update', 'lead');
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->can('delete', 'lead');
        // Ownership assignment/reassignment — separate from lead edit. Admin/SA only (leads.assign).
        Route::patch('/leads/{lead}/assign', [LeadController::class, 'assign'])->can('assign', 'lead');

        // BD sales-outcome actions (Lead Detail Quick Actions).
        // Won — create a Deal (Deal bounded context). Requires deals.create; ownership + edit via can(update).
        Route::post('/leads/{lead}/won', [LeadOutcomeController::class, 'won'])
            ->middleware('permission:crm.deals.create')
            ->can('update', 'lead');
        // Lost — a lead-side Activity only (no Deal, no status change). Ownership + edit via can(update).
        Route::post('/leads/{lead}/lost', [LeadOutcomeController::class, 'lost'])->can('update', 'lead');

        // Outreach: send an email to a lead (provider-agnostic). Logs an `email.sent`
        // Activity that surfaces on the Dashboard via GET /activities.
        Route::post('/leads/{lead}/emails', [EmailController::class, 'store'])
            ->middleware('permission:crm.follow-ups.create')
            ->can('view', 'lead');

        // Schedule a follow-up for a lead (Lead Details "Schedule follow-up" Quick Action). Delegates
        // to the single authoritative creator, FollowUpScheduler — the SAME flow as Cold Calling →
        // Scheduled — so follow-ups never gain a second creation path. Same authz as sending outreach.
        Route::post('/leads/{lead}/follow-ups', [LeadFollowUpController::class, 'store'])
            ->middleware('permission:crm.follow-ups.create')
            ->can('view', 'lead');

        // Outreach drafts — a draft is an Email lifecycle state (status=draft) on the SAME emails
        // table + EmailController. index is self-scoped to the author; storeDraft authorizes the
        // parent lead; update/delete/send authorize via EmailPolicy (author, draft-only). Sending
        // transitions the same row draft → sent (reuses the send pipeline; no second record).
        Route::get('/emails', [EmailController::class, 'index'])
            ->middleware('permission:crm.follow-ups.view');
        Route::post('/leads/{lead}/emails/drafts', [EmailController::class, 'storeDraft'])
            ->middleware('permission:crm.follow-ups.create')
            ->can('view', 'lead');
        Route::match(['put', 'patch'], '/emails/{email}', [EmailController::class, 'update'])
            ->can('update', 'email');
        Route::delete('/emails/{email}', [EmailController::class, 'destroy'])
            ->can('delete', 'email');
        Route::post('/emails/{email}/send', [EmailController::class, 'send'])
            ->can('send', 'email');
        // RC3 Send-Later — pre-execution actions on a scheduled email (author/Admin, scheduled-only).
        Route::post('/emails/{email}/cancel', [EmailController::class, 'cancel'])
            ->can('cancel', 'email');
        Route::post('/emails/{email}/reschedule', [EmailController::class, 'reschedule'])
            ->can('reschedule', 'email');
        Route::post('/emails/{email}/send-now', [EmailController::class, 'sendNow'])
            ->can('sendNow', 'email');

        // RC3 Phase 4 — read-only delivery-lifecycle monitoring (reuses email_events + reminder_deliveries).
        // Ownership-scoped in the service; no engagement analytics.
        Route::get('/delivery/monitor', [DeliveryMonitorController::class, 'show'])
            ->middleware('permission:crm.follow-ups.view');

        // RC3 Outreach Automation Pack — the dedicated thin automation send endpoint (Option A). Authorized
        // ONLY by `outreach.automation.send` (held by the n8n service account); deliberately NOT gated by
        // can:view,lead, so an ownerless automation account can send system-wide under one narrow ability.
        // Delegates to the single frozen pipeline (OutreachEmailService → EmailProvider). The frozen
        // user-facing POST /leads/{lead}/emails above is unchanged (still can:view,lead + ownership).
        Route::post('/automation/leads/{lead}/emails', [AutomationEmailController::class, 'store'])
            ->middleware('permission:crm.outreach.automation.send');

        // Internal notes on a lead (Lead Internal Notes Module). Read/create reuse LeadPolicy@view
        // (ownership); create also needs lead.notes.create. Edit/delete authorize via NotePolicy
        // (author/Admin/SA); scopeBindings ties {note} to its {lead}. Each write logs a note.* Activity.
        Route::get('/leads/{lead}/notes', [NoteController::class, 'index'])->can('view', 'lead');
        Route::post('/leads/{lead}/notes', [NoteController::class, 'store'])
            ->middleware('permission:crm.lead.notes.create')
            ->can('view', 'lead');
        Route::patch('/leads/{lead}/notes/{note}', [NoteController::class, 'update'])
            ->scopeBindings()
            ->can('update', 'note');
        Route::delete('/leads/{lead}/notes/{note}', [NoteController::class, 'destroy'])
            ->scopeBindings()
            ->can('delete', 'note');

        // Planner — the single scheduling platform (Calendar + Board). The list is ownership-scoped
        // (PlannerEvent::scopeVisibleTo: BD sees own/on-owned-leads; Admin/SA all); single-record
        // routes authorize via PlannerEventPolicy. Every write logs a `planner-event.*` Activity via
        // PlannerEventObserver → Activity Log. The permission namespace stays `calendar.*` (only the
        // UI module is branded "Planner"); `calendar.delete` is management-only (Admin/SA).
        Route::get('/planner-events', [PlannerEventController::class, 'index'])->can('viewAny', PlannerEvent::class);
        Route::post('/planner-events', [PlannerEventController::class, 'store'])->can('create', PlannerEvent::class);
        Route::get('/planner-events/{plannerEvent}', [PlannerEventController::class, 'show'])->can('view', 'plannerEvent');
        Route::match(['put', 'patch'], '/planner-events/{plannerEvent}', [PlannerEventController::class, 'update'])
            ->can('update', 'plannerEvent');
        Route::delete('/planner-events/{plannerEvent}', [PlannerEventController::class, 'destroy'])->can('delete', 'plannerEvent');

        // Calling Workspace — call attempts are Planner-driven: every attempt belongs to a planner
        // event (created on the fly for an ad-hoc call). ALL state changes route through
        // CallSessionService; the controller never persists a Call. `crm.calls.view` gates reading (Call
        // History), `crm.calls.log` gates logging/concluding. Read + start authorize on the parent lead
        // or event (ownership); complete/discard authorize via CallPolicy@update. Domain-rule breaches
        // (single-active-call, already-concluded) surface as 422 from the service.
        Route::get('/leads/{lead}/calls', [CallController::class, 'index'])
            ->middleware('permission:crm.calls.view')
            ->can('view', 'lead');
        Route::post('/leads/{lead}/calls', [CallController::class, 'storeForLead'])
            ->middleware('permission:crm.calls.log')
            ->can('view', 'lead');
        Route::post('/planner-events/{plannerEvent}/calls', [CallController::class, 'storeForEvent'])
            ->middleware('permission:crm.calls.log')
            ->can('view', 'plannerEvent');
        Route::patch('/calls/{call}/complete', [CallController::class, 'complete'])->can('update', 'call');
        Route::patch('/calls/{call}/discard', [CallController::class, 'discard'])->can('update', 'call');

        // Team Accounts (user management). Policy-guarded (users.* permissions); Super Admin bypasses
        // via Gate::before. BD Manager has no users.* permission → 403.
        Route::get('/users', [UserController::class, 'index'])->can('viewAny', User::class);
        Route::post('/users', [UserController::class, 'store'])->can('create', User::class);
        Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->can('update', 'user');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->can('delete', 'user');

        // Nearby Clients — discovered-business prospect pool. A shared prospecting surface of leads
        // landed by a discovery provider. Data only, and provider-agnostic (the supplying provider is
        // never exposed).
        //
        // 🔒 BD workspace (PO decision 2026-07-24, RBAC Hardening R-2): Business Development + Super
        // Admin only — Admin (CRM Manager) has NO access, per the approved Admin module list. This
        // route previously had no authorization at all (RBAC audit finding 3).
        //
        // The Target Service Area registry below is a SEPARATE surface and is unchanged — it stays
        // Admin/SA-gated at the API. Its only UI entry point remains the Nearby Clients header action,
        // so in practice only Super Admin reaches it once Admin loses this page. That consequence is
        // deliberate and deferred: Target Areas is being reviewed under its own PDR (keep / redesign /
        // retire), and this milestone makes no further change to it.
        Route::get('/nearby-clients', [NearbyClientController::class, 'index'])
            ->middleware('role:super_admin|crm_bd');

        // Nearby Clients — Service-Area Registry (target service locations for the frozen Target
        // Location +10 signal). Business configuration owned by Admin/Super Admin; supplies data only,
        // never computes the Lead Score. Read + write both restricted to management roles.
        Route::middleware('role:super_admin|crm_admin')->group(function () {
            Route::get('/service-areas', [ServiceAreaController::class, 'index']);
            Route::post('/service-areas', [ServiceAreaController::class, 'store']);
            Route::delete('/service-areas/{serviceArea}', [ServiceAreaController::class, 'destroy']);
        });

        Route::middleware('permission:crm.dashboard.view')->prefix('dashboard')->group(function () {
            Route::get('/kpis', [DashboardController::class, 'kpis']);
            Route::get('/copilot-summary', [DashboardController::class, 'copilotSummary']);
            Route::get('/priority-buckets', [LeadController::class, 'websiteStatusBuckets']);
            Route::get('/recent-leads', [DashboardController::class, 'recentLeads']);
            Route::get('/lead-acquisition', [DashboardController::class, 'leadAcquisition']);

            // "My Performance" personal KPI summary (BD-facing surface). Ownership-scoped in the
            // controller (Lead::scopeVisibleTo); dashboard.view already gates the group.
            Route::get('/performance-summary', [DashboardController::class, 'performanceSummary']);

            // BD KPI Engine — the operational KPI catalog (M-01…M-07) with an explainable, per-metric
            // breakdown. Ownership-scoped in the controller; dashboard.view already gates the group.
            Route::get('/kpi-metrics', [DashboardController::class, 'kpiMetrics']);

            // Reports & Performance management analytics (funnel / status-mix / outreach-by-channel /
            // niche / follow-through / pending-follow-ups). Same scope seam as performance-summary
            // (All Reports, or one BD via ?user_id — the latter authorized to crm.reports.view holders).
            Route::get('/reports-analytics', [DashboardController::class, 'reportsAnalytics']);

            // Management Dashboard "BD Performance Overview" — Admin/Super Admin only. Aggregates
            // one live row per active Business Development Manager (BDs have dashboard.view but must
            // not see the team roster, so this is restricted beyond the group's permission gate).
            Route::middleware('role:super_admin|crm_admin')
                ->get('/bd-performance', [DashboardController::class, 'bdPerformance']);

            // BD Ranking (Interim) — relative 0–100 index + rank across active BDs (management view).
            // Admin/Super-Admin only; the response is labelled Interim (R-10).
            Route::middleware('role:super_admin|crm_admin')
                ->get('/bd-ranking', [DashboardController::class, 'bdRanking']);
        });

        // Settings → Business Rules (Business Rules Administration). The single administration surface
        // for configurable business computations — configuration only, never computed results. `{module}`
        // is constrained to the enabled modules (v1: lead_scoring); reserved modules (kpi/ranking) 404
        // until approved. Reads gate on crm.business_rules.view, writes on crm.business_rules.manage.
        // Publish/rollback endpoints arrive with the Publish/Rollback integration step.
        Route::prefix('settings/business-rules')->whereIn('module', ['lead_scoring'])->group(function () {
            Route::middleware('permission:crm.business_rules.view')->group(function () {
                Route::get('/{module}/active', [BusinessRuleController::class, 'active']);
                Route::get('/{module}/draft', [BusinessRuleController::class, 'draft']);
                Route::get('/{module}/versions', [BusinessRuleController::class, 'versions']);
                Route::get('/{module}/versions/{version}', [BusinessRuleController::class, 'version'])->whereNumber('version');
                Route::get('/{module}/audit', [BusinessRuleController::class, 'audit']);
            });

            Route::middleware('permission:crm.business_rules.manage')->group(function () {
                Route::post('/{module}/draft', [BusinessRuleController::class, 'ensureDraft']);
                Route::delete('/{module}/draft', [BusinessRuleController::class, 'discardDraft']);
                // `/rules/order` is declared BEFORE `/rules/{ruleKey}` so "order" is never captured as a key.
                Route::patch('/{module}/rules/order', [BusinessRuleController::class, 'reorder']);
                Route::patch('/{module}/rules/{ruleKey}', [BusinessRuleController::class, 'updateRule']);
                Route::post('/{module}/rules/{ruleKey}/enable', [BusinessRuleController::class, 'enableRule']);
                Route::post('/{module}/rules/{ruleKey}/disable', [BusinessRuleController::class, 'disableRule']);

                // Lifecycle: publish the draft; forward-only rollback to a prior published version. Both
                // invalidate the provider cache and dispatch a version-pinned recompute AFTER commit.
                Route::post('/{module}/publish', [BusinessRuleController::class, 'publish']);
                Route::post('/{module}/rollback', [BusinessRuleController::class, 'rollback']);
            });
        });
    });
});
