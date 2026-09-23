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
    /*
    |--------------------------------------------------------------------------
    | SaaS Connect (Phase 3F.3A) — Facebook Login for Business
    |--------------------------------------------------------------------------
    |
    | NON-SECRET KEYS ONLY. The App Secret is NOT duplicated here: the webhook
    | already reads it from `meta.app_secret` above, and one secret with two
    | config homes is one that eventually gets rotated in only one of them.
    |
    | None of these have production values. `app_id` and `redirect_uri` are
    | empty by default, and MetaConnectFlow refuses to issue an authorization
    | URL while either is blank rather than sending somebody to Meta on a link
    | whose callback could only fail.
    |
    | Setting them does NOT enable connecting: the bound MetaOAuthClient is
    | UnconfiguredMetaOAuthClient, which makes no network call at all. Phase
    | 3F.3B replaces that binding, and that replacement is its own review.
    |
    */
    'oauth' => [
        /** Facebook App ID. Public by nature — it appears in the authorization URL. */
        'app_id' => env('META_APP_ID', ''),

        /**
         * Must match the redirect URI registered on the app EXACTLY. Meta compares
         * strings, so this is not ours to tidy — see the same note on the Google
         * callback in Routes/oauth.php.
         */
        'redirect_uri' => env('META_OAUTH_REDIRECT_URI', ''),

        /**
         * Facebook Login for Business drives its permission set from a configuration
         * created in the App dashboard rather than from a scope list in the URL. When
         * this is set we send `config_id` and nothing else.
         */
        'configuration_id' => env('META_LOGIN_CONFIGURATION_ID', ''),

        /**
         * Graph API version, verified against Meta's changelog on 2026-09-23: v26.0 is
         * current (released 2026-07-29). Pinned rather than floating — an unversioned
         * Graph call silently follows Meta's default and changes behaviour under us.
         */
        'graph_version' => env('META_GRAPH_VERSION', 'v26.0'),

        'authorize_endpoint' => env('META_OAUTH_AUTHORIZE_ENDPOINT', 'https://www.facebook.com/v26.0/dialog/oauth'),

        'graph_host' => env('META_GRAPH_HOST', 'https://graph.facebook.com'),

        /*
        | Bounds on talking to Meta. A connect flow happens with a person waiting on a
        | browser redirect, so a slow provider must become an error they can act on
        | rather than a request that hangs until the gateway gives up.
        */
        'http' => [
            'connect_timeout' => (int) env('META_HTTP_CONNECT_TIMEOUT', 5),
            'timeout' => (int) env('META_HTTP_TIMEOUT', 10),

            /** Pages a discovery walk will follow before it stops. */
            'max_pages' => (int) env('META_DISCOVERY_MAX_PAGES', 10),

            /** Assets per page requested from Meta. */
            'page_size' => (int) env('META_DISCOVERY_PAGE_SIZE', 50),
        ],

        /**
         * Fallback only, for a development app with no Login configuration. These are
         * the permissions the CRM would need and each one is subject to App Review:
         * reading and sending Page messages, the Instagram equivalent, and the two
         * management scopes that let a person list the assets they are choosing from.
         */
        'scopes' => [
            'pages_show_list',
            'pages_messaging',
            'pages_manage_metadata',
            'instagram_basic',
            'instagram_manage_messages',
            'business_management',
        ],
    ],

    'resource_types' => [
        'page' => 'page',
        'instagram' => 'instagram_business_account',
    ],
];
