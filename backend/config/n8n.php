<?php

return [
    /*
    |--------------------------------------------------------------------------
    | n8n Integration Boundary (RC3 Phase 5)
    |--------------------------------------------------------------------------
    | CRM = source of truth; n8n = orchestration only. Two authenticated directions:
    |  - CRM → n8n: HMAC-signed webhooks over domain events (this config's webhook_*).
    |  - n8n → CRM: a dedicated scoped Sanctum service account (service_account below)
    |    calling the EXISTING endpoints — subject to the existing policies (no bypass).
    | n8n never owns a schedule and never sends email directly (all outbound stays on
    | OutreachEmailService → EmailProvider). See KB: n8n-Integration-Boundary.
    */

    // Master switch — disabled by default; emitting is a no-op until turned on with a URL + secret.
    'enabled' => (bool) env('N8N_ENABLED', false),

    // CRM → n8n webhook BASE url + HMAC signing secret (header: X-CRM-Signature: sha256=<hex>).
    // The base is the n8n webhook ingress (e.g. https://n8n.example/webhook); the per-topic path from
    // `routes` below is appended to it. A topic with no mapped path is never emitted.
    'webhook_url' => env('N8N_WEBHOOK_URL'),
    'secret' => env('N8N_WEBHOOK_SECRET'),
    'timeout' => (int) env('N8N_WEBHOOK_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Topic → n8n webhook path (delivery routing, NOT business logic)
    |--------------------------------------------------------------------------
    | Each workflow owns a distinct n8n webhook path. Posting every topic to one URL means only the
    | single workflow sitting on that path ever fires and the rest are dead. Mapping topic → path makes
    | every workflow reachable from one base URL without an extra hop.
    |
    | This is transport addressing only: which HTTP endpoint an already-decided event is delivered to.
    | No decision, schedule, or business state is expressed here — the CRM still decides everything and
    | n8n still only orchestrates. Each value must match the `path` of that workflow's Webhook node.
    |
    | A topic absent from this map is intentionally NOT delivered (no workflow consumes it) — e.g.
    | `email.sent`, which is emitted for future consumers but has no workflow today.
    */
    'routes' => [
        'proposal.sent' => env('N8N_PATH_PROPOSAL_SENT', 'crm-proposal-sent'),
        'lead.qualified' => env('N8N_PATH_LEAD_QUALIFIED', 'crm-lead-qualified'),
        'reminder.due' => env('N8N_PATH_REMINDER_DUE', 'crm-reminder-due'),
        'follow-up.completed' => env('N8N_PATH_FOLLOW_UP_COMPLETED', 'crm-follow-up-completed'),
        'deal.won' => env('N8N_PATH_DEAL_WON', 'crm-deal-won'),
    ],

    // n8n → CRM scoped service account (provisioned by `php artisan n8n:token`).
    'service_account' => [
        'email' => env('N8N_SERVICE_EMAIL', 'n8n-service@crm.local'),
        'name' => env('N8N_SERVICE_NAME', 'n8n Service Account'),
        // Minimal ability set — reuse of existing endpoints only; never leads.delete / admin abilities.
        // Names use the platform's `crm.` permission namespace.
        'permissions' => [
            'crm.follow-ups.view',
            'crm.follow-ups.create',
            'crm.follow-ups.edit',
            'crm.calendar.view',
            'crm.calendar.create',
            // RC3 Outreach Automation Pack — the SOLE new ability. Authorizes the dedicated automation
            // send endpoint (Option A) to trigger OutreachEmailService for a lead WITHOUT the human
            // per-lead ownership check. Strictly narrower than leads.view; held only by this account.
            // The frozen user-facing POST /leads/{lead}/emails is unchanged and keeps can:view,lead.
            'crm.outreach.automation.send',
        ],
    ],
];
