<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meta inbound webhook boundary (Phase 3D.1 — fixtures only)
    |--------------------------------------------------------------------------
    | Inbound ONLY. There is no outbound messaging, no Graph API client and no
    | Page Access Token anywhere in this phase, which is why the only secrets
    | here are the two the webhook itself needs.
    |
    | WHY THESE TWO LIVE IN CONFIG AND NOT THE DATABASE. Both are per-Meta-app
    | and deployment-wide, and the App Secret is needed on EVERY request before
    | any lookup happens — putting a database round-trip in front of signature
    | verification would hand an attacker a way to make us work before we have
    | decided whether to trust them. Future PER-ACCOUNT tokens are the opposite
    | case (per tenant, rotated independently) and must be stored encrypted at
    | rest; see crm_provider_accounts, which deliberately holds no secrets.
    */

    // Master switch. Disabled by default, and disabled means REFUSE — never
    // "accept without verifying". See MetaSignatureGuard.
    'enabled' => (bool) env('META_WEBHOOK_ENABLED', false),

    // HMAC key for X-Hub-Signature-256. Empty = every POST is rejected.
    'app_secret' => env('META_APP_SECRET'),

    // Compared against hub.verify_token on the GET handshake. Empty = every
    // handshake is rejected.
    'verify_token' => env('META_VERIFY_TOKEN'),

    'limits' => [
        /*
        | Whole-delivery byte ceiling, measured on the ACTUAL raw body rather
        | than Content-Length (which the sender controls and may not match).
        | 1 MiB is far above any documented Meta delivery and far below what it
        | costs to HMAC something abusive.
        */
        'raw_body_bytes' => (int) env('META_MAX_BODY_BYTES', 1048576),

        /*
        | Decode depth for the verified body. Meta's own envelope nests about
        | six levels (entry → messaging → message → attachments → payload); 16
        | leaves generous headroom while still bounding a hostile document.
        | json_decode's depth is a HARD limit: exceeding it is an error, not a
        | truncation.
        */
        'json_depth' => (int) env('META_MAX_JSON_DEPTH', 16),

        /*
        | Fan-out ceiling: how many individual events one signed delivery may
        | contain. Each surviving event becomes its own database transaction in
        | the Phase 3A engine, so an unbounded delivery is an unbounded write
        | amplification from a single request. Meta batches modestly; 200 is
        | roughly an order of magnitude above anything observed and is checked
        | BEFORE any group is processed, so an over-limit delivery cannot leave
        | half of itself written.
        */
        'max_events' => (int) env('META_MAX_EVENTS', 200),
    ],

    /*
    | Meta resource kinds this integration recognises, mapped to the
    | `resource_type` values stored in crm_provider_accounts. Stated here so the
    | provisioning command, the resolver and the classifier cannot drift.
    */
    'resource_types' => [
        'page' => 'page',
        'instagram' => 'instagram_business_account',
    ],
];
