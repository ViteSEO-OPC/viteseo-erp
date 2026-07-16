<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active outreach email provider
    |--------------------------------------------------------------------------
    | SMTP is the Phase 1 implementation. Gmail OAuth, Microsoft Graph, Resend,
    | SendGrid, Amazon SES, etc. are added by registering a new binding in
    | OutreachServiceProvider — the Outreach business layer never changes.
    */
    'provider' => env('OUTREACH_MAIL_PROVIDER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Inbound mailbox sync provider (Sprint 2)
    |--------------------------------------------------------------------------
    | Which MailboxSyncProvider receives replies. Gmail (push) is the first provider;
    | Microsoft Graph and IMAP can be added as bindings with no business-layer change.
    | `mailbox` is the address whose inbox is synchronized.
    */
    'inbound_provider' => env('OUTREACH_INBOUND_PROVIDER', 'gmail'),
    'mailbox' => env('OUTREACH_MAILBOX', env('MAIL_FROM_ADDRESS')),

    /*
    |--------------------------------------------------------------------------
    | Gmail push provider
    |--------------------------------------------------------------------------
    | Authentication is KEYLESS by default (org policy iam.disableServiceAccountKeyCreation —
    | no downloadable service-account JSON key). Domain-wide delegation is performed by signing
    | the delegation assertion via the IAM Credentials signJwt API using the runtime identity
    | (Application Default Credentials — Workload Identity Federation off-GCP, or an attached
    | service account on-GCP) which must hold roles/iam.serviceAccountTokenCreator on the sync SA.
    |
    | `auth`            keyless (preferred, no key) | key (legacy JSON-key fallback only)
    | `service_account` the SA impersonated for delegation (keyless mode); e.g. crm-inbound-sync@…
    | `credentials`     path to a downloadable SA JSON key — used ONLY when auth=key (fallback)
    |
    | gmail.readonly is sufficient for sync; switch to gmail.modify only if we later mark read.
    */
    'gmail' => [
        'auth' => env('OUTREACH_GMAIL_AUTH', 'keyless'),
        'service_account' => env('GMAIL_SYNC_SERVICE_ACCOUNT'),
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
        'topic' => env('GMAIL_PUBSUB_TOPIC'),
        'scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound webhook verification (Pub/Sub OIDC push token)
    |--------------------------------------------------------------------------
    | Google signs each push with an OIDC JWT. We verify its audience + the
    | authorized service account. `verify` MUST be true in production; it exists
    | only so the endpoint can be exercised locally before Cloud infra is wired.
    */
    'webhook' => [
        'verify' => (bool) env('GMAIL_WEBHOOK_VERIFY', true),
        'audience' => env('GMAIL_WEBHOOK_AUDIENCE'),
        'service_account' => env('GMAIL_PUBSUB_SERVICE_ACCOUNT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default sender identity for outbound outreach email
    |--------------------------------------------------------------------------
    | Falls back to the framework MAIL_FROM_* so a single SMTP config works with
    | Google Workspace, Microsoft 365, Zoho, Hostinger, cPanel, and others.
    */
    'from' => [
        'address' => env('OUTREACH_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@crm-enterprise.com')),
        'name' => env('OUTREACH_FROM_NAME', env('MAIL_FROM_NAME', 'CRM Enterprise')),
    ],
];
