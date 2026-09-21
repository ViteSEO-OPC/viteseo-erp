<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound Conversation Boundary (Phase 3B)
    |--------------------------------------------------------------------------
    |
    | The trusted ingest endpoint accepts an ALREADY NORMALIZED conversation
    | turn, so everything a caller may say is enumerated here rather than left
    | open. Adding a provider or a channel is a config change reviewed like any
    | other, not a surprise value appearing in the database.
    |
    | NO SECRETS LIVE HERE. Tokens belong to the service account that holds them
    | (MachineTokens) and to the integration's own credential store.
    |
    */

    /** Vendors allowed to ingest. A value not on this list is rejected at validation. */
    'providers' => [
        'meta',
        'n8n',
        'web',
        'twilio',
    ],

    /** Mediums within those vendors. Identity is scoped by provider AND channel. */
    'channels' => [
        'messenger',
        'instagram_dm',
        'whatsapp',
        'web_chat',
        'lead_ads',
    ],

    /** What a turn can be. Mirrors the documented `crm_messages.message_type` vocabulary. */
    'message_types' => [
        'text',
        'image',
        'video',
        'audio',
        'file',
        'template',
        'postback',
        'story_reply',
        'lead_form',
        'system',
    ],

    /*
    | Bounds on one request. The real ceiling is PHP's post_max_size (8M), but a
    | request should be refused by an intelligible validation error long before
    | it meets the interpreter's limit.
    */
    'limits' => [
        // Characters of human-readable body. `crm_messages.body` is TEXT (65 535 bytes); this
        // leaves room for multi-byte characters without ever truncating silently.
        'text' => 20000,

        // Raw provider payload, measured ENCODED — the shape actually stored and transferred.
        'raw_payload_bytes' => 65536,

        // Nesting depth of that payload. A deeply nested structure is a parser and memory
        // hazard long before it is useful evidence.
        'raw_payload_depth' => 8,

        // A provider timestamp older than this is a data error, not history.
        'occurred_at_floor' => '2020-01-01',

        // Clock skew allowance for "the future".
        'occurred_at_future_grace_minutes' => 10,
    ],

    /*
    | PHASE 3C — provider adapter classes, in resolution order-independent form.
    |
    | Every class listed here must implement InboundProviderAdapter, and exactly one of them may
    | claim any given provider/channel pair (InboundAdapterRegistry refuses both zero and two).
    | This list is the ONLY source of adapter classes: nothing is discovered by scanning, and no
    | name that arrived in a request is ever turned into a class.
    |
    | It is EMPTY in production on purpose. Phase 3C builds the framework; the first real adapter
    | arrives with Phase 3D, once Meta developer access exists. The fixture adapter used by the
    | test suite lives under tests/ and is registered by setting this key inside a test — it must
    | never be listed here, and a test asserts that it is not.
    */
    'adapters' => [
        //
    ],

    /*
    | Integration identities are ONE PROVIDER PER ORG.
    |
    | `org_user` carries a unique index on user_id, so a user belongs to exactly one org — one
    | shared "meta-inbound" account could therefore never serve two tenants. The address is
    | derived from the provider and the org's internal id: deterministic, unique, and carrying
    | nothing about the customer beyond a number that is already ours.
    */
    'service_account' => [
        'email_pattern' => '%s-inbound-org%d@crm.local',
        'name_pattern' => '%s inbound integration (org %d)',
        'token_name_pattern' => '%s-inbound',

        /** The whole grant. Anything beyond this makes the account something other than an integration. */
        'permissions' => [
            'crm.conversations.ingest',
        ],

        /** Matches MachineTokens::DEFAULT_DAYS; stated here so provisioning reads in one place. */
        'token_days' => 90,
    ],

];
