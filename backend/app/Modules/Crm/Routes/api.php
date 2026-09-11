<?php

use App\Models\User;
use App\Modules\Crm\Controllers\ActivityController;
use App\Modules\Crm\Controllers\Auth\MeController;
use App\Modules\Crm\Controllers\Auth\ProfilePhotoController;
use App\Modules\Crm\Controllers\Auth\UpdateProfileController;
use App\Modules\Crm\Controllers\AutomationEmailController;
use App\Modules\Crm\Controllers\BusinessRuleController;
use App\Modules\Crm\Controllers\CallController;
use App\Modules\Crm\Controllers\CallSettingsController;
use App\Modules\Crm\Controllers\TwilioVoiceWebhookController;
use App\Modules\Crm\Http\Middleware\ValidateTwilioSignature;
use App\Modules\Crm\Controllers\DashboardController;
use App\Modules\Crm\Controllers\DeliveryMonitorController;
use App\Modules\Crm\Controllers\EmailController;
use App\Modules\Crm\Controllers\EmailTemplateController;
use App\Modules\Crm\Controllers\DiscoveryConfigController;
use App\Modules\Crm\Controllers\GmailOAuthController;
use App\Modules\Crm\Controllers\NerbyProviderSettingController;
use App\Modules\Crm\Controllers\LeadAttachmentController;
use App\Modules\Crm\Controllers\LeadController;
use App\Modules\Crm\Controllers\LeadFollowUpController;
use App\Modules\Crm\Controllers\LeadImportStatusController;
use App\Modules\Crm\Controllers\LeadOutcomeController;
use App\Modules\Crm\Controllers\LeadQualificationController;
use App\Modules\Crm\Controllers\NearbyClientController;
use App\Modules\Crm\Controllers\NicheController;
use App\Modules\Crm\Controllers\NoteController;
use App\Modules\Crm\Controllers\NotificationController;
use App\Modules\Crm\Controllers\PerformanceTargetController;
use App\Modules\Crm\Controllers\PlannerEventController;
use App\Modules\Crm\Controllers\ServiceAreaController;
use App\Modules\Crm\Controllers\TeamController;
use App\Modules\Crm\Controllers\UserController;
use App\Modules\Crm\Controllers\WebsiteEnrichmentController;
use App\Modules\Crm\Controllers\WebsiteEvidenceController;
use App\Modules\Crm\Http\Middleware\EnsureCrmAccessIsActive;
use App\Modules\Crm\Http\Middleware\EnsurePerformanceManagement;
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
    // NOTE: there is deliberately no public inbound webhook. Inbound email is pulled by the
    // scheduled `outreach:sync` poll, so the CRM exposes no unauthenticated endpoint for it and
    // needs no Pub/Sub push subscription, OIDC verification, or public HTTPS callback.

    // EnsureCrmAccessIsActive covers EVERY route below: CRM suspension is enforced per-request
    // (403 + `crm_access_suspended`), never by revoking the shared Sanctum token, which also signs the
    // user in to TeamOra and Sprintly. `GET /crm/me` is the single exemption — see the middleware.
    /*
 * THE THREE GATES, added 2026-09-03. Until then this module had NONE of them, and
 * a plan was a price list: an org could avail Basic and reach every route Full
 * sells, and an org whose subscription had lapsed -- or that never had one -- kept
 * working exactly as before.
 *
 *   set-current-org      pins the tenant for the whole request, before anything reads
 *   subscription-active  402s a lapsed or absent subscription (super_admin bypasses)
 *   feature:crm     refuses an org that never availed this module at all
 *
 * Order matters: subscription-active and feature both ask CurrentOrg who the tenant
 * is, so set-current-org has to run first.
 */
Route::middleware(['auth:sanctum', 'set-current-org', 'subscription-active', 'feature:crm', EnsureCrmAccessIsActive::class])->group(function () {
        // CRM-shaped profile payload + self-service update (own name + phone only).
        // Not admin user management; the platform's /api/v1/auth/me still owns auth.
        Route::get('/me', MeController::class);
        Route::patch('/me', UpdateProfileController::class);

        // The caller's own profile photo. Self-service like PATCH /me above — no user id is
        // accepted, the account comes from the token, so neither route can touch another user's
        // avatar. Bytes go on the PRIVATE disk and are read back through a short-lived signed URL
        // (`avatar_url` on the resource), because an <img> tag carries no bearer token.
        //
        // Throttled: an upload writes a file, and the endpoint is reachable by every signed-in
        // user. DELETE is idempotent and deliberately un-throttled — removing a photo you no
        // longer want should never be rate-limited.
        Route::post('/me/photo', [ProfilePhotoController::class, 'store'])->middleware('throttle:10,1');
        Route::delete('/me/photo', [ProfilePhotoController::class, 'destroy']);

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
        Route::patch('/notifications/{id}/unread', [NotificationController::class, 'markAsUnread']);
        // Dismiss = take the row off the bell dropdown (`scope=inbox`). PATCH, not
        // DELETE: nothing is removed — History still lists it, and the retention
        // command remains the only thing that deletes a notification.
        Route::patch('/notifications/{id}/dismiss', [NotificationController::class, 'dismiss']);

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

        // Poll a queued `.xls` conversion. Same `import` ability as the upload — no new permission
        // surface; ownership is enforced per-record in XlsImportJobStore (foreign job => 404).
        Route::get('/leads/import/{jobId}/status', [LeadImportStatusController::class, 'show'])
            ->can('import', Lead::class);
        // Programmatic enrichment ingest — a discovery provider (Python Scraper) submits normalized
        // discovered businesses as JSON. Same `import` permission; lands fit-data only, assigns no owner.
        Route::post('/leads/discovered', [LeadController::class, 'ingestDiscovered'])->can('import', Lead::class);
        // Website Intelligence — audit provider (Scrapy). `queue` lists businesses with a website to
        // audit; `website-enrichment` records the audit findings (1:1) + derives website_status. Strict
        // data provider (same `import` permission); never touches Lead Status / score / ownership.
        Route::get('/website-enrichment/queue', [WebsiteEnrichmentController::class, 'queue'])->can('import', Lead::class);
        Route::put('/leads/{lead}/website-enrichment', [WebsiteEnrichmentController::class, 'update'])->can('import', Lead::class);

        // Nearby discovery provider — the scraper's window onto the configuration an admin chose in
        // Settings → Integrations. Same `import` permission as the two providers above, because it is
        // the same service doing the same job; deliberately NOT gated on an admin role, since the
        // scraper authenticates as a service account and should not need to be an administrator.
        //
        // `config` answers with the EFFECTIVE provider, never the merely-selected one. That keeps the
        // fallback rule in exactly one place: "OpenAI selected but no key" resolves to OpenStreetMap
        // here, so the scraper needs no fallback logic of its own and cannot disagree with the CRM.
        //
        // `proposals` makes the OpenAI call SERVER-SIDE and returns names only. The scraper never
        // receives the API key — that is the whole point of the endpoint existing. Refused with 409
        // unless AI is genuinely the effective provider, so a caller that skipped `config` cannot
        // spend on a provider nobody enabled.
        //
        // IT CARRIES A ROLE GATE ON TOP OF THE PERMISSION, unlike its siblings, because it SPENDS
        // MONEY. `crm.leads.import` is deliberately held by Business Development Managers too (their
        // own spreadsheet imports), so permission alone would let any BD run up an OpenAI bill.
        // Triggering paid AI work is closer to managing the integration than to importing a file, so
        // it is gated like the Settings surface. The scraper's service account is an Admin/Super
        // Admin token already, so this changes nothing operationally.
        // Website candidate evidence — the chain that lets `website-unknown` legitimately become
        // `no-website`. Two audiences on one trail.
        //
        // The DISCOVERY SERVICE (same `import` permission as the ingest and audit queue it sits
        // beside) reads the search queue, submits the candidates a search pass produced — possibly
        // none, which is itself evidence — and submits the crawler's findings per candidate. It can
        // never set a status: adjudication reuses the unmodified WebsiteIdentityVerifier, at a
        // stricter bar than the audit because these URLs were guessed rather than asserted.
        //
        // A BD reads the evidence and, only once the search is exhausted, confirms that no credible
        // website exists. That confirmation is the ONLY path to `no-website` — WebsiteStatusDeriver
        // still cannot produce it and is untouched. Guarded by the lead policy rather than a role, so
        // a BD acts on leads they can already see; `confirmAbsence` additionally requires `update`,
        // since it changes a field on the lead.
        Route::get('/website-evidence/queue', [WebsiteEvidenceController::class, 'queue'])->can('import', Lead::class);
        Route::post('/leads/{lead}/website-candidates', [WebsiteEvidenceController::class, 'storeCandidates'])->can('import', Lead::class);
        Route::put('/leads/{lead}/website-candidates/{candidate}', [WebsiteEvidenceController::class, 'verifyCandidate'])->can('import', Lead::class);
        Route::get('/leads/{lead}/website-evidence', [WebsiteEvidenceController::class, 'show'])->can('viewWebsiteEvidence', 'lead');
        Route::post('/leads/{lead}/website-absence', [WebsiteEvidenceController::class, 'confirmAbsence'])->can('confirmWebsiteAbsence', 'lead');

        Route::get('/discovery/config', [DiscoveryConfigController::class, 'config'])->can('import', Lead::class);
        Route::post('/discovery/proposals', [DiscoveryConfigController::class, 'proposals'])
            ->can('import', Lead::class)
            ->middleware(['role:super_admin|crm_admin', 'throttle:30,1']);
        // Search STRATEGY, not business records: which OSM tags a category can hide under. Same
        // guard and throttle as `proposals` because it reaches the same paid provider — though the
        // answer is cached per category forever, so a healthy deployment calls it a handful of times
        // in total.
        Route::post('/discovery/tag-expansions', [DiscoveryConfigController::class, 'tagExpansions'])
            ->can('import', Lead::class)
            ->middleware(['role:super_admin|crm_admin', 'throttle:30,1']);
        Route::get('/leads/{lead}/activities', [LeadController::class, 'activities'])->can('view', 'lead');
        // One lead as a PDF profile. Authorized by `view` on the lead — the same ownership boundary as
        // reading it — so a BD can export their own leads and nobody can export a lead they cannot see.
        // Shared by the Lead Details header action and the BD table row action (one implementation).
        Route::get('/leads/{lead}/export-pdf', [LeadController::class, 'exportPdf'])->can('view', 'lead');
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

        /*
         * Org-level team management: the CURRENT org's CRM staff.
         *
         * This is how a client's own super admin gives their people accounts --
         * one of the two halves of "create roles, then create the people who hold
         * them". Guarded three ways and none of them is the URL: the admin check
         * lives in TeamController::ensureAdmin, membership is fenced to the current
         * org, and the role a member may be given is restricted to this module's
         * presets. Seat count is capped by the org's subscription (see Seats).
         */
        Route::get('team', [TeamController::class, 'index']);
        Route::post('team', [TeamController::class, 'store']);
        Route::put('team/{user}', [TeamController::class, 'update']);
        Route::delete('team/{user}', [TeamController::class, 'destroy']);
            // List archived leads (leads.view gates viewing; the group already restricts to management).
            Route::get('/leads/archived', [LeadController::class, 'archived'])->can('viewAny', Lead::class);
            // Bulk restore — checked rows (ids) or the whole current filter set. Reuses the single
            // per-record restore path (one restore workflow, one Activity per lead).
            Route::post('/leads/restore-all', [LeadController::class, 'restoreAll'])
                ->middleware('permission:crm.leads.restore');
            // "Archive all leads" — every lead in the caller's authorized scope, not just the page
            // on screen. Same soft-delete mechanism as DELETE /leads/{lead}: the leads land in the
            // Archive above and are restorable by the two routes around this one. Static paths, so
            // declared inside this management-only group and ahead of the /leads/{lead} wildcards.
            // The count feeds the confirmation dialog from the SAME scope the action uses.
            Route::get('/leads/deletable-count', [LeadController::class, 'deletableCount'])
                ->middleware('permission:crm.leads.delete');
            Route::post('/leads/destroy-all', [LeadController::class, 'destroyAll'])
                ->middleware('permission:crm.leads.delete');
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

        // Files attached to a lead (Lead Attachments Module). List/download reuse LeadPolicy@view
        // (the same ownership boundary as opening the lead); upload also needs
        // lead.attachments.create. Delete authorizes via LeadAttachmentPolicy (uploader/Admin/SA).
        // scopeBindings ties {attachment} to its {lead}, so an attachment id from another lead 404s
        // instead of resolving — per-lead isolation is enforced by the binding, not by the caller.
        Route::get('/leads/{lead}/attachments', [LeadAttachmentController::class, 'index'])
            ->can('view', 'lead');
        Route::post('/leads/{lead}/attachments', [LeadAttachmentController::class, 'store'])
            ->middleware('permission:crm.lead.attachments.create')
            ->can('view', 'lead');
        Route::get('/leads/{lead}/attachments/{attachment}/download', [LeadAttachmentController::class, 'download'])
            ->scopeBindings()
            ->can('view', 'lead');
        // Same bytes, different shape — so deliberately the SAME authorization as download above,
        // not a weaker one. Returns a bounded JSON window of a spreadsheet; every other file type
        // 422s and falls back to download.
        Route::get('/leads/{lead}/attachments/{attachment}/preview', [LeadAttachmentController::class, 'preview'])
            ->scopeBindings()
            ->can('view', 'lead');
        Route::delete('/leads/{lead}/attachments/{attachment}', [LeadAttachmentController::class, 'destroy'])
            ->scopeBindings()
            ->can('delete', 'attachment');

        // Planner — the single scheduling platform (Calendar + Board). The list is ownership-scoped
        // (PlannerEvent::scopeVisibleTo: BD sees only events assigned to them; Admin/SA all); single-record
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
        // What this deployment's calling can do, for this lead — drives which controls the Call panel
        // renders. Read-only and gated like Call History.
        Route::get('/leads/{lead}/calling-capability', [CallController::class, 'capability'])
            ->middleware('permission:crm.calls.view')
            ->can('view', 'lead');
        // A short-lived browser credential for an open call. Authorized exactly like concluding one,
        // because being allowed to place the call is the same right as being allowed to log it.
        // Throttled per authenticated user (user ids are platform-wide, so this is already
        // tenant-safe): 10 a minute is far above any honest retry pattern, and each token is a live
        // credential on the centralized Twilio account. Manual calling and the Twilio webhooks are
        // deliberately NOT throttled.
        Route::post('/calls/{call}/token', [CallController::class, 'token'])
            ->can('update', 'call')
            ->middleware('throttle:10,1,crm-call-token:');
        Route::patch('/calls/{call}/complete', [CallController::class, 'complete'])->can('update', 'call');
        Route::patch('/calls/{call}/discard', [CallController::class, 'discard'])->can('update', 'call');

        // Team Accounts (user management). Policy-guarded (users.* permissions); Super Admin bypasses
        // via Gate::before. BD Manager has no users.* permission → 403.
        Route::get('/users', [UserController::class, 'index'])->can('viewAny', User::class);
        // Email-first Create Account: does this address already belong to a centralized user?
        // Declared before any /users/{user} wildcard so "lookup" is never captured as an id.
        Route::get('/users/lookup', [UserController::class, 'lookup'])->can('viewAny', User::class);
        Route::post('/users', [UserController::class, 'store'])->can('create', User::class);
        Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->can('update', 'user');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->can('delete', 'user');
        // Frozen performance readings for a BD who has left the pipeline — the only way to answer
        // "what was their record?" once their leads have been handed on. Same policy as viewing them.
        Route::get('/users/{user}/performance-snapshots', [UserController::class, 'performanceSnapshots']);
        // Resignation handover: a departing BD's whole portfolio to one active BD. Runs through the
        // same per-lead assignment logic as PATCH /leads/{lead}/assign — never a bulk UPDATE.
        Route::post('/users/{user}/reassign-leads', [UserController::class, 'reassignLeads']);

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

            // Performance scorecard as a PDF — the ONE export behind both My Performance (BD, own
            // scope) and Reports & Performance (Admin/SA: one BD via ?user_id, or the CRM-wide All
            // Reports default). Same PerformanceScopeRequest seam as the JSON endpoints above, so a
            // BD passing ?user_id is refused 403 here exactly as it is there — no separate export
            // permission, because reading the figures and downloading them are the same privilege.
            Route::get('/performance-export-pdf', [DashboardController::class, 'exportPerformancePdf']);

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
        // Settings → Manage Performance (Goal Management). Reached from Reports & Performance, not
        // from the Settings nav — the entry point lives where managers already read performance.
        //
        // Gated on `crm.performance.manage`, held by Super Admin AND Admin (approved PO decision:
        // performance management is a management capability). A BD holds neither and is refused.
        // It stays a permission SEPARATE from `crm.business_rules.manage` even though both roles now
        // hold both: they are different capabilities, so Lead Scoring administration and Performance
        // management can be narrowed independently — in particular when the temporary Admin grant on
        // business rules is revisited before production.
        Route::middleware('permission:crm.performance.manage')
            ->prefix('settings/performance-targets')
            ->group(function () {
                Route::get('/', [PerformanceTargetController::class, 'index']);
                Route::post('/', [PerformanceTargetController::class, 'store']);
                Route::patch('/{performanceTarget}', [PerformanceTargetController::class, 'update']);
                Route::delete('/{performanceTarget}', [PerformanceTargetController::class, 'destroy']);
            });

        // `kpi` joins the enabled modules: the KPI definition catalog (M-01…M-06) is managed through
        // the SAME Business Rules surface rather than a second CRUD implementation. Its WRITES also
        // require `crm.performance.manage` (see below), while lead_scoring keeps its existing
        // `crm.business_rules.manage` gate untouched.
        Route::prefix('settings/business-rules')->whereIn('module', ['lead_scoring', 'kpi'])->group(function () {
            Route::middleware('permission:crm.business_rules.view')->group(function () {
                Route::get('/{module}/active', [BusinessRuleController::class, 'active']);
                Route::get('/{module}/draft', [BusinessRuleController::class, 'draft']);
                Route::get('/{module}/versions', [BusinessRuleController::class, 'versions']);
                Route::get('/{module}/versions/{version}', [BusinessRuleController::class, 'version'])->whereNumber('version');
                Route::get('/{module}/audit', [BusinessRuleController::class, 'audit']);
            });

            // Writes require crm.business_rules.manage AND — for the `kpi` module only — also
            // crm.performance.manage. Stacking rather than duplicating the route list keeps one
            // definition per endpoint: lead_scoring is gated exactly as before, and KPI definitions
            // additionally demand the performance capability. Admin and Super Admin hold both and
            // pass for either module; a role holding only business-rules management would keep Lead
            // Scoring and be refused on KPI, which is what keeps the two capabilities separable.
            Route::middleware([
                'permission:crm.business_rules.manage',
                EnsurePerformanceManagement::class,
            ])->group(function () {
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

        // Settings → Integrations: connect the CRM's shared mailbox to Google (Gmail OAuth consent).
        // Admin/Super-Admin only — this authorizes access to the ORG's mail, not a personal setting,
        // so it is gated like the other org-configuration surfaces rather than by ownership.
        //
        // POST because it has an effect: each call mints a single-use, short-lived state nonce. It
        // returns only a URL; nothing is connected until the user comes back through the ROOT-level
        // /auth/google/callback (Routes/oauth.php), which Google reaches with no bearer token and
        // which therefore cannot live inside this authenticated group.
        //
        // GET returns safe metadata only (mailbox / provider / connected / status) — no token, no
        // expiry, no ciphertext. DELETE revokes at Google where it can and always drops the local
        // grant, so a disconnect cannot be blocked by a Google outage.
        Route::middleware('role:super_admin|crm_admin')->group(function () {
            Route::get('/settings/integrations/gmail', [GmailOAuthController::class, 'show']);
            Route::delete('/settings/integrations/gmail', [GmailOAuthController::class, 'destroy']);
            Route::post('/settings/integrations/gmail/authorization', [GmailOAuthController::class, 'store']);

            // Settings → Integrations → Calling: the client's OWN Twilio account, which turns on
            // browser calling for this organization.
            //
            // `crm_admin` is allowed here, unlike the Nearby routes below. That is the documented
            // distinction, not an oversight: Nearby holds ViteSEO's centrally-bought API keys and is
            // therefore super-admin only, while these credentials belong to the client, are billed to
            // the client, and are stored per organization in `crm_call_provider_settings`. The Nearby
            // comment below states the rule for exactly this case — a table a client brings its own
            // key to needs org_id and the crm_admin gate.
            //
            // GET returns identifiers, secret PRESENCE (last 4 characters only) and what is actually
            // in force — never an Auth Token, never an API Secret, never ciphertext, never a length.
            //
            // PUT treats both secrets as write-only: omitting one keeps the stored value, so
            // correcting a caller ID never sends a live secret through a browser again. Clearing them
            // is the separate DELETE, because a blank field usually means "I didn't retype it".
            //
            // POST .../test verifies the stored credentials with read-only Twilio REST lookups and
            // records a status code. It NEVER places a call. Throttled, because it reaches a third
            // party on a button press.
            Route::get('/settings/integrations/calling', [CallSettingsController::class, 'show']);
            Route::put('/settings/integrations/calling', [CallSettingsController::class, 'update']);
            Route::delete('/settings/integrations/calling/credentials', [CallSettingsController::class, 'destroyCredentials']);
            Route::post('/settings/integrations/calling/test', [CallSettingsController::class, 'test'])
                ->middleware('throttle:10,1');

            // Settings → Integrations → Nearby Lead Generation: which engine fills the discovered-
            // business pool. A Business Development Manager is refused here at the route, so the UI
            // hiding these controls is presentation rather than protection.
            //
            // GET returns configuration plus the EFFECTIVE provider and a reason code — never the
            // API key, never its ciphertext, never its length. Selecting OpenAI without a usable
            // credential falls back to OpenStreetMap, and the response says so rather than letting a
            // client display "OpenAI" for a run that queried Overpass.
            //
            // PUT treats the API key as write-only: omitting it keeps the stored one, so changing a
            // model or flipping the switch never sends a credential through a browser again. Clearing
            // it is the separate DELETE, because a blank field usually means "I didn't retype it".
            //
            // POST .../test verifies the stored credential and records a status code. Throttled: the
            // button spends money, so it must not be a load generator.
            // Provider-KEYED, so a third engine (a structured Places provider is under evaluation)
            // needs an enum case and nothing else — no new route, no new column, no migration.
            //
            // GET returns every provider's configuration plus the EFFECTIVE provider and a reason
            // code — never a credential, never its ciphertext, never its length. Selecting a provider
            // without a usable credential falls back to OpenStreetMap, and the response says so
            // rather than letting a client display a provider that is not actually running.
            //
            // PUT /provider is SELECTION only: it never enables anything and never touches a
            // credential, because choosing an engine and authorising it to spend money are different
            // decisions and one mis-click must not do both.
            //
            // PUT /providers/{provider} configures one engine. The credential is write-only —
            // omitting it keeps the stored one, so changing a model never sends a key through a
            // browser again. Clearing it is the separate DELETE.
            //
            // POST .../test verifies one stored credential and records a status code. Throttled: the
            // button spends money, so it must not be a load generator.
            // SUPER ADMIN ONLY, unlike Gmail directly above. `crm_nerby_provider_settings`
            // is the one CRM table deliberately left without org_id, because the keys it
            // holds are Vite SEO's own, bought and paid for by us and shared by every
            // tenant. Its migration promised this gate and the route did not carry it:
            // under `crm_admin` a client's own admin could replace or delete the
            // credential every other client's Nearby Clients runs on, and spend our
            // money testing it. A global table is only safe behind a global-only gate.
            //
            // If a client is ever meant to bring their own key, this is the wrong fix:
            // the table needs org_id and this group goes back to crm_admin.
            Route::middleware('role:super_admin')->group(function () {
                Route::get('/settings/integrations/nerby', [NerbyProviderSettingController::class, 'show']);
                Route::put('/settings/integrations/nerby/provider', [NerbyProviderSettingController::class, 'select']);
                Route::put('/settings/integrations/nerby/providers/{provider}', [NerbyProviderSettingController::class, 'update']);
                Route::delete('/settings/integrations/nerby/providers/{provider}/credential', [NerbyProviderSettingController::class, 'destroyCredential']);
                Route::post('/settings/integrations/nerby/providers/{provider}/test', [NerbyProviderSettingController::class, 'test'])
                    ->middleware('throttle:10,1');
            });
        });
    });
});

/*
|--------------------------------------------------------------------------
| Twilio Voice webhooks — the ONE unauthenticated CRM surface
|--------------------------------------------------------------------------
|
| Mounted OUTSIDE the authenticated group above, and outside `set-current-org`,
| `subscription-active`, `feature:crm` and `EnsureCrmAccessIsActive`. That is a
| deliberate, narrow exception to the note at the top of this file, and it exists
| because Twilio calls us from the internet with no user, no token and no tenant:
| there is no credential it could present to any of those gates.
|
| Two independent checks replace them, and BOTH must pass:
|
|   1. ValidateTwilioSignature  — the official Twilio SDK's RequestValidator proves
|                                 the request really came from Twilio. Anything
|                                 unsigned or mis-signed is refused 403.
|   2. CallCorrelation          — an HMAC token, minted earlier by an AUTHENTICATED
|                                 request that passed CallPolicy, names the single
|                                 crm_calls row this webhook may touch. No id from
|                                 the request body is ever dereferenced.
|
| The org is then read from that row's existing relationships. No tenant is
| resolved here and multi-tenancy is untouched.
|
| These routes are inert unless Twilio is configured: the middleware refuses with
| 503 when credentials are absent, which is every deployment running the free
| manual mode.
|
*/
Route::prefix('v1/twilio')->middleware(ValidateTwilioSignature::class)->group(function (): void {
    Route::post('/voice', [TwilioVoiceWebhookController::class, 'voice']);
    Route::post('/voice/completed', [TwilioVoiceWebhookController::class, 'completed']);
});
