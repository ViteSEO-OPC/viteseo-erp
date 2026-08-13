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

    // The address whose inbox is synchronized. It MUST be the same mailbox the CRM sends
    // FROM, or prospects reply to an inbox nobody polls and threading stops silently — so
    // the fallback chain deliberately mirrors mail.module_senders.crm rather than jumping
    // straight to MAIL_FROM_ADDRESS. Without that, setting CRM_MAIL_FROM_ADDRESS alone
    // would move the sender and leave the polled mailbox behind.
    //
    // OUTREACH_MAILBOX still wins when set, which is the only supported way to split them
    // (send as one address, poll another) — deliberate, never accidental.
    'mailbox' => env('OUTREACH_MAILBOX', env('CRM_MAIL_FROM_ADDRESS', env('OUTREACH_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')))),

    // The provider must (re)establish a cursor in two situations. They share mechanics — ingest a
    // bounded window of INBOX, then baseline the cursor to "now" — but they are DIFFERENT decisions
    // and therefore have separate settings. Idempotent ingest dedupes the overlap either one
    // re-offers; both log the window they used, so the boundary is never silent.
    'inbound' => [
        // ── Onboarding a mailbox (no cursor yet) ──────────────────────────────────────────────
        // How much existing mail to import the FIRST time a mailbox is polled.
        //   0  → baseline only: take a cursor, import nothing, sync everything from now on (default)
        //   7  → also import the last 7 days
        //   30 → also import the last 30 days
        // Logged as outreach.inbound.cursor_bootstrapped.
        //
        // Defaults to 0 because the alternative is a surprise: pointing the CRM at a busy shared
        // inbox would otherwise dump weeks of unrelated mail into the timeline on the first
        // scheduler tick. Opt IN to history; don't inherit it.
        'initial_sync_days' => (int) env('OUTREACH_INITIAL_SYNC_DAYS', 0),

        // ── Recovering a pruned cursor (Gmail 404 on the delta sync) ──────────────────────────
        // How far back to re-sync when Gmail has expired the stored historyId. Logged as
        // outreach.inbound.cursor_expired (ERROR — it means a gap we cannot measure).
        //
        // Deliberately NOT the same knob as initial_sync_days: setting that to 0 means "start this
        // mailbox from today", not "throw mail away whenever a cursor lapses". Mail older than this
        // window is not recovered.
        'resync_window_days' => (int) env('OUTREACH_RESYNC_WINDOW_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gmail inbound provider
    |--------------------------------------------------------------------------
    | Authentication is KEYLESS by default (org policy iam.disableServiceAccountKeyCreation —
    | no downloadable service-account JSON key). Domain-wide delegation is performed by signing
    | the delegation assertion via the IAM Credentials signJwt API using the runtime identity
    | (Application Default Credentials — Workload Identity Federation off-GCP, or an attached
    | service account on-GCP) which must hold roles/iam.serviceAccountTokenCreator on the sync SA.
    |
    | `auth`            keyless (preferred, no key) | key (legacy JSON-key fallback only)
    |                   | oauth (per-mailbox user consent — see below)
    | `service_account` the SA impersonated for delegation (keyless mode); e.g. crm-inbound-sync@…
    | `credentials`     path to a downloadable SA JSON key — used ONLY when auth=key (fallback)
    |
    | gmail.readonly is sufficient for sync; switch to gmail.modify only if we later mark read.
    | The scopes below are shared by ALL THREE auth modes — changing them forces every OAuth-
    | connected mailbox back through consent, so treat the list as a migration, not a setting.
    |
    |--------------------------------------------------------------------------
    | auth=oauth — per-mailbox user consent
    |--------------------------------------------------------------------------
    | Delegation proves "this Workspace's admin authorised us for every mailbox in the domain",
    | which is impossible for a mailbox on someone else's Workspace or on consumer Gmail. Those
    | can only be reached by the user consenting, leaving us holding a per-mailbox refresh token
    | (crm_mailbox_oauth_tokens, encrypted at rest). Same GoogleCredentialSource seam, different
    | proof — GmailMailboxSync is unchanged either way.
    |
    | `client_id` / `client_secret`  the OAuth client the grants are issued to; required only when
    |                                auth=oauth, and read at refresh time, never at boot
    | `redirect_uri`                 where Google returns the user after consent. Not used by the
    |                                credential itself — it belongs to the consent flow, and is
    |                                surfaced here so both halves read the ONE value. Must match a
    |                                redirect URI registered on the OAuth client EXACTLY.
    |
    | NOT ACTIVE. `auth` still defaults to keyless; setting OUTREACH_GMAIL_AUTH=oauth before a
    | consent flow exists would leave every mailbox with no stored grant and therefore no way to
    | authenticate. The capability is wired; switching it on is a later, deliberate step.
    */
    'gmail' => [
        'auth' => env('OUTREACH_GMAIL_AUTH', 'keyless'),
        'service_account' => env('GMAIL_SYNC_SERVICE_ACCOUNT'),
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
    ],

    /*
    |--------------------------------------------------------------------------
    | REMOVED: Pub/Sub push configuration
    |--------------------------------------------------------------------------
    | `gmail.topic` (GMAIL_PUBSUB_TOPIC) and the whole `webhook` block
    | (GMAIL_WEBHOOK_VERIFY / GMAIL_WEBHOOK_AUDIENCE / GMAIL_PUBSUB_SERVICE_ACCOUNT)
    | existed for the Gmail push → Pub/Sub → public webhook trigger. Inbound is now a
    | scheduled poll (`outreach:sync`, every five minutes), so none of that Google Cloud
    | surface is required: no topic, no push subscription, no OIDC verification, and no
    | public HTTPS endpoint. Those env vars are inert and can be deleted from deployments.
    |
    | Still required: the Gmail API itself + domain-wide delegation (gmail.readonly) for the
    | service account above. Polling changed the trigger, not the Gmail access model.
    */

    /*
    |--------------------------------------------------------------------------
    | CRM SPA origin — where OAuth returns the browser
    |--------------------------------------------------------------------------
    | ORIGIN ONLY (scheme + host + port), never a full URL with a path: the path is a
    | frontend-routing fact and lives in code (GmailOAuthController), so the two cannot
    | drift. Used for exactly one thing — bouncing the browser back to Settings →
    | Integrations after Google consent, with a non-secret ?gmail=… status.
    |
    | This is a FIXED configuration value and is never read from the request. An OAuth
    | callback that accepted a redirect target from its own query string would be an open
    | redirect wearing a Google-shaped badge.
    |
    | Defaults to the local Next dev server (the frontend is not containerised — see
    | docker-compose.yml). A deployment sets FRONTEND_URL; if it is blank the callback
    | falls back to returning minimal JSON rather than guessing a host.
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Default sender identity for outbound outreach email — DEPRECATED
    |--------------------------------------------------------------------------
    | SUPERSEDED by config('mail.module_senders.crm'), which is now the single source
    | of truth for the CRM's outbound identity and is what OutreachEmailService reads.
    | The platform sends as several businesses over one shared relay, so sender identity
    | belongs in the shared registry beside the transport rather than in a module config.
    |
    | OUTREACH_FROM_ADDRESS / OUTREACH_FROM_NAME are UNCHANGED and still authoritative:
    | mail.module_senders.crm falls back to them ahead of MAIL_FROM_*, so existing
    | deployments keep their exact sender with no env change. Set CRM_MAIL_FROM_ADDRESS
    | to override them going forward.
    |
    | Retained (not deleted) so any config:cache'd deployment or out-of-tree reader does
    | not break on a missing key. Nothing in app/ reads it any more — verify with
    | `grep -rn "outreach.from" app/` before removing it in a later slice.
    */
    'from' => [
        'address' => env('OUTREACH_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@crm-enterprise.com')),
        'name' => env('OUTREACH_FROM_NAME', env('MAIL_FROM_NAME', 'CRM Enterprise')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal notification channels (INTERNAL staff mail — never customer mail)
    |--------------------------------------------------------------------------
    | Additional delivery channels for INTERNAL CRM notifications, on top of the
    | always-on in-app database notification. These carry staff alerts to a CRM
    | user's own company mailbox (users.email, e.g. bruce@viteseo.ph) — they are
    | NOT part of customer communication, which continues to flow exclusively
    | through the shared outreach mailbox (see `from` + `mailbox` above).
    |
    | `mail`  off by default: no internal mail is sent until it is switched on, so
    |         enabling SMTP alone can never start emailing staff unexpectedly.
    |
    | Future channels (slack/teams/sms/whatsapp) are added as additional keys here
    | and a channel branch in the delivery dispatcher — the inbound business layer
    | (InboundEmailService / InboundReplyProcessor) never changes.
    */
    'internal_notifications' => [
        'mail' => (bool) env('CRM_INTERNAL_REPLY_MAIL', false),
    ],
];
