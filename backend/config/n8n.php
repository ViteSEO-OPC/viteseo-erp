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

    // CRM → n8n webhook endpoint + HMAC signing secret (header: X-CRM-Signature: sha256=<hex>).
    'webhook_url' => env('N8N_WEBHOOK_URL'),
    'secret' => env('N8N_WEBHOOK_SECRET'),
    'timeout' => (int) env('N8N_WEBHOOK_TIMEOUT', 10),

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
