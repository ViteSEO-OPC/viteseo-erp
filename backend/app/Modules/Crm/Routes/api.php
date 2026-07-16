<?php

use App\Models\User;
use App\Modules\Crm\Controllers\ActivityController;
use App\Modules\Crm\Controllers\DashboardController;
use App\Modules\Crm\Controllers\EmailController;
use App\Modules\Crm\Controllers\GmailWebhookController;
use App\Modules\Crm\Controllers\LeadController;
use App\Modules\Crm\Controllers\LeadFollowUpController;
use App\Modules\Crm\Controllers\NearbyClientController;
use App\Modules\Crm\Controllers\NoteController;
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
 * namespaced `crm.*`; roles are the platform Spatie roles (super_admin/admin/bd).
 */
Route::prefix('v1/crm')->group(function (): void {
    // Public inbound webhook (Gmail Pub/Sub push). Authenticated by the Google-signed OIDC token
    // inside the controller, NOT by Sanctum — Pub/Sub cannot present a bearer session token.
    Route::post('/webhooks/gmail', GmailWebhookController::class)->middleware('throttle:120,1');

    Route::middleware('auth:sanctum')->group(function () {
        // Generic, reusable activity feed (append-only Activity log).
        Route::get('/activities', [ActivityController::class, 'index']);

        Route::get('/leads', [LeadController::class, 'index'])->can('viewAny', Lead::class);
        Route::post('/leads/import', [LeadController::class, 'import'])->can('import', Lead::class);
        Route::post('/leads/discovered', [LeadController::class, 'ingestDiscovered'])->can('import', Lead::class);
        Route::get('/website-enrichment/queue', [WebsiteEnrichmentController::class, 'queue'])->can('import', Lead::class);
        Route::put('/leads/{lead}/website-enrichment', [WebsiteEnrichmentController::class, 'update'])->can('import', Lead::class);
        Route::get('/leads/{lead}/activities', [LeadController::class, 'activities'])->can('view', 'lead');
        Route::get('/leads/{lead}/score', [LeadController::class, 'score'])->can('view', 'lead');
        Route::post('/leads', [LeadController::class, 'store'])->can('create', Lead::class);
        Route::get('/leads/{lead}', [LeadController::class, 'show'])->can('view', 'lead');
        Route::match(['put', 'patch'], '/leads/{lead}', [LeadController::class, 'update'])->can('update', 'lead');
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->can('delete', 'lead');
        Route::patch('/leads/{lead}/assign', [LeadController::class, 'assign'])->can('assign', 'lead');

        // Outreach: send an email to a lead.
        Route::post('/leads/{lead}/emails', [EmailController::class, 'store'])
            ->middleware('permission:crm.follow-ups.create')
            ->can('view', 'lead');

        // Schedule a follow-up for a lead.
        Route::post('/leads/{lead}/follow-ups', [LeadFollowUpController::class, 'store'])
            ->middleware('permission:crm.follow-ups.create')
            ->can('view', 'lead');

        // Outreach drafts.
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

        // Internal notes on a lead.
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

        // Planner — the single scheduling platform (Calendar + Board).
        Route::get('/planner-events', [PlannerEventController::class, 'index'])->can('viewAny', PlannerEvent::class);
        Route::post('/planner-events', [PlannerEventController::class, 'store'])->can('create', PlannerEvent::class);
        Route::get('/planner-events/{plannerEvent}', [PlannerEventController::class, 'show'])->can('view', 'plannerEvent');
        Route::match(['put', 'patch'], '/planner-events/{plannerEvent}', [PlannerEventController::class, 'update'])
            ->can('update', 'plannerEvent');
        Route::delete('/planner-events/{plannerEvent}', [PlannerEventController::class, 'destroy'])->can('delete', 'plannerEvent');

        // Team Accounts (CRM user management). Policy-guarded (crm.users.* permissions).
        Route::get('/users', [UserController::class, 'index'])->can('viewAny', User::class);
        Route::post('/users', [UserController::class, 'store'])->can('create', User::class);
        Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->can('update', 'user');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->can('delete', 'user');

        // Nearby Clients — discovered-business prospect pool (BD-facing).
        Route::get('/nearby-clients', [NearbyClientController::class, 'index']);

        // Nearby Clients — Service-Area Registry. Management roles only.
        Route::middleware('role:super_admin|crm_admin')->group(function () {
            Route::get('/service-areas', [ServiceAreaController::class, 'index']);
            Route::post('/service-areas', [ServiceAreaController::class, 'store']);
            Route::delete('/service-areas/{serviceArea}', [ServiceAreaController::class, 'destroy']);
        });

        Route::middleware('permission:crm.dashboard.view')->prefix('dashboard')->group(function () {
            Route::get('/kpis', [DashboardController::class, 'kpis']);
            Route::get('/copilot-summary', [DashboardController::class, 'copilotSummary']);
            Route::get('/priority-buckets', [DashboardController::class, 'priorityBuckets']);
            Route::get('/recent-leads', [DashboardController::class, 'recentLeads']);
            Route::get('/lead-acquisition', [DashboardController::class, 'leadAcquisition']);
            Route::get('/performance-summary', [DashboardController::class, 'performanceSummary']);
            Route::get('/kpi-metrics', [DashboardController::class, 'kpiMetrics']);

            Route::middleware('role:super_admin|crm_admin')
                ->get('/bd-performance', [DashboardController::class, 'bdPerformance']);
            Route::middleware('role:super_admin|crm_admin')
                ->get('/bd-ranking', [DashboardController::class, 'bdRanking']);
        });
    });
});
