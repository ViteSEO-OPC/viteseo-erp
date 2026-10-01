<?php

/**
 * AI reply drafts — Phase 4A-lite.
 *
 * A model DRAFTS a reply for a human agent to read, edit and send through the ordinary reply
 * endpoint. It never sends, never holds a Meta credential, never chooses a sending resource and
 * never owns a retry: the draft endpoint writes nothing and calls nothing but the model.
 *
 * DELIBERATELY SEPARATE FROM config/openai.php. `openai.enabled` gates the website-identity
 * adjudicator; sharing that switch would mean turning drafts on silently turned adjudication on too
 * (and vice versa). Each capability gets its own flag, its own key and its own budget.
 *
 * OFF by default. An environment that never sets these variables behaves exactly as before, and an
 * empty key is treated like a disabled flag — no HTTP is attempted in either case.
 */
return [

    // Master switch. Turning this on is a deliberate operational act.
    'enabled' => (bool) env('AI_DRAFTS_ENABLED', false),

    // Server-side only; never serialized to the browser.
    'api_key' => env('OPENROUTER_API_KEY'),

    'base_url' => env('AI_DRAFTS_BASE_URL', 'https://openrouter.ai/api/v1'),

    /*
    | Pinned to a dated, paid snapshot rather than a floating alias or a ':free' route. Free routes
    | may log or train on prompts and are heavily rate limited; a floating alias would let the drafts
    | agents review change underneath them without a deploy.
    */
    'model' => env('AI_DRAFTS_MODEL', 'openai/gpt-4o-mini-2024-07-18'),

    /*
    | One attempt, a tight timeout, no retries. A draft is a convenience: a slow model must cost the
    | agent a few seconds and an error message, never a hung composer or a doubled bill.
    */
    'timeout' => (int) env('AI_DRAFTS_TIMEOUT', 15),

    // Caps a runaway completion (the prompt asks for <= 1500 characters).
    'max_output_tokens' => 500,

    // How much of the thread the model sees: the newest N turns, each cut to this many characters.
    'context_messages' => 10,
    'max_chars_per_message' => 1000,

    // The draft is cut to this length server-side whatever the model returns.
    'max_draft_chars' => 2000,

    // Optional OpenRouter attribution headers (`HTTP-Referer`, `X-Title`). Omitted when blank.
    'referer' => env('AI_DRAFTS_REFERER'),
    'app_title' => env('AI_DRAFTS_APP_TITLE'),
];
